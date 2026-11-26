<?php

namespace App\Domains\Commerce\Actions;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Pipeline\PipelineInput;
use App\Domains\Commerce\Actions\Definitions\ActionContext;
use App\Domains\Commerce\Actions\Gateway\ShopifyGateway;
use App\Domains\Commerce\Actions\Models\ActionExecution;
use App\Domains\Commerce\Actions\Models\ActionPolicy;
use App\Domains\Commerce\Actions\Models\ActionSettings;
use App\Domains\Commerce\Actions\Policy\LimitEvaluator;
use App\Domains\Commerce\Actions\Policy\PolicyResolver;
use App\Domains\Commerce\Actions\Support\Scopes;
use App\Domains\Commerce\Models\CommerceSetting;

/**
 * Reads fresh order state (read-only queries, through the gateway) and
 * evaluates scopes, preconditions, policy and limits for one execution.
 * Used when a run proposes an action, by the refresh endpoint, and by the
 * executor immediately before the mutation.
 */
class ActionAssessor
{
    public function __construct(
        private readonly ActionRegistry $registry,
        private readonly PolicyResolver $policies,
        private readonly LimitEvaluator $limits,
        private readonly OrderStateLoader $loader,
    ) {}

    public function assess(ActionExecution $execution, ShopifyGateway $gateway, ?string $approverName = null): Assessment
    {
        $definition = $this->registry->get($execution->action_key);
        $settings = ActionSettings::forWorkspace($execution->workspace_id);
        $scopes = Scopes::for(CommerceSetting::forWorkspace($execution->workspace_id));
        $ctx = new ActionContext($execution, $settings, $scopes, $approverName);
        $policy = $this->policies->resolve($execution->workspace_id, $execution->action_key, $execution->playbook_id);
        $unavailable = $this->registry->unavailableReason($execution->action_key, $execution->workspace_id);

        $state = null;
        $checks = null;
        $stateError = null;
        if ($unavailable === null && $policy->mode !== ActionPolicy::NEVER && $gateway->isLive()) {
            $state = $this->loader->load($gateway, $scopes, $execution->order_id, $execution->order_number);
            $stateError = $this->loader->last['error'];
            if ($state !== null && ! $execution->param_errors) {
                $definition->prepare($gateway, $execution->params, $state, $ctx);
                $checks = $definition->preconditions($execution->params, $state, $ctx);
            }
        } elseif (! $gateway->isLive()) {
            $stateError = 'Dry run: order state is never read for a non-live run.';
        }

        $evaluation = $this->limits->evaluate(
            $definition, $policy, $execution, $state, $checks, $settings,
            $this->senderEmails($execution), $this->runDecision($execution),
        );

        $warnings = [...($checks->warnings ?? []), ...$evaluation['warnings']];
        if ($definition->warehouseSensitive()) {
            $guard = $evaluation['details']['warehouse_guard'] ?? null;
            $why = is_array($guard) && $guard['reasons'] !== [] ? ' ('.implode('; ', $guard['reasons']).')' : '';
            array_unshift($warnings, [
                'level' => is_array($guard) && $guard['clear'] ? 'warning' : 'danger',
                'text' => 'The 4PX warehouse may already have this order'.$why.'. A change made in Shopify may not reach the warehouse: check with 4PX before approving.',
            ]);
        }
        if ($stateError !== null) {
            $warnings[] = ['level' => 'danger', 'text' => $stateError];
        }

        return new Assessment(
            state: $state,
            checks: $checks,
            policy: $policy,
            context: $ctx,
            approvalReasons: $evaluation['reasons'],
            warnings: $warnings,
            limitDetails: $evaluation['details'],
            unavailable: $unavailable,
            stateError: $stateError,
            description: $definition->describe($execution->params, $state, $settings),
        );
    }

    /** @return list<string> */
    public function senderEmails(ActionExecution $execution): array
    {
        $conversation = $execution->conversation;
        if ($conversation === null) {
            return [];
        }

        return PipelineInput::fromConversation($conversation, AgentRun::PURPOSE_PREVIEW)->senderEmails();
    }

    private function runDecision(ActionExecution $execution): ?string
    {
        return $execution->run_id !== null ? AgentRun::whereKey($execution->run_id)->value('decision') : null;
    }
}
