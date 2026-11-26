// Shopify action catalogue, policies and approval settings
// (docs/agent-actions.md, ActionPolicyController.php). Read every field
// defensively: PHP sends [] for an empty map and older rows can miss keys.

export type PolicyMode = 'never' | 'approval' | 'always';

export interface ScopeAdvisory {
    scope: string;
    text: string;
}

export interface CatalogueAction {
    key: string;
    label: string;
    summary: string;
    /** Groups: every group is needed, any one scope within a group will do. */
    required_scopes: string[][];
    missing_scopes: string[][];
    available: boolean;
    unavailable_reason: string | null;
    money: boolean;
    reversible: boolean;
    risk: string | null;
    reply_waits: boolean;
    unattended_allowed: boolean;
    warehouse_sensitive: boolean;
    touches_shopify: boolean;
    params_hint: string | null;
    workspace_mode: PolicyMode | string;
}

export interface Catalogue {
    shopify_connected: boolean;
    granted_scopes: string[];
    scopes_known: boolean;
    advisories: ScopeAdvisory[];
    actions: CatalogueAction[];
}

/** Every limit is optional; amounts arrive as strings ("20.00"). */
export interface PolicyLimits {
    max_amount?: string | number | null;
    per_customer_30d_amount?: string | number | null;
    per_customer_30d_count?: number | null;
    per_day_amount?: string | number | null;
    per_day_count?: number | null;
    max_percentage?: number | null;
    max_order_age_days?: number | null;
    allowed_fulfilment_states?: string[] | null;
    allowed_countries?: string[] | null;
    require_sender_email_match?: boolean | null;
    [key: string]: unknown;
}

export interface Policy {
    id: number | null;
    mode: PolicyMode | string;
    limits: PolicyLimits | unknown[];
    playbook: { id: number; slug: string | null; name: string | null } | null;
    override_workspace: boolean;
    updated_by?: number | null;
    updated_at?: string | null;
}

export interface EffectivePolicy {
    action_key: string;
    mode: PolicyMode | string;
    limits: PolicyLimits | unknown[];
    source: 'workspace' | 'workspace+playbook' | 'playbook_override' | string;
    forced: string[];
    workspace_policy_id: number | null;
    playbook_policy_id: number | null;
    playbook_id: number | null;
}

export interface ActionPolicyEntry {
    action_key: string;
    label: string;
    money: boolean;
    unattended_allowed: boolean;
    workspace: Policy;
    playbooks: Policy[];
    effective: EffectivePolicy;
}

export interface PoliciesResponse {
    policies: ActionPolicyEntry[];
    modes: string[];
    limit_keys: string[];
}

export interface UpdatePolicyBody {
    mode: PolicyMode;
    playbook_id?: number | null;
    override_workspace?: boolean;
    limits?: PolicyLimits;
}

export interface DiscountTemplate {
    key: string;
    label: string;
    type: 'percentage' | 'fixed' | string;
    value: number | string;
    max_value?: number | string | null;
    expires_days: number | string;
    minimum_subtotal?: number | string | null;
}

export interface ActionSettingsData {
    approver_role_money: string;
    approver_role_other: string;
    approval_expiry_hours: number;
    refund_hard_ceiling: string;
    unattended_order_age_minutes: number;
    audit_note: boolean;
    discount_templates: DiscountTemplate[];
    updated_by: number | null;
    updated_at: string | null;
}

/** Hard maximums from config/agent_actions.php (the server enforces them). */
export interface ActionHardLimits {
    max_refund_ceiling: number;
    max_discount_percentage: number;
    max_discount_expiry_days: number;
}

export interface ActionSettingsResponse {
    settings: ActionSettingsData;
    roles: string[];
    gates?: {
        mode: string;
        kill_switch: boolean;
        paused_until: string | null;
        paused_reason: string | null;
        actions_per_day: number;
    };
    /** Last currency seen on an action for this shop; null until one ran. */
    shop_currency?: string | null;
    hard_limits?: ActionHardLimits;
}

export type UpdateActionSettingsBody = Partial<Omit<ActionSettingsData, 'updated_by' | 'updated_at'>>;

/**
 * The global switches that decide whether anything can run unattended
 * (agent settings), from GET /agent/actions/settings `gates`; null when unknown.
 */
export interface UnattendedGates {
    mode: string | null;
    kill_switch: boolean | null;
    paused_until: string | null;
    actions_per_day: number | null;
}

export interface AuditFilters {
    status?: string;
    action_key?: string;
    /** YYYY-MM-DD */
    from?: string;
    /** YYYY-MM-DD (inclusive: the whole day) */
    to?: string;
    page?: number;
    per_page?: number;
}
