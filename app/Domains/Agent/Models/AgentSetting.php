<?php

namespace App\Domains\Agent\Models;

use App\Domains\AI\Models\Module;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $mode
 * @property bool $kill_switch
 * @property int|null $live_voice_version_id
 * @property array<string, int|null>|null $caps
 * @property int $auto_send_delay_seconds
 * @property list<string>|null $link_allowlist
 * @property Carbon|null $paused_until
 * @property string|null $paused_reason
 * @property array<string, mixed>|null $auto_send
 */
class AgentSetting extends Model
{
    public const MODE_OFF = 'off';

    public const MODE_SHADOW = 'shadow';

    public const MODE_DRAFT = 'draft';

    public const MODE_LIVE = 'live';

    public const MODES = [self::MODE_OFF, self::MODE_SHADOW, self::MODE_DRAFT, self::MODE_LIVE];

    protected $table = 'agent_settings';

    protected $fillable = [
        'workspace_id',
        'mode',
        'kill_switch',
        'live_voice_version_id',
        'caps',
        'auto_send_delay_seconds',
        'link_allowlist',
        'paused_until',
        'paused_reason',
        'auto_send',
    ];

    protected $casts = [
        'kill_switch' => 'boolean',
        'caps' => 'array',
        'link_allowlist' => 'array',
        'auto_send_delay_seconds' => 'integer',
        'paused_until' => 'datetime',
        'auto_send' => 'array',
    ];

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<VoiceVersion, $this> */
    public function liveVoice(): BelongsTo
    {
        return $this->belongsTo(VoiceVersion::class, 'live_voice_version_id');
    }

    /**
     * Lazily created. A workspace that already runs the old Commerce Assist
     * drafting starts in draft mode (the new pipeline takes over drafting);
     * everyone else starts off.
     */
    public static function forWorkspace(int $workspaceId): self
    {
        return static::firstOrCreate(
            ['workspace_id' => $workspaceId],
            [
                'mode' => self::defaultMode(),
                'kill_switch' => false,
                'caps' => self::defaultCaps(),
                'auto_send_delay_seconds' => (int) config('agent.auto_send_delay_seconds', 300),
            ],
        );
    }

    public static function defaultMode(): string
    {
        try {
            $legacyActive = Module::where('alias', 'CommerceAssist')->where('active', true)->exists();
        } catch (\Throwable) {
            $legacyActive = false;
        }

        return $legacyActive ? self::MODE_DRAFT : self::MODE_OFF;
    }

    /** @return array<string, int> */
    public static function defaultCaps(): array
    {
        $d = (array) config('agent.auto_send.defaults', []);

        return [
            'auto_send_per_day' => (int) ($d['per_day'] ?? 0),
            'auto_send_per_customer_per_day' => (int) ($d['per_customer_per_day'] ?? 0),
            'auto_send_consecutive_per_conversation' => (int) ($d['consecutive_per_conversation'] ?? 2),
            'actions_per_day' => 0,
        ];
    }

    /** A stored cap, falling back to the default for a key this row never saved. */
    public function cap(string $key): int
    {
        $caps = $this->caps ?? [];

        return (int) (array_key_exists($key, $caps) && $caps[$key] !== null ? $caps[$key] : (self::defaultCaps()[$key] ?? 0));
    }

    /**
     * Auto-send options (Phase 8), defaults from config('agent.auto_send.defaults').
     *
     * @return array{allowed_languages: list<string>, channels: list<string>, disclosure_line: string, status_after_send: string}
     */
    public function autoSendOptions(): array
    {
        $d = (array) config('agent.auto_send.defaults', []);
        $o = array_replace([
            'allowed_languages' => $d['allowed_languages'] ?? ['en'],
            'channels' => $d['channels'] ?? ['email'],
            'disclosure_line' => $d['disclosure_line'] ?? '',
            'status_after_send' => $d['status_after_send'] ?? 'pending',
        ], array_filter((array) ($this->auto_send ?? []), fn ($v) => $v !== null));

        $status = in_array($o['status_after_send'], ['pending', 'open'], true) ? $o['status_after_send'] : 'pending';

        return [
            'allowed_languages' => array_values(array_unique(array_map(fn ($l) => mb_strtolower(trim((string) $l)), (array) $o['allowed_languages']))),
            'channels' => array_values(array_unique(array_map('strval', (array) $o['channels']))),
            'disclosure_line' => trim((string) $o['disclosure_line']),
            'status_after_send' => $status,
        ];
    }

    /** Auto-sending is paused (the hourly breaker or the "Pause AI agent" control). */
    public function isPaused(): bool
    {
        return $this->paused_until !== null && $this->paused_until->isFuture();
    }

    /** The new pipeline produces the visible draft (the legacy filter must not). */
    public function pipelineOwnsDrafts(): bool
    {
        return in_array($this->mode, [self::MODE_DRAFT, self::MODE_LIVE], true);
    }

    public function runsPipeline(): bool
    {
        return $this->mode !== self::MODE_OFF;
    }
}
