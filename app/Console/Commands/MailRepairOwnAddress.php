<?php

namespace App\Console\Commands;

use App\Domains\Conversation\Models\Conversation;
use App\Domains\Customer\Models\Customer;
use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Sync\IngestResult;
use App\Domains\Mailbox\Sync\MailIngestService;
use App\Domains\Mailbox\Sync\Models\MailMessage;
use App\Domains\Mailbox\Sync\Parsing\MessageClassifier;
use App\Domains\Mailbox\Sync\Threading\MailboxIdentity;
use App\Domains\Mailbox\Sync\Threading\ThreadResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Repairs a mailbox after mail from one of its own addresses (a Gmail "Send
 * mail as" alias, the Shopify store sender) was ingested as customer mail
 * because the address was not in mailboxes.aliases.
 *
 *  1. adds the addresses to the mailbox aliases;
 *  2. deletes the threads those messages created, and every conversation
 *     left with no message at all (its AI runs and notes go with it);
 *  3. recomputes direction / customer / classification of the ledger rows
 *     and ingests them again as history: self-addressed notifications are
 *     skipped, staff replies land on the customer's conversation as agent
 *     messages. No AI, notification or auto-reply runs.
 *
 * Without --apply it only reports what it would do.
 */
class MailRepairOwnAddress extends Command
{
    protected $signature = 'mail:repair-own-address
                            {address* : Address(es) that belong to the mailbox}
                            {--mailbox= : Mailbox ID (default: the only active IMAP mailbox)}
                            {--apply : Make the changes}';

    protected $description = 'Add own addresses to a mailbox and undo conversations created from mail they sent';

    public function handle(MailIngestService $ingest, ThreadResolver $resolver, MessageClassifier $classifier): int
    {
        $mailbox = $this->mailbox();
        if ($mailbox === null) {
            return self::FAILURE;
        }

        $addresses = array_values(array_unique(array_filter(array_map(
            fn ($a) => MailboxIdentity::canonical((string) $a),
            (array) $this->argument('address'),
        ))));
        if ($addresses === []) {
            $this->error('No valid address given.');

            return self::FAILURE;
        }

        $own = new MailboxIdentity($addresses[0], array_slice($addresses, 1));
        $rowIds = MailMessage::query()
            ->where('mailbox_id', $mailbox->id)
            ->where('direction', 'inbound')
            ->whereNotNull('from_email')
            ->orderBy('id')
            ->get(['id', 'from_email'])
            ->filter(fn (MailMessage $r) => $own->owns($r->from_email))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $threadIds = [];
        $conversationIds = [];
        foreach (array_chunk($rowIds, 1000) as $chunk) {
            $created = DB::table('mail_messages')->whereIn('id', $chunk)
                ->where('ingest_outcome', IngestResult::CREATED)->whereNotNull('thread_id')->pluck('thread_id');
            foreach (array_chunk($created->all(), 1000) as $ids) {
                foreach (DB::table('threads')->whereIn('id', $ids)->get(['id', 'conversation_id']) as $t) {
                    $threadIds[] = (int) $t->id;
                    $conversationIds[(int) $t->conversation_id] = true;
                }
            }
        }
        $conversationIds = array_keys($conversationIds);

        // Conversations that still hold a message from somebody else are kept.
        $keep = [];
        foreach (array_chunk($conversationIds, 1000) as $chunk) {
            $kept = DB::table('threads')->whereIn('conversation_id', $chunk)->where('type', 'message')
                ->whereNotIn('id', $threadIds === [] ? [0] : $threadIds)->distinct()->pluck('conversation_id');
            foreach ($kept as $id) {
                $keep[] = (int) $id;
            }
        }
        $delete = array_values(array_diff($conversationIds, $keep));

        $this->info("Mailbox {$mailbox->id} ({$mailbox->name}); own addresses: ".implode(', ', $addresses));
        $this->line('  ledger rows wrongly treated as customer mail: '.count($rowIds));
        $this->line('  threads to delete:                           '.count($threadIds));
        $this->line('  conversations to delete (nothing else in them): '.count($delete));
        $this->line('  conversations kept (other messages in them):    '.count($keep));

        if (! $this->option('apply')) {
            $this->warn('Dry run. Nothing changed. Add --apply to make the changes.');

            return self::SUCCESS;
        }

        // 1. Aliases, so new mail is right from here on.
        $aliases = array_values(array_unique(array_merge(
            array_map('strtolower', array_filter((array) ($mailbox->aliases ?? []), 'is_string')),
            $addresses,
        )));
        $mailbox->forceFill(['aliases' => $aliases])->save();
        $identity = MailboxIdentity::forMailbox($mailbox->refresh());

        // 2. Threads and empty conversations.
        foreach (array_chunk($threadIds, 500) as $chunk) {
            DB::table('threads')->whereIn('id', $chunk)->delete();
        }
        foreach (array_chunk($delete, 200) as $chunk) {
            $gone = Conversation::withTrashed()->whereIn('id', $chunk)->get();
            try {
                $gone->first()?->queueRemoveFromSearch($gone);
            } catch (Throwable $e) {
                $this->warn('  search index not updated: '.$e->getMessage());
            }
            DB::table('conversations')->whereIn('id', $chunk)->delete();
        }
        foreach ($keep as $conversationId) {
            $this->repairKept($conversationId, $identity);
        }

        // 3. Ledger rows: reset, re-read, ingest again as history.
        $counts = [];
        foreach (array_chunk($rowIds, 200) as $chunk) {
            foreach (MailMessage::query()->whereIn('id', $chunk)->orderBy('sent_at')->orderBy('id')->get() as $row) {
                $parsed = $row->toParsedMessage();
                $direction = $resolver->direction($parsed, $identity);
                $customer = $resolver->customer($parsed, $identity, $direction);
                $row->forceFill([
                    'thread_id' => null,
                    'conversation_id' => null,
                    'ingested_at' => null,
                    'ingest_outcome' => null,
                    'direction' => $direction,
                    'customer_email' => $customer !== null ? mb_substr(strtolower($customer['email']), 0, 255) : null,
                    'classification' => $classifier->classify($parsed, $direction),
                ])->save();

                try {
                    $outcome = $ingest->ingest($row->id, historical: true)->outcome;
                } catch (Throwable $e) {
                    $outcome = 'error';
                    $this->warn("  row {$row->id}: ".$e->getMessage());
                }
                $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            }
        }

        // The "customer" that was really us.
        $removedCustomers = 0;
        foreach (Customer::query()->where('workspace_id', $mailbox->workspace_id)->whereIn(DB::raw('lower(email)'), $addresses)->get() as $customer) {
            if (! $customer->conversations()->withTrashed()->exists() && ! DB::table('threads')->where('customer_id', $customer->id)->exists()) {
                $customer->delete();
                $removedCustomers++;
            }
        }

        $this->info('Done.');
        $this->line('  conversations deleted: '.count($delete).', threads deleted: '.count($threadIds).", customers removed: {$removedCustomers}");
        foreach ($counts as $outcome => $n) {
            $this->line("  re-ingested as {$outcome}: {$n}");
        }

        return self::SUCCESS;
    }

    /** A kept conversation must not belong to us, and its last-reply time must match what is left. */
    private function repairKept(int $conversationId, MailboxIdentity $identity): void
    {
        $conversation = DB::table('conversations')->where('id', $conversationId)->first(['id', 'customer_id']);
        if ($conversation === null) {
            return;
        }

        $updates = [];
        $ownerEmail = $conversation->customer_id !== null ? DB::table('customers')->where('id', $conversation->customer_id)->value('email') : null;
        if ($ownerEmail === null || $identity->owns((string) $ownerEmail)) {
            $customerId = DB::table('threads')->where('conversation_id', $conversationId)->where('type', 'message')
                ->whereNotNull('customer_id')->orderBy('created_at')->orderBy('id')->value('customer_id');
            if ($customerId !== null) {
                $updates['customer_id'] = $customerId;
            }
        }
        $last = DB::table('threads')->where('conversation_id', $conversationId)->where('type', 'message')->max('created_at');
        if ($last !== null) {
            $updates['last_reply_at'] = $last;
        }
        if ($updates !== []) {
            DB::table('conversations')->where('id', $conversationId)->update($updates);
        }
    }

    private function mailbox(): ?Mailbox
    {
        if ($this->option('mailbox') !== null) {
            $mailbox = Mailbox::query()->find((int) $this->option('mailbox'));
            if ($mailbox === null) {
                $this->error('Mailbox not found.');
            }

            return $mailbox;
        }

        $mailboxes = Mailbox::query()->where('active', true)->whereNotNull('imap_config')->get();
        if ($mailboxes->count() !== 1) {
            $this->error('Pass --mailbox=ID: there are '.$mailboxes->count().' active IMAP mailboxes.');

            return null;
        }

        return $mailboxes->first();
    }
}
