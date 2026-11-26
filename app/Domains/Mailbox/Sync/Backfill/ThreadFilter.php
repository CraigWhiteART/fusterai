<?php

namespace App\Domains\Mailbox\Sync\Backfill;

/**
 * Decides, from ledger metadata only, whether one mail thread is a real
 * two-way support conversation worth importing. Pure: no database, no IMAP.
 *
 * A row is a plain array (see ThreadGrouper::row()):
 *   id, direction (inbound|outbound|internal), classification
 *   (support|bulk|auto|notification|unknown|null), customer_email, labels,
 *   subject, at (unix time the message was sent), live (the live sync owns
 *   it), ingested (already has an ingest outcome).
 *
 * Rules, in order:
 *  - drafts and chats are ignored (a thread of only those: draft_or_chat);
 *  - the newest message is older than the window: out_of_window;
 *  - every message is between our own addresses: internal;
 *  - no human customer message: bulk / auto_reply / notification (the most
 *    common machine kind), no_customer_reply (we wrote, nobody answered) or
 *    no_customer (no address to attach it to);
 *  - a customer wrote and we never replied: unanswered. Dropped unless
 *    $includeUnanswered, but kept anyway when the customer wrote within the
 *    active window (unanswered_recent): that mail still needs an answer;
 *  - otherwise two_way: kept.
 *
 * A kept thread is "active" when its newest message is the customer's and
 * is inside the active window: it is imported with the live status (open)
 * instead of closed. A kept thread with nothing left to import is skipped
 * (live: only live-sync rows; already_imported: every row already ingested).
 */
final class ThreadFilter
{
    public const KEPT = 'kept';

    public const DROPPED = 'dropped';

    public const SKIPPED = 'skipped';

    public const REASON_TWO_WAY = 'two_way';

    public const REASON_UNANSWERED = 'unanswered';

    public const REASON_UNANSWERED_RECENT = 'unanswered_recent';

    public const REASON_OUT_OF_WINDOW = 'out_of_window';

    public const REASON_DRAFT_OR_CHAT = 'draft_or_chat';

    public const REASON_INTERNAL = 'internal';

    public const REASON_BULK = 'bulk';

    public const REASON_AUTO_REPLY = 'auto_reply';

    public const REASON_NOTIFICATION = 'notification';

    public const REASON_NO_CUSTOMER_REPLY = 'no_customer_reply';

    public const REASON_NO_CUSTOMER = 'no_customer';

    public const REASON_LIVE = 'live';

    public const REASON_ALREADY_IMPORTED = 'already_imported';

    private const IGNORED_LABELS = ['\\draft', '\\chat', '\\chats'];

