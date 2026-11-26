<?php

namespace App\Http\Requests\Mailboxes;

use App\Domains\Mailbox\Models\Channel;
use App\Domains\Mailbox\Models\Mailbox;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The access token and app secret never go back to the browser, so on an
 * update they may be left blank to keep the stored value (same pattern as
 * mailbox passwords). They are required only when nothing is stored yet.
 */
class UpdateWhatsAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $stored = $this->storedConfig();

        return [
            'phone_number_id' => ['required', 'string', 'max:64'],
            'access_token' => [filled($stored['access_token'] ?? null) ? 'nullable' : 'required', 'string', 'max:2048'],
            'app_secret' => [filled($stored['app_secret'] ?? null) ? 'nullable' : 'required', 'string', 'max:255'],
        ];
    }

    /**
     * The config to store: submitted values, with blank secrets falling back
     * to the stored ones.
     *
     * @return array{phone_number_id: string, access_token: string, app_secret: string}
     */
    public function configToStore(): array
    {
        $stored = $this->storedConfig();
        $data = $this->validated();

        return [
            'phone_number_id' => (string) $data['phone_number_id'],
            'access_token' => (string) (filled($data['access_token'] ?? null) ? $data['access_token'] : ($stored['access_token'] ?? '')),
            'app_secret' => (string) (filled($data['app_secret'] ?? null) ? $data['app_secret'] : ($stored['app_secret'] ?? '')),
        ];
    }

    /** @return array<string, mixed> */
    private function storedConfig(): array
    {
        $mailbox = $this->route('mailbox');
        if (! $mailbox instanceof Mailbox) {
            return [];
        }

        /** @var Channel|null $channel */
        $channel = $mailbox->channels()->where('type', 'whatsapp')->first();

        return is_array($channel?->config) ? $channel->config : [];
    }
}
