<?php

namespace App\Domains\Commerce\Actions\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-workspace action settings. Edited by admins only (manage-settings),
 * never by any AI path.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $approver_role_money
 * @property string $approver_role_other
 * @property int $approval_expiry_hours
 * @property string $refund_hard_ceiling
 * @property int $unattended_order_age_minutes
 * @property bool $audit_note
 * @property list<array<string, mixed>>|null $discount_templates
 * @property int|null $updated_by
 */
class ActionSettings extends Model
{
    public const ROLES = ['agent', 'manager', 'admin', 'super_admin'];

    protected $table = 'agent_action_settings';

    protected $fillable = [
        'workspace_id',
        'approver_role_money',
        'approver_role_other',
        'approval_expiry_hours',
        'refund_hard_ceiling',
        'unattended_order_age_minutes',
        'audit_note',
        'discount_templates',
        'updated_by',
    ];

    protected $casts = [
        'approval_expiry_hours' => 'integer',
        'unattended_order_age_minutes' => 'integer',
        'audit_note' => 'boolean',
        'discount_templates' => 'array',
    ];

    public static function forWorkspace(int $workspaceId): self
    {
        $d = (array) config('agent_actions.defaults', []);

        return static::firstOrCreate(['workspace_id' => $workspaceId], [
            'approver_role_money' => (string) ($d['approver_role_money'] ?? 'admin'),
            'approver_role_other' => (string) ($d['approver_role_other'] ?? 'agent'),
            'approval_expiry_hours' => (int) ($d['approval_expiry_hours'] ?? 48),
            'refund_hard_ceiling' => (string) ($d['refund_hard_ceiling'] ?? '150.00'),
            'unattended_order_age_minutes' => (int) ($d['unattended_order_age_minutes'] ?? 60),
            'audit_note' => (bool) ($d['audit_note'] ?? true),
            'discount_templates' => [],
        ]);
    }

    /** @return array<string, array<string, mixed>> keyed by template key */
    public function templates(): array
    {
        $out = [];
        foreach ((array) ($this->discount_templates ?? []) as $template) {
            if (is_array($template) && filled($template['key'] ?? null)) {
                $out[(string) $template['key']] = $template;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'approver_role_money' => $this->approver_role_money,
            'approver_role_other' => $this->approver_role_other,
            'approval_expiry_hours' => $this->approval_expiry_hours,
            'refund_hard_ceiling' => (string) $this->refund_hard_ceiling,
            'unattended_order_age_minutes' => $this->unattended_order_age_minutes,
            'audit_note' => $this->audit_note,
            'discount_templates' => array_values($this->templates()),
            'updated_by' => $this->updated_by,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
