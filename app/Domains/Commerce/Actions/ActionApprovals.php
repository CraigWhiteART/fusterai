<?php

namespace App\Domains\Commerce\Actions;

use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\FeedbackEvent;
use App\Domains\Commerce\Actions\Jobs\ExecuteActionJob;
use App\Domains\Commerce\Actions\Models\ActionExecution as E;
use App\Domains\Commerce\Actions\Models\ActionSettings;
use App\Domains\Commerce\Actions\Support\Money;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Human decisions on pending executions. Approve and reject are
 * conditional updates (pending_approval -> approved / rejected), so a
 * double click or two people at once produce exactly one outcome.
 */
class ActionApprovals
{
    public function __construct(
        private readonly ActionRegistry $registry,
        private readonly ActionNotifier $notifier,
    ) {}

    /** Role needed to approve this execution (agent_action_settings). */
    public function requiredRole(E $execution): string
    {
        $settings = ActionSettings::forWorkspace($execution->workspace_id);

        return $this->registry->get($execution->action_key)->isMoney() ? $settings->approver_role_money : $settings->approver_role_other;
    }

    public function canApprove(User $user, E $execution): bool
    {
        if ((int) $user->workspace_id !== (int) $execution->workspace_id) {
            return false;
        }
        if ($execution->conversation !== null && ! Gate::forUser($user)->allows('view', $execution->conversation)) {
            return false;
        }

        return $user->hasMinRole($this->requiredRole($execution));
    }

    /**
     * @param  array<string, mixed>|null  $edits  replacement params (re-validated)
     * @return array{ok: bool, status: int, message: string, errors?: array<string, list<string>>}
     */
    public function approve(E $execution, User $user, ?array $edits = null): array
    {
        if (AgentSetting::forWorkspace($execution->workspace_id)->kill_switch) {
            return ['ok' => false, 'status' => 409, 'message' => 'The agent kill switch is on: nothing can be executed.'];
        }
        if ($execution->status !== E::PENDING_APPROVAL) {
            return ['ok' => false, 'status' => 409, 'message' => "This action is {$execution->status}, not waiting for approval."];
        }
        if (($why = $this->registry->unavailableReason($execution->action_key, $execution->workspace_id)) !== null) {
            return ['ok' => false, 'status' => 422, 'message' => $why];
        }

        $definition = $this->registry->get($execution->action_key);
        $settings = ActionSettings::forWorkspace($execution->workspace_id);
        $extra = [];

        if ($edits !== null) {
            // The system-generated discount code is never editable.
            if (isset($execution->params['code'])) {
                $edits['code'] = $execution->params['code'];
            }
            $validated = $definition->validate($edits, $settings);
            if ($validated['errors'] !== []) {
                return ['ok' => false, 'status' => 422, 'message' => 'The edited parameters are not valid.', 'errors' => $validated['errors']];
            }
            if ($validated['params'] != $execution->params) {
                $amount = $definition->amountFromParams($validated['params']);
                $extra = [
                    'params' => $validated['params'],
                    'param_errors' => null,
                    'params_edited' => ['before' => $execution->params, 'after' => $validated['params'], 'by' => $user->id, 'at' => now()->toIso8601String()],
                    'description' => $definition->describe($validated['params'], null, $settings),
                    'amount' => $amount !== null ? Money::decimal($amount) : $execution->amount,
                ];
            }
        } elseif ($execution->param_errors) {
            return ['ok' => false, 'status' => 422, 'message' => 'The proposed parameters are not valid: send corrected params.', 'errors' => $execution->param_errors];
        }

        if (! $execution->transition([E::PENDING_APPROVAL], E::APPROVED, [...$extra, 'approved_by' => $user->id, 'approved_at' => now()])) {
            return ['ok' => false, 'status' => 409, 'message' => 'Someone else already decided this action.'];
        }

        $this->notifier->changed($execution, 'approved', $user);
        ExecuteActionJob::dispatch($execution->id);

        return ['ok' => true, 'status' => 202, 'message' => 'Approved; executing.'];
    }

    /** @return array{ok: bool, status: int, message: string} */
    public function reject(E $execution, User $user, string $reason): array
    {
        if (! $execution->transition([E::PENDING_APPROVAL], E::REJECTED, [
            'rejected_by' => $user->id,
            'rejected_at' => now(),
            'rejection_reason' => mb_substr($reason, 0, 2000),
            'completed_at' => now(),
        ])) {
            return ['ok' => false, 'status' => 409, 'message' => "This action is {$execution->status}, not waiting for approval."];
        }

        FeedbackEvent::create([
            'workspace_id' => $execution->workspace_id,
            'run_id' => $execution->run_id,
            'conversation_id' => $execution->conversation_id,
            'user_id' => $user->id,
            'kind' => FeedbackEvent::KIND_REJECTED,
            'text' => mb_substr($reason, 0, 2000),
            'meta' => ['reason' => 'action_rejected', 'execution_id' => $execution->id, 'action_key' => $execution->action_key, 'params' => $execution->params],
        ]);

        $this->notifier->changed($execution, 'rejected', $user);
        $this->notifier->requestRewrite($execution);

        return ['ok' => true, 'status' => 200, 'message' => 'Rejected.'];
    }

    /** Pending approvals past expires_at become expired. @return int how many */
    public function expireStale(): int
    {
        $count = 0;
        E::where('status', E::PENDING_APPROVAL)->whereNotNull('expires_at')->where('expires_at', '<', now())
            ->orderBy('id')
            ->each(function (E $execution) use (&$count): void {
                if ($execution->transition([E::PENDING_APPROVAL], E::EXPIRED, ['error' => 'Nobody approved it in time.', 'completed_at' => now()])) {
                    $count++;
                    $this->notifier->changed($execution, 'expired');
                }
            });

        return $count;
    }
}
