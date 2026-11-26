<?php

namespace App\Domains\Mailbox\Models;

use App\Domains\Conversation\Models\Conversation;
use App\Models\User;
use Database\Factories\MailboxFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Mailbox extends Model
{
    /** @use HasFactory<MailboxFactory> */
    use HasFactory;

    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'channel_type', 'active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('mailbox');
    }

    protected static function newFactory(): MailboxFactory
    {
        return MailboxFactory::new();
    }

    protected $fillable = [
        'workspace_id',
        'name',
        'email',
        'signature',
        'imap_config',
        'smtp_config',
        'auto_reply_config',
        'channel_type',
        'active',
        'webhook_token',
        'aliases',
    ];

    protected $casts = [
        'active' => 'boolean',
        'auto_reply_config' => 'array',
        // Extra addresses that count as "us" (see MailboxIdentity).
        'aliases' => 'array',
    ];

    /**
     * The decrypted IMAP/SMTP configs hold passwords, so they are never part
     * of toArray()/JSON (Inertia props, API responses). Server code reads them
     * through the attributes as before; the settings page uses the safe*Config()
     * helpers below. webhook_token authenticates inbound webhooks, so it is
     * hidden too (the WhatsApp setup page passes it explicitly).
     */
    protected $hidden = [
        'imap_config',
        'smtp_config',
        'webhook_token',
    ];

    // ── Encrypted JSON accessors ──────────────────────────────────

    public function getImapConfigAttribute(?string $value): ?array
    {
        if (! $value) {
            return null;
        }
        try {
            return json_decode(Crypt::decryptString($value), true);
        } catch (\Exception) {
            return null;
        }
    }

    public function setImapConfigAttribute(?array $value): void
    {
        $this->attributes['imap_config'] = $value
            ? Crypt::encryptString(json_encode($value))
            : null;
    }

    public function getSmtpConfigAttribute(?string $value): ?array
    {
        if (! $value) {
            return null;
        }
        try {
            return json_decode(Crypt::decryptString($value), true);
        } catch (\Exception) {
            return null;
        }
    }

    public function setSmtpConfigAttribute(?array $value): void
    {
        $this->attributes['smtp_config'] = $value
            ? Crypt::encryptString(json_encode($value))
            : null;
    }

    // ── Relations ────────────────────────────────────────────────

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'mailbox_user')
            ->withPivot('permissions');
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    // ── Helpers ──────────────────────────────────────────────────

    public function hasImapConfig(): bool
    {
        return ! empty($this->imap_config);
    }

    public function hasSmtpConfig(): bool
    {
        return ! empty($this->smtp_config);
    }

    /**
     * IMAP config for display: everything except the password, plus has_password.
     *
     * @return array<string, mixed>|null
     */
    public function safeImapConfig(): ?array
    {
        return self::withoutPassword($this->imap_config);
    }

    /**
     * SMTP config for display: everything except the password, plus has_password.
     *
     * @return array<string, mixed>|null
     */
    public function safeSmtpConfig(): ?array
    {
        return self::withoutPassword($this->smtp_config);
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @return array<string, mixed>|null
     */
    private static function withoutPassword(?array $config): ?array
    {
        if ($config === null) {
            return null;
        }

        $hasPassword = isset($config['password']) && $config['password'] !== '';
        unset($config['password']);

        return [...$config, 'has_password' => $hasPassword];
    }
}
