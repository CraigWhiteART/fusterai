<?php

use App\Domains\Conversation\Jobs\SendReplyJob;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Sync\Contracts\InboxArchiver;
use App\Domains\Mailbox\Sync\Imap\ImapInboxArchiver;
use App\Domains\Mailbox\Sync\Jobs\ArchiveConversationMailJob;
use App\Domains\Mailbox\Sync\Models\MailMessage;
use App\Enums\ConversationStatus;
use App\Services\ConversationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\Response;

/*
 * Closed or replied to in fusterai = marked read and archived in Gmail.
 */

/** Records what would have been archived, and archives all of it. */
function awdFakeArchiver(): object
{
    $fake = new class implements InboxArchiver
    {
        /** @var list<list<int>> */
        public array $calls = [];

        public function archive(Mailbox $mailbox, iterable $messages): array
        {
            $ids = [];
            foreach ($messages as $m) {
                $ids[] = $m->id;
            }
            sort($ids);
            $this->calls[] = $ids;

            return $ids;
        }
    };
    app()->instance(InboxArchiver::class, $fake);

    return $fake;
}

function awdRow(Conversation $conversation, int $uid, array $labels = ['\\Inbox'], array $overrides = []): MailMessage
{
    return MailMessage::create(array_merge([
        'mailbox_id' => $conversation->mailbox_id,
        'conversation_id' => $conversation->id,
        'gm_msgid' => (string) (1900000000000000000 + $uid),
        'folder' => '[Gmail]/All Mail',
        'uidvalidity' => 7,
        'uid' => $uid,
        'direction' => 'inbound',
        'labels' => $labels,
    ], $overrides));
}

beforeEach(function () {
    $this->conversation = Conversation::factory()->create();
    $this->actor = agentUser($this->conversation->workspace);
});

test('closing a conversation archives its mail that is in the Inbox, and nothing else', function () {
    $archiver = awdFakeArchiver();
    $inbox = awdRow($this->conversation, 101);
    awdRow($this->conversation, 102, []); // already archived
    awdRow($this->conversation, 103, ['\\Sent'], ['direction' => 'outbound']);
    $other = Conversation::factory()->create(['mailbox_id' => $this->conversation->mailbox_id, 'workspace_id' => $this->conversation->workspace_id]);
    awdRow($other, 104);

    app(ConversationService::class)->changeStatus($this->conversation, ConversationStatus::Closed, $this->actor);

    expect($archiver->calls)->toBe([[$inbox->id]])
        ->and($inbox->refresh()->labels)->toBe([]);
});

test('only a change to closed archives', function () {
    $archiver = awdFakeArchiver();
    awdRow($this->conversation, 101);

    app(ConversationService::class)->changeStatus($this->conversation, ConversationStatus::Pending, $this->actor);
    $this->conversation->update(['subject' => 'Renamed']);
    expect($archiver->calls)->toBe([]);

    $this->conversation->update(['status' => ConversationStatus::Closed]);
    $this->conversation->update(['subject' => 'Renamed again']);
    expect($archiver->calls)->toHaveCount(1);
});

test('the Inbox check closing a conversation does not write back to Gmail', function () {
    $archiver = awdFakeArchiver();
    awdRow($this->conversation, 101, []);

    // InboxReconciler closes with a plain query update.
    DB::table('conversations')->where('id', $this->conversation->id)->update(['status' => 'closed']);

    expect($archiver->calls)->toBe([]);
});

test('a sent reply archives the mail it answered, not mail that came in after it was written', function () {
    $archiver = awdFakeArchiver();
    $answered = awdRow($this->conversation, 101);
    $answered->forceFill(['created_at' => now()->subMinutes(5)])->save();
    $thread = Thread::factory()->create(['conversation_id' => $this->conversation->id, 'user_id' => $this->actor->id]);
    $thread->forceFill(['created_at' => now()->subMinute()])->save();
    awdRow($this->conversation, 102); // arrived while the reply was queued

    Mail::fake();
    (new SendReplyJob($thread, $this->conversation))->handle();

    expect($archiver->calls)->toBe([[$answered->id]])
        ->and($this->conversation->status)->toBe(ConversationStatus::Open);
});

