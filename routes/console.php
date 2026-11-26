<?php

use App\Domains\Conversation\Models\Conversation;
use App\Domains\Mailbox\Models\Mailbox;
use App\Events\ConversationUpdated;
use Illuminate\Support\Facades\Schedule;

// Sync new mail into the ledger every minute and queue it for ingest. The
// overlap lock expires after 15 minutes (default is 24h) so a killed run
// cannot stop mail for a day; FetchEmails also locks per mailbox.
Schedule::command('emails:fetch')->everyMinute()->withoutOverlapping(15);

// Re-queue history backfills whose job chain was lost (killed worker, Redis
// flush). A healthy run checkpoints every few minutes and is left alone.
Schedule::command('mail:backfill --recover')->everyFiveMinutes()->withoutOverlapping(10);

// Same watchdog for history mining runs (docs/agent-mining.md).
Schedule::command('agent:mine --recover')->everyFiveMinutes()->withoutOverlapping(10);

// AI auto-send circuit breakers (docs/agent-autosend.md): only ever demote.
Schedule::command('agent:autosend-breakers')->everyFifteenMinutes()->withoutOverlapping(10);

// Wake snoozed conversations back to open
Schedule::call(function () {
    Conversation::query()
        ->where('status', 'open')
        ->where('snoozed_until', '<=', now())
        ->whereNotNull('snoozed_until')
        ->update(['snoozed_until' => null]);
})->everyMinute();

// Horizon snapshot (metrics)
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Daily digest email at 8am
Schedule::command('notifications:digest')->dailyAt('08:00');

// Auto-close stale pending conversations (runs daily at midnight)
Schedule::call(function () {
    Mailbox::whereNotNull('auto_reply_config')
        ->get()
        ->each(function (Mailbox $mailbox) {
            $days = (int) ($mailbox->auto_reply_config['auto_close_pending_days'] ?? 0);
            if ($days <= 0) {
                return;
            }

            Conversation::where('mailbox_id', $mailbox->id)
                ->where('status', 'pending')
                ->where('last_reply_at', '<=', now()->subDays($days))
                ->each(function (Conversation $conversation) {
                    $conversation->update(['status' => 'closed']);

                    $conversation->threads()->create([
                        'type' => 'activity',
                        'body' => '<p>Conversation automatically closed after no customer reply.</p>',
                        'body_plain' => 'Conversation automatically closed after no customer reply.',
                        'source' => 'web',
                    ]);

                    broadcast(new ConversationUpdated($conversation->fresh()));
                });
        });
})->daily();
