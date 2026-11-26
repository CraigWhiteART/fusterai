<?php

namespace App\Domains\Commerce\Actions\Http\Controllers;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Commerce\Actions\ActionApprovals;
use App\Domains\Commerce\Actions\ActionAssessor;
use App\Domains\Commerce\Actions\ActionGate;
use App\Domains\Commerce\Actions\ActionNotifier;
use App\Domains\Commerce\Actions\Gateway\ShopifyGatewayFactory;
use App\Domains\Commerce\Actions\Http\ExecutionPresenter;
use App\Domains\Commerce\Actions\Models\ActionExecution;
use App\Domains\Commerce\Actions\Models\ActionSettings;
use App\Domains\Conversation\Models\Conversation;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Approvals queue, execution detail, approve / reject / refresh, the audit
 * log (+ CSV) and per-conversation executions. Authorization:
 *
 *  - queue / show / conversation list: users who can view the conversation
 *    (admins see all); the queue additionally needs at least the lower of
 *    the two approver roles.
 *  - approve / refresh: ActionApprovals::canApprove (role from
 *    agent_action_settings + conversation access).
 *  - reject: same as approve.
 *  - audit + CSV: manage-settings.
 */
class ActionExecutionController extends Controller
{
    public function __construct(
        private readonly ActionApprovals $approvals,
        private readonly ExecutionPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $settings = ActionSettings::forWorkspace((int) $user->workspace_id);
        $lowest = User::ROLE_HIERARCHY[$settings->approver_role_money] <= User::ROLE_HIERARCHY[$settings->approver_role_other] ? $settings->approver_role_money : $settings->approver_role_other;
        abort_unless($user->hasMinRole($lowest), 403);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in([...ActionExecution::STATUSES, 'all', 'open'])],
            'action_key' => ['nullable', 'string', 'max:40'],
            'conversation_id' => ['nullable', 'integer'],
            'route' => ['nullable', Rule::in([ActionExecution::ROUTE_APPROVAL, ActionExecution::ROUTE_UNATTENDED])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $filters['status'] ?? ActionExecution::PENDING_APPROVAL;
        $query = $this->visible($user)
            ->with(['conversation:id,subject,mailbox_id,workspace_id', 'approver:id,name', 'rejecter:id,name'])
            ->when($status === 'open', fn ($q) => $q->whereIn('status', [ActionExecution::PENDING_APPROVAL, ActionExecution::APPROVED, ActionExecution::EXECUTING, ActionExecution::UNKNOWN]))
            ->when(! in_array($status, ['all', 'open'], true), fn ($q) => $q->where('status', $status))
            ->when($filters['action_key'] ?? null, fn ($q, $v) => $q->where('action_key', $v))
            ->when($filters['conversation_id'] ?? null, fn ($q, $v) => $q->where('conversation_id', $v))
            ->when($filters['route'] ?? null, fn ($q, $v) => $q->where('route', $v))
            ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END, expires_at ASC, id DESC');

        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (ActionExecution $e) => $this->presenter->summary($e, $user))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
            'counts' => [
                'pending_approval' => $this->visible($user)->where('status', ActionExecution::PENDING_APPROVAL)->count(),
                'unknown' => $this->visible($user)->where('status', ActionExecution::UNKNOWN)->count(),
            ],
        ]);
    }

    public function show(Request $request, ActionExecution $execution): JsonResponse
    {
        $this->authorizeView($request->user(), $execution);

        return response()->json(['execution' => $this->presenter->detail($execution->load(['conversation', 'approver', 'rejecter']), $request->user())]);
    }

    public function approve(Request $request, ActionExecution $execution): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeView($user, $execution);
        abort_unless($this->approvals->canApprove($user, $execution), 403, 'Your role cannot approve this action.');

        $data = $request->validate(['params' => ['nullable', 'array']]);
        $result = $this->approvals->approve($execution, $user, $data['params'] ?? null);

        return response()->json([
            'message' => $result['message'],
            ...(isset($result['errors']) ? ['errors' => $result['errors']] : []),
            'execution' => $this->presenter->detail($execution->fresh(['conversation', 'approver', 'rejecter']) ?? $execution, $user),
        ], $result['status']);
    }

    public function reject(Request $request, ActionExecution $execution): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeView($user, $execution);
        abort_unless($this->approvals->canApprove($user, $execution), 403, 'Your role cannot decide this action.');

        $data = $request->validate(['reason' => ['required', 'string', 'min:2', 'max:2000']]);
        $result = $this->approvals->reject($execution, $user, $data['reason']);

        return response()->json([
            'message' => $result['message'],
            'execution' => $this->presenter->detail($execution->fresh(['conversation', 'approver', 'rejecter']) ?? $execution, $user),
        ], $result['status']);
    }

    /** Re-read the order (read-only queries) and re-check preconditions for the card. */
    public function refresh(Request $request, ActionExecution $execution, ActionAssessor $assessor, ShopifyGatewayFactory $gateways, ActionNotifier $notifier): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeView($user, $execution);
        abort_unless($this->approvals->canApprove($user, $execution), 403);
        if ($execution->status !== ActionExecution::PENDING_APPROVAL) {
            return response()->json(['message' => 'Only pending actions can be refreshed.'], 409);
        }

        $assessment = $assessor->assess($execution, $gateways->forExecution($execution));
        $execution->forceFill([
            'precondition_snapshot' => $assessment->preconditionSnapshot(),
            'policy_snapshot' => $assessment->policy->toArray(),
            'warnings' => $assessment->warnings,
            'description' => $assessment->description,
            'approval_reasons' => $assessment->approvalReasons,
        ])->save();
        $notifier->changed($execution, 'refreshed', $user);

        return response()->json(['execution' => $this->presenter->detail($execution->fresh(['conversation', 'approver', 'rejecter']) ?? $execution, $user)]);
    }

    public function forConversation(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);
        $user = $request->user();

        $executions = ActionExecution::where('conversation_id', $conversation->id)->with(['approver:id,name', 'rejecter:id,name'])->latest('id')->limit(50)->get();
        $run = AgentRun::where('conversation_id', $conversation->id)->where('purpose', AgentRun::PURPOSE_LIVE)->latest('id')->first();

        return response()->json([
            'executions' => $executions->map(fn (ActionExecution $e) => $this->presenter->summary($e, $user))->values(),
            'latest_run' => $run === null ? null : ['id' => $run->id, 'reply' => ActionGate::state($run), 'blocks_sending' => ActionGate::blocksSending($run)],
        ]);
    }

    public function runGate(Request $request, AgentRun $run): JsonResponse
    {
        abort_unless($run->conversation !== null && (int) $run->workspace_id === (int) $request->user()->workspace_id, 404);
        $this->authorize('view', $run->conversation);

        return response()->json(['run_id' => $run->id, 'reply' => ActionGate::state($run), 'blocks_sending' => ActionGate::blocksSending($run)]);
    }

    public function audit(Request $request): JsonResponse|StreamedResponse
    {
        $this->authorize('manage-settings');
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(ActionExecution::STATUSES)],
            'action_key' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'format' => ['nullable', Rule::in(['json', 'csv'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = ActionExecution::where('workspace_id', $request->user()->workspace_id)
            ->with(['approver:id,name', 'rejecter:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['action_key'] ?? null, fn ($q, $v) => $q->where('action_key', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            // A plain date covers that whole day; a date-time is taken as given.
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) === 1 ? Carbon::parse($v)->endOfDay() : Carbon::parse($v)))
            ->orderByDesc('id');

        if (($filters['format'] ?? 'json') === 'csv') {
            return response()->streamDownload(function () use ($query): void {
                $out = fopen('php://output', 'w');
                if ($out === false) {
                    return;
                }
                fputcsv($out, ['id', 'created_at', 'action', 'status', 'route', 'order', 'amount', 'currency', 'customer_email', 'conversation_id', 'approved_by', 'approved_at', 'rejected_by', 'rejection_reason', 'executed_at', 'completed_at', 'error', 'description']);
                $query->chunk(500, function ($rows) use ($out): void {
                    foreach ($rows as $e) {
                        fputcsv($out, array_map([self::class, 'csvSafe'], [
                            $e->id, $e->created_at?->toIso8601String(), $e->action_key, $e->status, $e->route, $e->order_number,
                            $e->amount, $e->currency, $e->customer_email, $e->conversation_id, $e->approver?->name,
                            $e->approved_at?->toIso8601String(), $e->rejecter?->name, $e->rejection_reason,
                            $e->executed_at?->toIso8601String(), $e->completed_at?->toIso8601String(), $e->error, $e->description,
                        ]));
                    }
                });
                fclose($out);
            }, 'agent-actions-audit-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
        }

        $page = $query->paginate((int) ($filters['per_page'] ?? 50));

        return response()->json([
            'data' => collect($page->items())->map(fn (ActionExecution $e) => $this->presenter->summary($e, $request->user()))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
        ]);
    }

    /** Neutralise spreadsheet formula injection in customer-controlled text. */
    public static function csvSafe(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value) ? "'".$value : $value;
    }

    /** @return Builder<ActionExecution> */
    private function visible(User $user): Builder
    {
        $query = ActionExecution::query()->where('workspace_id', $user->workspace_id);
        if (! $user->isAdmin()) {
            $mailboxIds = $user->mailboxes()->pluck('mailboxes.id');
            if ($mailboxIds->isNotEmpty()) {
                $query->whereHas('conversation', fn ($q) => $q->whereIn('mailbox_id', $mailboxIds));
            }
        }

        return $query;
    }

    private function authorizeView(User $user, ActionExecution $execution): void
    {
        abort_unless((int) $execution->workspace_id === (int) $user->workspace_id, 404);
        if ($execution->conversation !== null) {
            $this->authorize('view', $execution->conversation);
        } else {
            abort_unless($user->isAdmin(), 403);
        }
    }
}
