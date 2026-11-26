<?php

namespace App\Domains\Commerce\Actions\Http\Controllers;

use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Commerce\Actions\ActionRegistry;
use App\Domains\Commerce\Actions\Models\ActionExecution;
use App\Domains\Commerce\Actions\Models\ActionPolicy;
use App\Domains\Commerce\Actions\Models\ActionSettings;
use App\Domains\Commerce\Actions\Policy\PolicyResolver;
use App\Domains\Commerce\Actions\Support\Scopes;
use App\Domains\Commerce\Models\CommerceSetting;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The action catalogue (any signed-in user of the workspace) and the
 * permission settings: policies and approver settings (admins only,
 * `manage-settings`). This is the ONLY writer of agent_action_policies and
 * agent_action_settings; no AI path can reach it (proposals strip
 * permission changes, and no tool writes here).
 */
class ActionPolicyController extends Controller
{
    public function __construct(
        private readonly ActionRegistry $registry,
        private readonly PolicyResolver $resolver,
    ) {}

    public function catalogue(Request $request): JsonResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;
        $commerce = CommerceSetting::forWorkspace($workspaceId);
        $scopes = Scopes::for($commerce);
        $connected = $commerce->hasShopifyCredentials();

        $actions = [];
        foreach ($this->registry->all() as $definition) {
            $entry = $definition->catalogueEntry($scopes);
            if (! $connected) {
                $entry['available'] = false;
                $entry['unavailable_reason'] = 'Shopify is not connected.';
            }
            $entry['workspace_mode'] = $this->resolver->resolve($workspaceId, $definition->key(), null)->mode;
            $actions[] = $entry;
        }