    public function __construct(
        private readonly int $sinceTs,
        private readonly int $nowTs,
        private readonly bool $includeUnanswered = false,
        private readonly int $activeWindowDays = 14,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows  one thread
     * @return array{decision: string, reason: string, active: bool, first_at: ?int, last_at: ?int, inbound: int, outbound: int, customer_email: ?string, subject: ?string, importable: list<int>}
     */
    public function decide(array $rows): array
    {
        $relevant = array_values(array_filter($rows, fn (array $r) => ! self::isDraftOrChat($r)));
        usort($relevant, fn (array $a, array $b) => [$a['at'] ?? 0, $a['id']] <=> [$b['at'] ?? 0, $b['id']]);

        $human = array_values(array_filter($relevant, fn (array $r) => $r['direction'] === 'inbound'
            && ($r['classification'] ?? 'support') === 'support'
            && ($r['customer_email'] ?? null) !== null));
        $ours = array_values(array_filter($relevant, fn (array $r) => $r['direction'] === 'outbound'
            && ($r['classification'] ?? 'support') === 'support'));

        $first = $relevant[0] ?? null;
        $last = $relevant === [] ? null : $relevant[count($relevant) - 1];

        $out = [
            'decision' => self::DROPPED,
            'reason' => self::REASON_TWO_WAY,
            'active' => false,
            'first_at' => $first['at'] ?? null,
            'last_at' => $last['at'] ?? null,
            'inbound' => count($human),
            'outbound' => count($ours),
            'customer_email' => $human[0]['customer_email'] ?? $ours[0]['customer_email'] ?? $first['customer_email'] ?? null,
            'subject' => $first['subject'] ?? ($rows[0]['subject'] ?? null),
            'importable' => [],
        ];

        if ($relevant === []) {
            return ['reason' => self::REASON_DRAFT_OR_CHAT] + $out;
        }

        if (($last['at'] ?? 0) < $this->sinceTs) {
            return ['reason' => self::REASON_OUT_OF_WINDOW] + $out;
        }

        if (array_filter($relevant, fn (array $r) => $r['direction'] !== 'internal') === []) {
            return ['reason' => self::REASON_INTERNAL] + $out;
        }

        if ($human === []) {
            return ['reason' => $this->machineReason($relevant, $ours)] + $out;
        }

        $customerWroteLast = $this->isHuman($last);
        $recent = ($last['at'] ?? 0) >= $this->nowTs - $this->activeWindowDays * 86_400;
        $active = $customerWroteLast && $recent;

        if ($ours === []) {
            if ($active) {
                $out = ['decision' => self::KEPT, 'reason' => self::REASON_UNANSWERED_RECENT] + $out;
            } elseif ($this->includeUnanswered) {
                $out = ['decision' => self::KEPT, 'reason' => self::REASON_UNANSWERED] + $out;
            } else {
                return ['reason' => self::REASON_UNANSWERED] + $out;
            }
        } else {
            $out = ['decision' => self::KEPT, 'reason' => self::REASON_TWO_WAY] + $out;
        }

        $out['active'] = $active;

        // What the backfill would ingest: not the live sync's rows, not done yet.
        $importable = [];
        foreach ($relevant as $r) {
            if (empty($r['live']) && empty($r['ingested'])) {
                $importable[] = (int) $r['id'];
            }
        }
        $out['importable'] = $importable;

        if ($importable === []) {
            $allLive = array_filter($relevant, fn (array $r) => empty($r['live'])) === [];
            $out['decision'] = self::SKIPPED;
            $out['reason'] = $allLive ? self::REASON_LIVE : self::REASON_ALREADY_IMPORTED;
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    public static function isDraftOrChat(array $row): bool
    {
        foreach ((array) ($row['labels'] ?? []) as $label) {
            if (in_array(strtolower((string) $label), self::IGNORED_LABELS, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed>|null $row */
    private function isHuman(?array $row): bool
    {
        return $row !== null
            && $row['direction'] === 'inbound'
            && ($row['classification'] ?? 'support') === 'support'
            && ($row['customer_email'] ?? null) !== null;
    }

    /**
     * @param  list<array<string, mixed>>  $relevant
     * @param  list<array<string, mixed>>  $ours
     */
    private function machineReason(array $relevant, array $ours): string
    {
        $counts = [self::REASON_BULK => 0, self::REASON_AUTO_REPLY => 0, self::REASON_NOTIFICATION => 0];
        foreach ($relevant as $r) {
            if ($r['direction'] !== 'inbound') {
                continue;
            }
            $reason = match ($r['classification'] ?? 'support') {
                'bulk' => self::REASON_BULK,
                'auto' => self::REASON_AUTO_REPLY,
                'notification', 'unknown' => self::REASON_NOTIFICATION,
                default => null,
            };
            if ($reason !== null) {
                $counts[$reason]++;
            }
        }

        if (max($counts) > 0) {
            arsort($counts); // stable for ties: bulk, auto_reply, notification

            return (string) array_key_first($counts);
        }

        return $ours !== [] ? self::REASON_NO_CUSTOMER_REPLY : self::REASON_NO_CUSTOMER;
    }
}
