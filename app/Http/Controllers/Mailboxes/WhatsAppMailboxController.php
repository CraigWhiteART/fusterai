<?php

namespace App\Http\Controllers\Mailboxes;

use App\Domains\Mailbox\Models\Channel;
use App\Domains\Mailbox\Models\Mailbox;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mailboxes\UpdateWhatsAppRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WhatsAppMailboxController extends Controller
{
    public function show(Request $request, Mailbox $mailbox): Response
    {
        $this->authorize('update', $mailbox);

        /** @var Channel|null $channel */
        $channel = $mailbox->channels()->where('type', 'whatsapp')->first();
        $config = is_array($channel?->config) ? $channel->config : null;

        // Secrets never leave the server: only the (non-secret) phone number id
        // is sent, plus has_* flags so the page can say "leave blank to keep".
        return Inertia::render('Mailboxes/WhatsAppSetup', [
            'mailbox' => [
                'id' => $mailbox->id,
                'name' => $mailbox->name,
                'webhook_token' => $mailbox->webhook_token,
                'channel' => $config === null ? null : [
                    'phone_number_id' => (string) ($config['phone_number_id'] ?? ''),
                    'has_access_token' => filled($config['access_token'] ?? null),
                    'has_app_secret' => filled($config['app_secret'] ?? null),
                ],
            ],
            'webhookUrl' => url('/api/webhooks/whatsapp/'.$mailbox->webhook_token),
        ]);
    }

    public function update(UpdateWhatsAppRequest $request, Mailbox $mailbox): RedirectResponse
    {
        $this->authorize('update', $mailbox);

        $mailbox->channels()->updateOrCreate(
            ['type' => 'whatsapp'],
            ['config' => $request->configToStore(), 'active' => true],
        );

        return back()->with('success', 'WhatsApp credentials saved.');
    }
}
