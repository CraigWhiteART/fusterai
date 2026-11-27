<?php

namespace App\Domains\Conversation\Support;

use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Customer\Models\Customer;
use App\Enums\ConversationStatus;
use App\Enums\ThreadType;
use App\Models\User;
use App\Support\PlainText;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The same customer's other conversations in the workspace: customers often
 * write several times about one thing (contact form, a direct email, a reply
 * to an order email), and every Gmail thread is its own conversation.
 *
 * "Same customer" = the same customer row, or another customer row in the
 * workspace with the same email once trimmed and lower-cased (the unique
 * index is case-sensitive, and live chat stores the address as typed).
 *
 * Returned: every open / pending conversation, plus closed ones with
 * activity in the last 30 days (so old imported history never shows), open
 * ones first, newest first, capped. Spam and the conversation itself are
 * left out.
 */
class RelatedConversations
{
    public const LIMIT = 8;

    public const RECENT_DAYS = 30;

    /** Statuses that count as "open" (not finished) for the strip, the inbox badge and the auto-send blocker. */
    public const OPEN_STATUSES = [ConversationStatus::Open->value, ConversationStatus::Pending->value];

    /**
     * @param  list<int>|null  $mailboxIds  only these mailboxes (a user's mailbox restriction); null = all
     * @return list<array{
     *     id: int,
     *     subject: string,
     *     status: string,
     *     channel: string|null,
     *     via: string|null,
     *     last_activity_at: string|null,
     *     last_customer_at: string|null,
     *     last_customer_excerpt: string|null,
     *     last_staff_at: string|null,
     *     last_staff_excerpt: string|null,
     *     last_speaker: string|null,
     *     waiting_on_us: bool,
     * }>
     */
    public function for(Conversation $conversation, ?CarbonInterface $now = null, int $excerptChars = 160, ?array $mailboxIds = null): array
    {
        $now ??= Carbon::now();
        $customerIds = $this->customerIds($conversation);
        if ($customerIds === []) {
            return [];
        }

        $conversations = Conversation::query()
            ->where('workspace_id', $conversation->workspace_id)
            ->whereIn('customer_id', $customerIds)
            ->whereKeyNot($conversation->id)
            ->when($mailboxIds !== null, fn ($q) => $q->whereIn('mailbox_id', $mailboxIds))
            ->where(fn ($q) => $q
                ->whereIn('status', self::OPEN_STATUSES)
                // Closed: recent ones only. Imported history is dated by its
                // mail, so closed threads older than this never qualify.
                ->orWhere(fn ($q) => $q
                    ->where('status', ConversationStatus::Closed->value)
                    ->whereRaw('coalesce(last_reply_at, created_at) >= ?', [$now->copy()->subDays(self::RECENT_DAYS)])))
            ->orderByRaw('case when status in (?, ?) then 0 else 1 end', self::OPEN_STATUSES)
            ->orderByRaw('coalesce(last_reply_at, created_at) desc')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['id', 'subject', 'status', 'channel_type', 'last_reply_at', 'created_at']);

        if ($conversations->isEmpty()) {
            return [];
        }

        [$customer, $staff] = $this->lastMessages($conversations->modelKeys(), $now);

        $related = [];
        foreach ($conversations as $other) {
            $lastCustomer = $customer[$other->id] ?? null;
            $lastStaff = $staff[$other->id] ?? null;
            $speaker = match (true) {
                $lastCustomer === null && $lastStaff === null => null,
                $lastStaff === null => 'customer',
                $lastCustomer === null => 'staff',
                default => self::isAfter($lastCustomer, $lastStaff) ? 'customer' : 'staff',
            };
            $open = in_array($other->status->value, self::OPEN_STATUSES, true);

            $related[] = [
                'id' => (int) $other->id,
                'subject' => (string) $other->subject,
                'status' => $other->status->value,
                'channel' => $other->channel_type->value ?? null,
                'via' => $lastCustomer !== null ? $this->via($lastCustomer) : null,
                'last_activity_at' => ($other->last_reply_at ?? $other->created_at)?->toIso8601String(),
                'last_customer_at' => $lastCustomer !== null ? self::at($lastCustomer)->toIso8601String() : null,
                'last_customer_excerpt' => $lastCustomer !== null ? $this->excerpt($lastCustomer, $excerptChars) : null,
                'last_staff_at' => $lastStaff !== null ? self::at($lastStaff)->toIso8601String() : null,
                'last_staff_excerpt' => $lastStaff !== null ? $this->excerpt($lastStaff, $excerptChars) : null,
                'last_speaker' => $speaker,
                // Waiting on us: not finished, and the customer spoke last.
                'waiting_on_us' => $open && $speaker === 'customer',
            ];
        }