        return response()->json([
            'shopify_connected' => $connected,
            'granted_scopes' => $scopes->granted,
            'scopes_known' => $scopes->known(),
            'advisories' => array_values(array_filter([
                ! $scopes->has('read_all_orders') ? [
                    'scope' => 'read_all_orders',
                    'text' => 'Without read_all_orders, Shopify only shows orders from the last 60 days: older orders cannot be looked up or acted on. Add read_all_orders to the app\'s access scopes (no Shopify approval needed for an app in your own organisation), release the version and approve it on the store.',
                ] : null,
                ! $scopes->canReadFulfillmentOrders() ? [
                    'scope' => 'read_third_party_fulfillment_orders',
                    'text' => 'Without a fulfilment-order scope fusterai cannot see whether 4PX already has an order, so address changes and cancellations are always approval-only.',
                ] : null,
            ])),
            'actions' => $actions,
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        $this->authorize('manage-settings');

        $workspaceId = (int) $request->user()->workspace_id;
        $agent = AgentSetting::forWorkspace($workspaceId);

        return response()->json([
            'settings' => ActionSettings::forWorkspace($workspaceId)->toApiArray(),
            'roles' => ActionSettings::ROLES,
            // The workspace-wide gates every unattended action also needs
            // (display only: the executor re-checks them before each run).
            'gates' => [
                'mode' => $agent->mode,
                'kill_switch' => (bool) $agent->kill_switch,
                'paused_until' => $agent->isPaused() ? $agent->paused_until?->toIso8601String() : null,
                'paused_reason' => $agent->isPaused() ? $agent->paused_reason : null,
                'actions_per_day' => $agent->cap('actions_per_day'),
            ],
            // Last currency seen on an action for this shop; null until one ran.
            'shop_currency' => ActionExecution::where('workspace_id', $workspaceId)->whereNotNull('currency')->latest('id')->value('currency'),
            // Hard maximums (config/agent_actions.php), never above these whatever is saved.
            'hard_limits' => [
                'max_refund_ceiling' => (float) config('agent_actions.max_refund_ceiling', 5000),
                'max_discount_percentage' => (float) config('agent_actions.max_discount_percentage', 50),
                'max_discount_expiry_days' => (int) config('agent_actions.max_discount_expiry_days', 90),
            ],
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->authorize('manage-settings');

        $data = $request->validate([
            'approver_role_money' => ['sometimes', Rule::in(ActionSettings::ROLES)],
            'approver_role_other' => ['sometimes', Rule::in(ActionSettings::ROLES)],
            'approval_expiry_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'refund_hard_ceiling' => ['sometimes', 'numeric', 'min:0', 'max:'.(float) config('agent_actions.max_refund_ceiling', 5000)],
            'unattended_order_age_minutes' => ['sometimes', 'integer', 'min:0', 'max:10080'],
            'audit_note' => ['sometimes', 'boolean'],
            'discount_templates' => ['sometimes', 'array', 'max:20'],
            'discount_templates.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            'discount_templates.*.label' => ['required', 'string', 'max:120'],
            'discount_templates.*.type' => ['required', Rule::in(['percentage', 'fixed'])],
            'discount_templates.*.value' => ['required', 'numeric', 'gt:0'],
            'discount_templates.*.max_value' => ['nullable', 'numeric', 'gt:0'],
            'discount_templates.*.expires_days' => ['required', 'integer', 'min:1', 'max:'.(int) config('agent_actions.max_discount_expiry_days', 90)],
            'discount_templates.*.minimum_subtotal' => ['nullable', 'numeric', 'min:0'],
        ]);

        foreach ((array) ($data['discount_templates'] ?? []) as $i => $template) {
            $max = (float) ($template['max_value'] ?? $template['value']);
            if ($template['type'] === 'percentage' && $max > (float) config('agent_actions.max_discount_percentage', 50)) {
                return response()->json(['message' => 'A percentage template cannot exceed the hard maximum.', 'errors' => ["discount_templates.{$i}.max_value" => ['Too high.']]], 422);
            }
            if ($max < (float) $template['value']) {
                return response()->json(['message' => 'max_value must be at least value.', 'errors' => ["discount_templates.{$i}.max_value" => ['Below value.']]], 422);
            }
        }

        $settings = ActionSettings::forWorkspace((int) $request->user()->workspace_id);
        $settings->fill([...$data, 'updated_by' => $request->user()->id])->save();

        return response()->json(['settings' => $settings->fresh()?->toApiArray()]);
    }

    public function policies(Request $request): JsonResponse
    {
        $this->authorize('manage-settings');
        $workspaceId = (int) $request->user()->workspace_id;
        $rows = ActionPolicy::where('workspace_id', $workspaceId)->with('playbook:id,slug,name')->orderBy('action_key')->orderBy('playbook_id')->get();

        $actions = [];
        foreach ($this->registry->all() as $key => $definition) {
            $workspace = $rows->first(fn ($p) => $p->action_key === $key && $p->playbook_id === null);
            $actions[] = [
                'action_key' => $key,
                'label' => $definition->label(),
                'money' => $definition->isMoney(),
                'unattended_allowed' => $definition->unattendedAllowed(),
                'workspace' => $workspace !== null ? $this->presentPolicy($workspace) : ['id' => null, 'mode' => ActionPolicy::NEVER, 'limits' => [], 'playbook' => null, 'override_workspace' => false],
                'playbooks' => $rows->filter(fn ($p) => $p->action_key === $key && $p->playbook_id !== null)->map(fn ($p) => $this->presentPolicy($p))->values()->all(),
                'effective' => $this->resolver->resolve($workspaceId, $key, null)->toArray(),
            ];
        }

        return response()->json(['policies' => $actions, 'modes' => ActionPolicy::MODES, 'limit_keys' => PolicyResolver::LIMIT_KEYS]);
    }

    public function updatePolicy(Request $request, string $action): JsonResponse
    {
        $this->authorize('manage-settings');
        abort_unless($this->registry->has($action), 404);
        $workspaceId = (int) $request->user()->workspace_id;

        $data = $request->validate([
            'mode' => ['required', Rule::in(ActionPolicy::MODES)],
            'playbook_id' => ['nullable', 'integer'],
            'override_workspace' => ['sometimes', 'boolean'],
            'limits' => ['sometimes', 'array'],
            'limits.max_amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'limits.per_customer_30d_amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'limits.per_customer_30d_count' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'limits.per_day_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'limits.per_day_count' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'limits.max_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'limits.max_order_age_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'limits.allowed_fulfilment_states' => ['nullable', 'array'],
            'limits.allowed_fulfilment_states.*' => ['string', Rule::in(['UNFULFILLED', 'ON_HOLD', 'OPEN', 'PENDING_FULFILLMENT', 'SCHEDULED', 'IN_PROGRESS', 'PARTIALLY_FULFILLED', 'FULFILLED', 'RESTOCKED', 'REQUEST_DECLINED', 'FULFILLMENT_NOT_REQUIRED'])],
            'limits.require_sender_email_match' => ['nullable', 'boolean'],
            'limits.allowed_countries' => ['nullable', 'array'],
            'limits.allowed_countries.*' => ['string', 'size:2', 'alpha'],
        ]);

        $playbookId = $data['playbook_id'] ?? null;
        if ($playbookId !== null) {
            abort_unless(Playbook::where('workspace_id', $workspaceId)->whereKey($playbookId)->exists(), 404);
        }

        $definition = $this->registry->get($action);
        $limits = PolicyResolver::clean((array) ($data['limits'] ?? []));
        if ($definition->isMoney() && ($limits['require_sender_email_match'] ?? true) === false) {
            return response()->json(['message' => 'The sender-email match cannot be switched off for money or address actions.', 'errors' => ['limits.require_sender_email_match' => ['Mandatory for this action.']]], 422);
        }
        if (! $definition->unattendedAllowed() && $data['mode'] === ActionPolicy::ALWAYS) {
            return response()->json(['message' => "{$definition->label()} is approval-only and cannot run unattended.", 'errors' => ['mode' => ['Approval-only action.']]], 422);
        }
        if ($data['mode'] === ActionPolicy::ALWAYS && ($missing = $this->missingRequiredLimits($workspaceId, $action, $playbookId, $limits, (bool) ($data['override_workspace'] ?? false))) !== []) {
            return response()->json([
                'message' => "{$definition->label()} cannot run on its own without a maximum: set ".implode(' and ', array_map(fn ($k) => self::LIMIT_LABELS[$k], $missing)).'.',
                'errors' => collect($missing)->mapWithKeys(fn ($k) => ["limits.{$k}" => ['Required to run on its own.']])->all(),
            ], 422);
        }

        $policy = ActionPolicy::updateOrCreate(
            ['workspace_id' => $workspaceId, 'action_key' => $action, 'playbook_id' => $playbookId],
            [
                'mode' => $data['mode'],
                'limits' => $limits,
                'override_workspace' => $playbookId !== null && (bool) ($data['override_workspace'] ?? false),
                'updated_by' => $request->user()->id,
            ],
        );

        activity('agent-actions')->performedOn($policy)->causedBy($request->user())->event('policy_updated')
            ->withProperties(['action_key' => $action, 'playbook_id' => $playbookId, 'mode' => $policy->mode, 'limits' => $limits])
            ->log("Action policy {$action} set to {$policy->mode}");

        return response()->json([
            'policy' => $this->presentPolicy($policy->fresh(['playbook']) ?? $policy),
            'effective' => $this->resolver->resolve($workspaceId, $action, $playbookId)->toArray(),
        ]);
    }

    public function destroyPolicy(Request $request, ActionPolicy $policy): JsonResponse
    {
        $this->authorize('manage-settings');
        abort_unless((int) $policy->workspace_id === (int) $request->user()->workspace_id, 404);

        $policy->delete();
        activity('agent-actions')->causedBy($request->user())->event('policy_deleted')
            ->withProperties(['action_key' => $policy->action_key, 'playbook_id' => $policy->playbook_id])
            ->log("Action policy {$policy->action_key} removed");

        return response()->json(['deleted' => true]);
    }

    private const LIMIT_LABELS = ['max_amount' => 'a maximum amount', 'max_percentage' => 'a maximum percentage'];

    /**
     * Limits an `always` money action cannot run without (LimitEvaluator
     * routes it to approval otherwise, so saving it would be misleading):
     *   refund, cancel_order  max_amount
     *   discount_code         max_percentage when a percentage template exists,
     *                         max_amount when a fixed one does (at least one).
     * Checked on the limits that would apply: a playbook row that overrides
     * the shop-wide one alone, otherwise the stricter merge of both.
     *
     * @param  array<string, mixed>  $limits
     * @return list<string>
     */
    private function missingRequiredLimits(int $workspaceId, string $action, ?int $playbookId, array $limits, bool $override): array
    {
        $required = match ($action) {
            'refund', 'cancel_order' => ['max_amount'],
            'discount_code' => $this->discountLimitKeys($workspaceId),
            default => [],
        };
        if ($required === []) {
            return [];
        }

        $effective = $limits;
        if ($playbookId !== null && ! $override) {
            $workspace = ActionPolicy::where('workspace_id', $workspaceId)->where('action_key', $action)->whereNull('playbook_id')->first();
            $effective = PolicyResolver::stricter(PolicyResolver::clean((array) ($workspace->limits ?? [])), $limits);
        }

        return array_values(array_filter($required, fn ($k) => ($effective[$k] ?? null) === null));
    }

    /** @return list<string> */
    private function discountLimitKeys(int $workspaceId): array
    {
        $types = collect((array) (ActionSettings::forWorkspace($workspaceId)->discount_templates ?? []))->pluck('type')->unique();
        $keys = array_values(array_filter([
            $types->contains('percentage') ? 'max_percentage' : null,
            $types->contains('fixed') ? 'max_amount' : null,
        ]));

        return $keys !== [] ? $keys : ['max_percentage'];
    }

    /** @return array<string, mixed> */
    private function presentPolicy(ActionPolicy $policy): array
    {
        return [
            'id' => $policy->id,
            'mode' => $policy->mode,
            'limits' => (array) ($policy->limits ?? []),
            'playbook' => $policy->playbook_id !== null ? ['id' => $policy->playbook_id, 'slug' => $policy->playbook?->slug, 'name' => $policy->playbook?->name] : null,
            'override_workspace' => $policy->override_workspace,
            'updated_by' => $policy->updated_by,
            'updated_at' => $policy->updated_at?->toIso8601String(),
        ];
    }
}
