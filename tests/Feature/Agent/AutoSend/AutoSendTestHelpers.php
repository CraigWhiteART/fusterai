<?php

require_once __DIR__.'/../AgentTestHelpers.php';
require_once __DIR__.'/../LoopTestHelpers.php';

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Pipeline\AgentPipeline;
use App\Domains\Agent\Pipeline\PipelineInput;
use App\Domains\Conversation\Jobs\SendReplyJob;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Mailbox\Sync\Models\MailMessage;
use Symfony\Component\Mailer\SentMessage;

/**
 * Auto-send fixtures (prefix `autoT`): a live workspace whose order_status
 * playbook is on auto at 100%, mail captured by the array transport.
 *
 * @return array<string, mixed> loopTWorkspace() keys + settings
 */
function autoTWorkspace(array $settings = [], array $playbook = [], string $message = 'Hi, has my order shipped yet? Thanks!'): array
{
    $f = loopTWorkspace($message);
    // The customer's message as the mail ledger recorded it (real threading headers).
    $f['mail'] = MailMessage::forceCreate([
        'mailbox_id' => $f['mailbox']->id, 'folder' => '[Gmail]/All Mail', 'uidvalidity' => 1, 'uid' => random_int(1, 1_000_000),
        'message_id' => 'cust-1@example.com', 'references' => [], 'from_email' => 'buyer@example.com', 'from_name' => 'Alex Buyer',
        'subject' => 'Where is my order?', 'sent_at' => now()->subMinutes(5), 'direction' => 'inbound', 'classification' => 'support',
        'customer_email' => 'buyer@example.com', 'headers' => [], 'thread_id' => $f['thread']->id, 'conversation_id' => $f['conversation']->id,
    ]);
    $f['thread']->forceFill(['message_id' => 'cust-1@example.com', 'mail_message_id' => $f['mail']->id, 'meta' => ['message_id' => '<cust-1@example.com>']])->save();
    $f['mailbox']->forceFill(['signature' => 'The Soul Dial team', 'smtp_config' => null])->save();

    $f['settings'] = AgentSetting::forWorkspace($f['workspace']->id);
    $f['settings']->forceFill(array_replace([
        'mode' => AgentSetting::MODE_LIVE,
        'kill_switch' => false,
        'auto_send_delay_seconds' => 120,
        'caps' => ['auto_send_per_day' => 20, 'auto_send_per_customer_per_day' => 5, 'auto_send_consecutive_per_conversation' => 2, 'actions_per_day' => 0],
    ], $settings))->save();

    $f['playbook']->forceFill(array_replace(['mode' => Playbook::MODE_AUTO, 'rollout_percent' => 100, 'always_human' => false], $playbook))->save();

    config(['mail.mailers.smtp' => ['transport' => 'array']]);
    app('mail.manager')->purge('smtp');

    return $f;
}

function autoTRun(Conversation $conversation, bool $force = false): ?AgentRun
{
    return app(AgentPipeline::class)->run(PipelineInput::fromConversation($conversation->fresh(), AgentRun::PURPOSE_LIVE, force: $force))->run;
}

function autoTPending(Conversation $conversation): ?Thread
{
    return Thread::where('conversation_id', $conversation->id)->where('origin', Thread::ORIGIN_AI)->latest('id')->first();
}

/** What the email-outbound worker does when the delayed SendReplyJob comes due. */
function autoTExecute(Thread $thread): void
{
    $thread = $thread->fresh();
    (new SendReplyJob($thread, Conversation::find($thread->conversation_id), $thread->send_at ?? now()))->handle();
}

/** @return list<SentMessage> */
function autoTSent(): array
{
    return array_values(iterator_to_array(app('mail.manager')->mailer('smtp')->getSymfonyTransport()->messages()));
}

function autoTCustomerMessage(Conversation $conversation, string $text = 'Any news?'): Thread
{
    return Thread::factory()->create([
        'conversation_id' => $conversation->id,
        'customer_id' => $conversation->customer_id,
        'user_id' => null,
        'type' => 'message',
        'body' => '<p>'.e($text).'</p>',
        'body_plain' => $text,
        'created_at' => now()->addMinutes(2),
    ]);
}

/** A gate input where every auto-send condition holds. */
function autoTGate(array $overrides = [], array $context = []): array
{
    return array_replace([
        'agent_mode' => 'live',
        'kill_switch' => false,
        'triage_failed' => false,
        'playbook' => ['mode' => 'auto', 'rollout_percent' => 100, 'always_human' => false],
        'rollout_bucket' => 10,
        'risk_flags' => [],
        'writer_handover' => null,
        'needs_human' => false,
        'order_withheld' => false,
        'missing_evidence' => [],
        'validator_passed' => true,
        'validator_error' => false,
        'has_draft' => true,
        'proposed_actions' => [],
        'auto_send' => array_replace(autoTContext(), $context),
    ], $overrides);
}

function autoTContext(): array
{
    return [
        'context_error' => false,
        'purpose_live' => true,
        'paused' => false,
        'playbook_hold' => false,
        'rewrites' => 0,
        'claims_grounded' => true,
        'language' => 'en',
        'allowed_languages' => ['en'],
        'sentiment' => 'calm',
        'channel' => 'email',
        'allowed_channels' => ['email'],
        'human_replied' => false,
        'customer_blocked' => false,
        'inbound_automated' => false,
        'requested_by_human' => false,
        'daily_remaining' => 5,
        'customer_remaining' => 2,
        'consecutive_remaining' => 2,
        'hourly_breaker' => false,
    ];
}
