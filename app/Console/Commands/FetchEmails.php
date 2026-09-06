<?php

namespace App\Console\Commands;

use App\Domains\Conversation\Jobs\ProcessInboundEmailJob;
use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Support\ImapInboundMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\IMAP;

class FetchEmails extends Command
{
    protected $signature = 'emails:fetch
                            {--mailbox=* : Specific mailbox IDs to fetch}
                            {--limit=15 : Max unseen messages to process per mailbox}';

    protected $description = 'Fetch new emails from all active IMAP mailboxes';

    private const MAX_MESSAGE_BYTES = 8_388_608;

    public function handle(): int
    {
        ini_set('memory_limit', '512M');

        $query = Mailbox::where('active', true)->whereNotNull('imap_config');

        if ($ids = $this->option('mailbox')) {
            $query->whereIn('id', $ids);
        }

        $mailboxes = $query->get();

        if ($mailboxes->isEmpty()) {
            $this->info('No active IMAP mailboxes found.');

            return 0;
        }

        foreach ($mailboxes as $mailbox) {
            $this->fetchForMailbox($mailbox);
        }

        return 0;
    }

    private function fetchForMailbox(Mailbox $mailbox): void
    {
        $config = $mailbox->imap_config;
        if (! $config || empty($config['host']) || empty($config['username'])) {
            $this->warn("  Skipping {$mailbox->email}: IMAP host/username missing.");
            Log::warning('FetchEmails skipped mailbox with incomplete IMAP config', [
                'mailbox_id' => $mailbox->id,
            ]);

            return;
        }

        $this->info("Fetching: {$mailbox->name} <{$mailbox->email}>");

        try {
            $cm = new ClientManager;

            $client = $cm->make([
                'host' => $config['host'],
                'port' => $config['port'] ?? 993,
                'encryption' => $config['encryption'] ?? 'ssl',
                'validate_cert' => $config['validate_cert'] ?? true,
                'username' => $config['username'],
                'password' => $this->normalizePassword((string) ($config['password'] ?? '')),
                'protocol' => 'imap',
            ]);

            $client->connect();

            $folder = $client->getFolder('INBOX');
            $uids = $folder->query()->unseen()->search();
            $limit = max(1, (int) $this->option('limit'));
            $batch = $uids->reverse()->take($limit)->values();

            $this->info("  {$uids->count()} unseen; processing {$batch->count()} (newest first).");

            foreach ($batch as $uid) {
                $this->processUid($client, $mailbox, (int) $uid);
            }

            $client->disconnect();
        } catch (\Throwable $e) {
            $this->error("  Error fetching {$mailbox->email}: ".$e->getMessage());
            Log::error('FetchEmails failed', [
                'mailbox_id' => $mailbox->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function processUid(Client $client, Mailbox $mailbox, int $uid): void
    {
        try {
            $size = $this->rfc822Size($client, $uid);
            if ($size > self::MAX_MESSAGE_BYTES) {
                $this->warn("  Skipping UID {$uid}: {$size} bytes exceeds limit.");
                $this->markSeen($client, $uid);
                Log::warning('FetchEmails skipped oversized message', [
                    'mailbox_id' => $mailbox->id,
                    'uid' => $uid,
                    'size' => $size,
                ]);

                return;
            }

            $message = $client->getFolder('INBOX')->query()->getMessageByUid($uid);

            ProcessInboundEmailJob::dispatch(
                $mailbox->id,
                ImapInboundMessage::toPayload($message),
            )->onQueue('email-inbound');

            $message->setFlag('Seen');
        } catch (\Throwable $e) {
            $this->error("  UID {$uid}: ".$e->getMessage());
            Log::error('FetchEmails message failed', [
                'mailbox_id' => $mailbox->id,
                'uid' => $uid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function rfc822Size(Client $client, int $uid): int
    {
        $raw = $client->getConnection()->sizes($uid)->validatedData();

        if (is_numeric($raw)) {
            return (int) $raw;
        }

        if (! is_array($raw)) {
            return 0;
        }

        $entry = $raw[$uid] ?? $raw[(string) $uid] ?? $raw[0] ?? $raw;

        if (is_numeric($entry)) {
            return (int) $entry;
        }

        if (is_array($entry)) {
            foreach (['RFC822.SIZE', 'rfc822.size', 'SIZE', 'size'] as $key) {
                if (isset($entry[$key]) && is_numeric($entry[$key])) {
                    return (int) $entry[$key];
                }
            }
        }

        return 0;
    }

    private function markSeen(Client $client, int $uid): void
    {
        $client->getConnection()->store(['\\Seen'], $uid, $uid, '+', true, IMAP::ST_UID);
    }

    private function normalizePassword(string $password): string
    {
        $stripped = str_replace(' ', '', $password);

        // Gmail app passwords are 16 letters, often copied with spaces.
        if (preg_match('/^[a-z]{16}$/i', $stripped)) {
            return $stripped;
        }

        return $password;
    }
}
