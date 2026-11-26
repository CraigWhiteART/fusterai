<?php

namespace App\Http\Requests\Mailboxes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMailboxRequest extends FormRequest
{
    /** Values offered by the Encryption selects on Mailboxes/Settings.tsx. */
    public const ENCRYPTION_OPTIONS = ['ssl', 'tls', 'none'];

    public function authorize(): bool
    {
        return true;
    }

    /** Aliases: trimmed, lower-cased, blanks dropped; a comma/newline separated string is accepted too. */
    protected function prepareForValidation(): void
    {
        if (! $this->has('aliases')) {
            return;
        }

        $aliases = $this->input('aliases');
        if (is_string($aliases)) {
            $aliases = preg_split('/[\s,;]+/', $aliases) ?: [];
        }
        if (is_array($aliases)) {
            $aliases = array_values(array_filter(
                array_map(fn ($a) => is_string($a) ? strtolower(trim($a)) : $a, $aliases),
                fn ($a) => $a !== '' && $a !== null,
            ));
        }

        $this->merge(['aliases' => $aliases]);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'email' => ['sometimes', 'email'],
            'signature' => ['nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
            // Extra addresses that count as "us" for mail sync (Gmail "Send mail as").
            'aliases' => ['sometimes', 'nullable', 'array', 'max:20'],
            'aliases.*' => ['string', 'email', 'max:255', 'distinct'],
            // Once nested keys have rules, validated() returns only the listed
            // keys, so every config key the app stores is listed here.
            'imap_config' => ['nullable', 'array'],
            'imap_config.host' => ['nullable', 'string', 'max:255'],
            'imap_config.port' => ['nullable', 'integer', 'between:1,65535'],
            'imap_config.encryption' => ['nullable', 'string', Rule::in(self::ENCRYPTION_OPTIONS)],
            'imap_config.username' => ['nullable', 'string', 'max:255'],
            // Blank password = keep the stored one (see MailboxService::update).
            'imap_config.password' => ['nullable', 'string'],
            'imap_config.validate_cert' => ['sometimes', 'boolean'],
            'smtp_config' => ['nullable', 'array'],
            'smtp_config.host' => ['nullable', 'string', 'max:255'],
            'smtp_config.port' => ['nullable', 'integer', 'between:1,65535'],
            // ssl = implicit TLS (465), tls = STARTTLS required (587), none = no TLS forced.
            'smtp_config.encryption' => ['nullable', 'string', Rule::in(self::ENCRYPTION_OPTIONS)],
            'smtp_config.username' => ['nullable', 'string', 'max:255'],
            'smtp_config.password' => ['nullable', 'string'],
            'auto_reply_config' => ['nullable', 'array'],
            'auto_reply_config.enabled' => ['boolean'],
            'auto_reply_config.subject' => ['nullable', 'string', 'max:255'],
            'auto_reply_config.body' => ['nullable', 'string'],
            'auto_reply_config.auto_close_pending_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'auto_reply_config.office_hours' => ['nullable', 'array'],
            'auto_reply_config.office_hours.enabled' => ['boolean'],
            'auto_reply_config.office_hours.timezone' => ['nullable', 'string', 'timezone'],
            'auto_reply_config.office_hours.subject' => ['nullable', 'string', 'max:255'],
            'auto_reply_config.office_hours.message' => ['nullable', 'string'],
            'auto_reply_config.office_hours.schedule' => ['nullable', 'array'],
            'auto_reply_config.office_hours.schedule.*' => ['nullable', 'array'],
            'auto_reply_config.office_hours.schedule.*.open' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'auto_reply_config.office_hours.schedule.*.close' => ['nullable', 'string', 'regex:/^\d{2}:\d{2}$/'],
        ];
    }
}
