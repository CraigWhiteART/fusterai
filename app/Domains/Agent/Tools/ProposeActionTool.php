<?php

namespace App\Domains\Agent\Tools;

use App\Domains\Commerce\Actions\ActionRegistry;
use App\Domains\Commerce\Actions\Definitions\DiscountCode;
use App\Domains\Commerce\Actions\Models\ActionPolicy;
use App\Domains\Commerce\Actions\Models\ActionSettings;
use App\Domains\Commerce\Actions\Policy\PolicyResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Records a proposed Shopify action. The tool itself never executes
 * anything: for a live run, RunEffects hands the recorded proposals to
 * App\Domains\Commerce\Actions\ActionRecorder, which re-validates them in
 * code and queues them for approval (or, within the owner's limits, runs
 * them unattended). See docs/agent-actions.md.
 *
 * Rejected here: unknown actions, actions the playbook does not allow,
 * actions whose Shopify scope is missing, actions whose policy is `never`
 * (the default), and params that do not validate.
 */
class ProposeActionTool extends WriterTool
{
    public function name(): string
    {
        return 'propose_action';
    }

    public function description(): string
    {
        return 'Propose an order action (e.g. refund, replacement). It runs only after a human approves it, or automatically within limits the owner set; '
            .'the reply is held until it succeeds and rewritten if it is rejected. Give structured params.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(array_keys((array) config('agent.actions', [])))->required(),
            'order_number' => $schema->string()->nullable()->required(),
            'reason' => $schema->string()->description('Why, citing evidence ids')->required(),
            'details' => $schema->string()->nullable()->description('Amounts, items or address, as plain text')->required(),
            'params' => $schema->object()->nullable()->description('Structured parameters for the action. Shapes: '.$this->paramHints()),
        ];
    }

    protected function run(array $arguments): string
    {
        $action = (string) ($arguments['action'] ?? '');
        $known = array_key_exists($action, (array) config('agent.actions', []));
        $allowed = in_array($action, $this->session->playbook->allowedActions, true);
        $params = is_array($arguments['params'] ?? null) ? $arguments['params'] : [];
        $errors = [];
        $summary = null;

        // ── Phase 9: scopes, policy and params (app/Domains/Commerce/Actions) ──
        $registry = app(ActionRegistry::class);
        $workspaceId = $this->session->input->workspaceId;
        $unavailable = $known && $allowed && $registry->has($action) ? $registry->unavailableReason($action, $workspaceId) : null;
        $disabled = $known && $allowed && $unavailable === null && $registry->has($action)
            && app(PolicyResolver::class)->resolve($workspaceId, $action, $this->session->playbook->id)->mode === ActionPolicy::NEVER;

        if ($known && $allowed && $unavailable === null && ! $disabled && $registry->has($action)) {
            if ($action === 'discount_code') {
                $params['code'] = DiscountCode::generateCode(); // system-generated, never the model's
            }
            $settings = ActionSettings::forWorkspace($workspaceId);
            $validated = $registry->get($action)->validate($params, $settings);
            $params = $validated['params'];
            $errors = $validated['errors'];
            if ($errors === []) {
                $summary = $registry->get($action)->describe($params, null, $settings);
            }
        }
        // ── end Phase 9 ──

        $status = match (true) {
            ! $known => 'rejected_unknown_action',
            ! $allowed => 'rejected_not_allowed',
            $unavailable !== null => 'rejected_unavailable',
            $disabled => 'rejected_disabled',
            $errors !== [] => 'rejected_invalid_params',
            default => 'proposed',
        };

        $this->session->proposedActions[] = [
            'action' => $action,
            'order_number' => $arguments['order_number'] ?? null,
            'reason' => (string) ($arguments['reason'] ?? ''),
            'details' => $arguments['details'] ?? null,
            'params' => $params,
            'status' => $status,
            ...($errors !== [] ? ['errors' => $errors] : []),
            ...($unavailable !== null ? ['unavailable' => $unavailable] : []),
        ];

        if ($status === 'proposed') {
            // Grounds the reply's "I've arranged a refund of $20" / the discount code.
            $n = count($this->session->proposedActions);
            $this->session->ledger->add('action', "action.proposed.{$action}.{$n}", 'Action proposed in this run (runs only after approval or within limits)', (string) $summary);
        }

        return match ($status) {
            'proposed' => 'Recorded: '.$summary.' The reply is held until this succeeds and rewritten if it fails or is rejected, so you may tell the customer it is being done'
                .(isset($params['code']) ? " (the code is {$params['code']})" : '').'. Do not promise anything beyond it.',
            'rejected_invalid_params' => 'Not recorded: the params are invalid: '.json_encode($errors).'. Fix them and call propose_action again, or hand over.',
            'rejected_unavailable', 'rejected_disabled' => 'This action is not enabled for this store and was not recorded. Do not offer it or say it is done; say a teammate will look into it, or hand over.',
            default => 'This action is not allowed under this playbook and was not recorded. Do not offer it.',
        };
    }

    private function paramHints(): string
    {
        $registry = app(ActionRegistry::class);
        $hints = [];
        foreach ($this->session->playbook->allowedActions as $key) {
            if ($registry->has($key)) {
                $hints[] = $key.' '.$registry->get($key)->paramsHint();
            }
        }

        return $hints === [] ? 'none' : implode('; ', $hints);
    }
}
