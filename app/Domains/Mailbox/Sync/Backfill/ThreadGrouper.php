<?php

namespace App\Domains\Mailbox\Sync\Backfill;

use App\Domains\Conversation\Support\EmailSubject;
use App\Domains\Mailbox\Sync\Models\MailSyncState;
use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Groups a mailbox's ledger rows into mail threads for ThreadFilter.
 *
 *  - Rows with a Gmail thread id are grouped by it (streamed in gm_thrid
 *    order, one thread in memory at a time).
 *  - Rows without one (non-Gmail servers) are grouped by the
 *    Message-ID / In-Reply-To / References graph, then threads with the same
 *    normalised subject and the same customer are merged (clients that drop
 *    References). That part is held in memory; it only applies to non-Gmail
 *    mailboxes.
 */
final class ThreadGrouper
{
    private const COLUMNS = [
        'id', 'gm_thrid', 'folder', 'uidvalidity', 'uid', 'message_id', 'in_reply_to', 'references',
        'subject', 'customer_email', 'direction', 'classification', 'labels', 'sent_at', 'internal_date', 'ingested_at',
    ];

    /** @var array<string, array{uidvalidity: ?int, live_from_uid: ?int}> */
    private array $live = [];

    /**
     * @return Generator<string, list<array<string, mixed>>> thread key => rows
     */
    public function groups(int $mailboxId): Generator
    {
        $this->live = MailSyncState::query()
            ->where('mailbox_id', $mailboxId)
            ->get(['folder', 'uidvalidity', 'live_from_uid'])
            ->mapWithKeys(fn (MailSyncState $s) => [$s->folder => ['uidvalidity' => $s->uidvalidity, 'live_from_uid' => $s->live_from_uid]])
            ->all();

        $key = null;
        $rows = [];
        $query = DB::table('mail_messages')
            ->where('mailbox_id', $mailboxId)
            ->whereNotNull('gm_thrid')
            ->orderBy('gm_thrid')
            ->orderBy('id')
            ->select(self::COLUMNS);

        foreach ($query->lazy(2000) as $record) {
            $row = $this->row($record);
            $k = 'g:'.$row['gm_thrid'];
            if ($key !== null && $k !== $key) {
                yield $key => $rows;
                $rows = [];
            }
            $key = $k;
            $rows[] = $row;
        }
        if ($key !== null) {
            yield $key => $rows;
        }

        $plain = [];
        foreach (DB::table('mail_messages')->where('mailbox_id', $mailboxId)->whereNull('gm_thrid')->orderBy('id')->select(self::COLUMNS)->lazy(2000) as $record) {
            $plain[] = $this->row($record);
        }

        foreach (self::groupByReferences($plain) as $k => $group) {
            yield $k => $group;
        }
    }

    /**
     * Union-find over the reply graph, then same subject + same customer.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<array<string, mixed>>> "r:{lowest id}" => rows (id order)
     */
    public static function groupByReferences(array $rows): array
    {
        $parent = [];
        $find = function (int $i) use (&$parent): int {
            while ($parent[$i] !== $i) {
                $parent[$i] = $parent[$parent[$i]];
                $i = $parent[$i];
            }

            return $i;
        };
        $union = function (int $a, int $b) use (&$parent, $find): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[max($ra, $rb)] = min($ra, $rb);
            }
        };

        $byMessageId = [];
        foreach ($rows as $i => $row) {
            $parent[$i] = $i;
            if (! empty($row['message_id'])) {
                $byMessageId[(string) $row['message_id']][] = $i;
            }
        }

        foreach ($rows as $i => $row) {
            $refs = (array) ($row['references'] ?? []);
            if (! empty($row['in_reply_to'])) {
                $refs[] = $row['in_reply_to'];
            }
            foreach ($refs as $ref) {
                foreach ($byMessageId[(string) $ref] ?? [] as $j) {
                    $union($i, $j);
                }
            }
            // The same message seen twice (INBOX + Sent copies).
            foreach ($byMessageId[(string) ($row['message_id'] ?? '')] ?? [] as $j) {
                $union($i, $j);
            }
        }

        $bySubject = [];
        foreach ($rows as $i => $row) {
            $subject = strtolower(EmailSubject::normalize($row['subject'] ?? null));
            $customer = strtolower((string) ($row['customer_email'] ?? ''));
            if ($customer === '' || $subject === '(no subject)' || mb_strlen($subject) < 4) {
                continue;
            }
            $k = $customer."\n".$subject;
            if (isset($bySubject[$k])) {
                $union($i, $bySubject[$k]);
            } else {
                $bySubject[$k] = $i;
            }
        }

        $groups = [];
        foreach ($rows as $i => $row) {
            $groups[$find($i)][] = $row;
        }

        $out = [];
        foreach ($groups as $group) {
            usort($group, fn ($a, $b) => $a['id'] <=> $b['id']);
            $out['r:'.$group[0]['id']] = $group;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function row(object $r): array
    {
        $sent = $r->sent_at !== null ? Carbon::parse($r->sent_at) : null;
        $internal = $r->internal_date !== null ? Carbon::parse($r->internal_date) : null;
        $live = $this->live[$r->folder] ?? null;

        return [
            'id' => (int) $r->id,
            'gm_thrid' => $r->gm_thrid !== null ? (string) $r->gm_thrid : null,
            'message_id' => $r->message_id,
            'in_reply_to' => $r->in_reply_to,
            'references' => $r->references !== null ? (array) json_decode((string) $r->references, true) : [],
            'subject' => $r->subject,
            'customer_email' => $r->customer_email,
            'direction' => (string) $r->direction,
            'classification' => $r->classification,
            'labels' => $r->labels !== null ? (array) json_decode((string) $r->labels, true) : [],
            'at' => self::orderTime($sent, $internal)?->getTimestamp(),
            'live' => $live !== null && $live['live_from_uid'] !== null
                && (int) $r->uidvalidity === $live['uidvalidity'] && (int) $r->uid >= $live['live_from_uid'],
            'ingested' => $r->ingested_at !== null,
        ];
    }

    /** Same rule as the ingest: the Date header unless it is more than a day off INTERNALDATE. */
    public static function orderTime(?Carbon $sent, ?Carbon $internal): ?Carbon
    {
        if ($sent !== null && $internal !== null && abs($sent->diffInSeconds($internal)) > 86_400) {
            return $internal;
        }

        return $sent ?? $internal;
    }
}