test('archive_when_done off: nothing is queued', function () {
    config(['mailsync.archive_when_done' => false]);
    Queue::fake();
    awdRow($this->conversation, 101);

    $this->conversation->update(['status' => ConversationStatus::Closed]);

    Queue::assertNotPushed(ArchiveConversationMailJob::class);
});

// ── ImapInboxArchiver over the wire ──────────────────────────────────────────

/** @param list<string> $script replies, "{TAG}" replaced by the command's tag */
function awdImap(array $script): ImapProtocol
{
    return new class($script) extends ImapProtocol
    {
        /** @var list<string> */
        public array $commands = [];

        public function __construct(private array $script)
        {
            parent::__construct(Config::make());
            $this->stream = fopen('php://memory', 'r+');
        }

        public function write(Response $response, string $data): void
        {
            $this->commands[] = rtrim($data);
            $tag = explode(' ', $data, 2)[0];
            $reply = array_shift($this->script) ?? "* BYE\r\n{TAG} OK bye\r\n";
            $pos = ftell($this->stream);
            fseek($this->stream, 0, SEEK_END);
            fwrite($this->stream, str_replace('{TAG}', $tag, $reply));
            fseek($this->stream, $pos);
        }
    };
}

const AWD_GMAIL_CAPS = "* CAPABILITY IMAP4rev1 X-GM-EXT-1 UIDPLUS MOVE\r\n{TAG} OK Thats all she wrote!\r\n";

test('marks read and removes \\Inbox in the ledger folder, skipping stale UIDs, then logs out', function () {
    $fresh = awdRow($this->conversation, 101);
    $fresh2 = awdRow($this->conversation, 105);
    $stale = awdRow($this->conversation, 9, overrides: ['uidvalidity' => 6]);

    $protocol = awdImap([
        AWD_GMAIL_CAPS,
        "* 40 EXISTS\r\n* OK [UIDVALIDITY 7] UIDs valid.\r\n{TAG} OK [READ-WRITE] [Gmail]/All Mail selected. (Success)\r\n",
        "{TAG} OK Success\r\n",
        "* 1 FETCH (X-GM-LABELS () UID 101)\r\n* 3 FETCH (X-GM-LABELS () UID 105)\r\n{TAG} OK Success\r\n",
    ]);

    $done = (new ImapInboxArchiver(protocol: $protocol))->archive($this->conversation->mailbox, [$fresh, $stale, $fresh2]);

    expect($done)->toBe([$fresh->id, $fresh2->id])
        ->and(array_slice($protocol->commands, 0, 4))->toBe([
            'TAG1 CAPABILITY',
            'TAG2 SELECT "[Gmail]/All Mail"',
            'TAG3 UID STORE 101,105 +FLAGS.SILENT (\\Seen)',
            'TAG4 UID STORE 101,105 -X-GM-LABELS (\\Inbox)',
        ])
        ->and($protocol->commands[4])->toContain('LOGOUT');

    foreach ($protocol->commands as $command) {
        expect($command)->not->toMatch('/\b(CLOSE|EXPUNGE|COPY|MOVE)\b/');
    }
});

test('changes nothing on a server without the Gmail extensions', function () {
    $protocol = awdImap(["* CAPABILITY IMAP4rev1\r\n{TAG} OK done\r\n"]);

    $done = (new ImapInboxArchiver(protocol: $protocol))->archive($this->conversation->mailbox, [awdRow($this->conversation, 101)]);

    expect($done)->toBe([])
        ->and($protocol->commands[0])->toBe('TAG1 CAPABILITY')
        ->and($protocol->commands[1])->toContain('LOGOUT');
});