        return $related;
    }

    /**
     * How many OTHER open conversations each conversation's customer has, for
     * a page of the inbox in one query. Only conversations with at least one
     * are returned.
     *
     * @param  iterable<int, Conversation>  $conversations  with `customer` loaded
     * @param  list<int>|null  $mailboxIds
     * @return array<int, int> keyed by conversation id
     */
    public function openCounts(iterable $conversations, ?array $mailboxIds = null): array
    {
        $page = [];
        $workspaceId = null;
        foreach ($conversations as $conversation) {
            if ($conversation->customer_id === null) {
                continue;
            }
            $workspaceId ??= (int) $conversation->workspace_id;
            $page[(int) $conversation->id] = [(int) $conversation->customer_id, self::normaliseEmail($conversation->customer?->email)];
        }
        if ($page === []) {
            return [];
        }

        $customerIds = array_values(array_unique(array_column($page, 0)));
        $emails = array_values(array_unique(array_filter(array_column($page, 1))));

        $rows = DB::table('conversations as c')
            ->join('customers as cu', 'cu.id', '=', 'c.customer_id')
            ->where('c.workspace_id', $workspaceId)
            ->whereNull('c.deleted_at')
            ->whereIn('c.status', self::OPEN_STATUSES)
            ->when($mailboxIds !== null, fn ($q) => $q->whereIn('c.mailbox_id', $mailboxIds))
            ->where(fn ($q) => $q
                ->whereIn('c.customer_id', $customerIds)
                ->when($emails !== [], fn ($q) => $q->orWhereIn(DB::raw('lower(trim(cu.email))'), $emails)))
            ->get(['c.id', 'c.customer_id', DB::raw('lower(trim(cu.email)) as email')]);

        $byCustomer = [];
        $byEmail = [];
        foreach ($rows as $row) {
            $byCustomer[(int) $row->customer_id][(int) $row->id] = true;
            if (filled($row->email)) {
                $byEmail[(string) $row->email][(int) $row->id] = true;
            }
        }

        $counts = [];
        foreach ($page as $id => [$customerId, $email]) {
            $others = ($byCustomer[$customerId] ?? []) + ($email !== null ? ($byEmail[$email] ?? []) : []);
            unset($others[$id]);
            if ($others !== []) {
                $counts[$id] = count($others);
            }
        }

        return $counts;
    }

    /**
     * The mailboxes a user may see, as ConversationPolicy::view decides it:
     * null = every mailbox in the workspace.
     *
     * @return list<int>|null
     */
    public static function visibleMailboxIds(User $user): ?array
    {
        if ($user->isSuperAdmin() || $user->isAdmin()) {
            return null;
        }
        $ids = $user->mailboxes()->pluck('mailboxes.id');

        return $ids->isEmpty() ? null : $ids->map(fn ($id) => (int) $id)->values()->all();
    }

    public static function normaliseEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    /**
     * The conversation's customer plus any other customer row with the same
     * normalised email.
     *
     * @return list<int>
     */
    private function customerIds(Conversation $conversation): array
    {
        if ($conversation->customer_id === null) {
            return [];
        }

        $ids = [(int) $conversation->customer_id];
        $conversation->loadMissing('customer');
        $email = self::normaliseEmail($conversation->customer?->email);
        if ($email !== null) {
            $same = Customer::query()
                ->where('workspace_id', $conversation->workspace_id)
                ->whereRaw('lower(trim(email)) = ?', [$email])
                ->pluck('id');
            foreach ($same as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The latest customer message and the latest staff reply that was really
     * sent (no notes, no AI drafts, no reply still scheduled or inside its
     * auto-send undo window) per conversation, in one query.
     *
     * @param  array<int, int|string>  $conversationIds
     * @return array{0: array<int, Thread>, 1: array<int, Thread>} [customer, staff], each keyed by conversation id
     */
    private function lastMessages(array $conversationIds, CarbonInterface $now): array
    {
        $message = ThreadType::Message->value;
        $latest = 'threads.id = (select t.id from threads t where t.conversation_id = threads.conversation_id and t.type = ? and %s order by coalesce(t.sent_at, t.created_at) desc, t.id desc limit 1)';

        $threads = Thread::query()
            ->with(['mailMessage:id,from_email,reply_to', 'customer:id,email'])
            ->whereIn('conversation_id', $conversationIds)
            ->where('type', $message)
            ->where(fn ($q) => $q
                ->whereRaw(sprintf($latest, 't.customer_id is not null'), [$message])
                ->orWhereRaw(sprintf($latest, 't.customer_id is null and (t.send_at is null or t.send_at <= ?)'), [$message, $now]))
            ->get(['id', 'conversation_id', 'customer_id', 'body', 'body_plain', 'sent_at', 'created_at', 'mail_message_id']);

        $customer = [];
        $staff = [];
        foreach ($threads as $thread) {
            if ($thread->customer_id !== null) {
                $customer[(int) $thread->conversation_id] = $thread;
            } else {
                $staff[(int) $thread->conversation_id] = $thread;
            }
        }

        return [$customer, $staff];
    }

    private static function at(Thread $thread): CarbonInterface
    {
        return $thread->sent_at ?? $thread->created_at ?? Carbon::now();
    }

    /** Same order as the query: by time, then id. */
    private static function isAfter(Thread $a, Thread $b): bool
    {
        $at = self::at($a);
        $bt = self::at($b);

        return $at->equalTo($bt) ? $a->id > $b->id : $at->greaterThan($bt);
    }

    private function excerpt(Thread $thread, int $chars): string
    {
        $text = trim((string) $thread->body_plain);
        if ($text === '' || $text !== strip_tags($text)) {
            $text = PlainText::from($thread->body);
        }
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? $text);

        return Str::limit($text, max(20, $chars), '…');
    }

    /** "contact_form" when the customer was taken from Reply-To (the mail itself came from a relay); otherwise unknown. */
    private function via(Thread $thread): ?string
    {
        $mail = $thread->mailMessage;
        $customer = self::normaliseEmail($thread->customer?->email);
        if ($mail === null || $customer === null || self::normaliseEmail($mail->from_email) === $customer) {
            return null;
        }
        foreach ($mail->reply_to ?? [] as $address) {
            if (self::normaliseEmail(is_array($address) ? ($address['email'] ?? null) : null) === $customer) {
                return 'contact_form';
            }
        }

        return null;
    }
}
