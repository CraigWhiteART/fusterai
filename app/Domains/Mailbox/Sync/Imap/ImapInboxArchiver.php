<?php

namespace App\Domains\Mailbox\Sync\Imap;

use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Sync\Contracts\InboxArchiver;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;

/**
 * Gmail "archive": SELECT the ledger folder (All Mail), then per UID set
 *   UID STORE <uids> +FLAGS.SILENT (\Seen)
 *   UID STORE <uids> -X-GM-LABELS (\Inbox)
 * and log out. Never CLOSE (that would expunge anything another client
 * flagged \Deleted), EXPUNGE, COPY or MOVE.
 *
 * Messages whose stored UIDVALIDITY no longer matches the folder are skipped:
 * their UIDs would point at other mail.
 */
final class ImapInboxArchiver implements InboxArchiver
{
    use SpeaksImap;

    private const STORE_CHUNK = 100;

    /** @var array<string, mixed> */
    private array $config = [];

    private readonly ImapWireParser $parser;

    /** @param  ImapProtocol|null  $protocol  an already connected and authenticated protocol (tests) */
    public function __construct(?ImapWireParser $parser = null, private ?ImapProtocol $protocol = null)
    {
        $this->parser = $parser ?? new ImapWireParser;
    }

    public function archive(Mailbox $mailbox, iterable $messages): array
    {
        $byFolder = [];
        foreach ($messages as $message) {
            $byFolder[$message->folder][] = $message;
        }
        if ($byFolder === []) {
            return [];
        }

        if ($this->protocol === null) {
            $this->config = ImapMailSource::configFor($mailbox);
        }

        try {
            $caps = $this->parser->parseCapabilities($this->command('CAPABILITY'));
            if (! in_array(ImapMailSource::GMAIL_CAPABILITY, $caps, true)) {
                return [];
            }

            $done = [];
            foreach ($byFolder as $folder => $rows) {
                $validity = $this->parser->parseExamine($this->command('SELECT '.$this->quote((string) $folder)))['uidvalidity'];

                $uids = [];
                foreach ($rows as $row) {
                    if ($row->uidvalidity === $validity) {
                        $uids[$row->uid] = $row->id;
                    } else {
                        Log::warning('Archive skipped: UIDVALIDITY changed', ['mailbox_id' => $mailbox->id, 'mail_message_id' => $row->id, 'folder' => $folder]);
                    }
                }

                foreach (array_chunk(array_keys($uids), self::STORE_CHUNK) as $chunk) {
                    $set = implode(',', $chunk);
                    $this->command("UID STORE {$set} +FLAGS.SILENT (\\Seen)");
                    $this->command("UID STORE {$set} -X-GM-LABELS (\\Inbox)");
                    foreach ($chunk as $uid) {
                        $done[] = $uids[$uid];
                    }
                }
            }

            return $done;
        } finally {
            $this->close();
        }
    }

    public function close(): void
    {
        if ($this->protocol !== null) {
            try {
                $this->protocol->logout();
            } catch (Throwable) {
                // Already gone.
            }
        }
        $this->protocol = null;
    }
}
