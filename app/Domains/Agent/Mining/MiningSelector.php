<?php

namespace App\Domains\Agent\Mining;

use App\Enums\ConversationStatus;
use App\Enums\ThreadType;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Stage A: which conversations a run learns from.
 *
 * Eligible: the workspace's closed conversations (imported history and live
 * ones alike, not deleted) whose last activity is inside the window, with at
 * least one customer message and at least one human-written staff reply. A
 * staff reply is not human-written when the AI sent it (origin ai) or the
 * human sent the AI draft unchanged (feedback sent_as_is); the per-turn check
 * in DigestBuilder also catches accepted legacy suggestions.
 *
 * Over the cap, "stratified" splits the window into calendar months: a floor
 * share of the cap is spread evenly (every period is represented), the rest
 * goes by month size weighted towards recent months. Inside a month the
 * order is a stable pseudo-random one, so the same options select the same
 * conversations again and a re-run reuses its extractions. "newest" takes
 * the newest conversations.
 */
class MiningSelector
{
    public const STRATEGIES = ['stratified', 'newest'];

    /**
     * @return list<array{id: int, last_at: string, bucket: string}>
     */
    public function select(int $workspaceId, CarbonInterface $since, CarbonInterface $until, int $max, string $strategy = 'stratified'): array
    {
        $rows = $this->eligible($workspaceId, $since, $until)
            ->orderByDesc('last_at')
            ->orderByDesc('c.id')
            ->get(['c.id', DB::raw('coalesce(c.last_reply_at, c.created_at) as last_at')]);

        $candidates = [];
        foreach ($rows as $row) {
            $at = Carbon::parse((string) $row->last_at);
            $candidates[] = ['id' => (int) $row->id, 'last_at' => $at->toDateTimeString(), 'bucket' => $at->format('Y-m')];
        }

        if (count($candidates) <= $max) {
            return $candidates;
        }

        if ($strategy === 'newest') {
            return array_slice($candidates, 0, $max);
        }

        return $this->stratify($candidates, $max, $workspaceId);
    }

    public function countEligible(int $workspaceId, CarbonInterface $since, CarbonInterface $until): int
    {
        return $this->eligible($workspaceId, $since, $until)->count();
    }

    /**
     * @param  list<array{id: int, last_at: string, bucket: string}>  $candidates  newest first
     * @return list<array{id: int, last_at: string, bucket: string}>
     */
    public function stratify(array $candidates, int $max, int $seed = 0): array
    {
        $buckets = [];
        foreach ($candidates as $c) {
            $buckets[$c['bucket']][] = $c;
        }
        ksort($buckets); // oldest month first

        $keys = array_keys($buckets);
        $n = count($keys);
        $boost = max(0.0, (float) config('agent_mining.selection.recency_boost', 1.0));
        $floorShare = min(1.0, max(0.0, (float) config('agent_mining.selection.floor_share', 0.3)));

        $weights = [];
        $sizes = [];
        foreach ($keys as $i => $key) {
            $weights[$key] = 1 + ($n > 1 ? $boost * $i / ($n - 1) : 0);
            $sizes[$key] = count($buckets[$key]);
        }

        // Even floor per month first, then the rest by size x weight (water-filling).
        $floor = intdiv((int) floor($max * $floorShare), max(1, $n));
        $alloc = [];
        foreach ($keys as $key) {
            $alloc[$key] = min($sizes[$key], $floor);
        }
        $remaining = $max - array_sum($alloc);

        for ($round = 0; $remaining > 0 && $round < 20; $round++) {
            $total = 0.0;
            foreach ($keys as $key) {
                $total += $weights[$key] * ($sizes[$key] - $alloc[$key]);
            }
            if ($total <= 0) {
                break;
            }

            $given = 0;
            foreach ($keys as $key) {
                $spare = $sizes[$key] - $alloc[$key];
                $add = min($spare, (int) floor($remaining * $weights[$key] * $spare / $total));
                $alloc[$key] += $add;
                $given += $add;
            }
            $remaining -= $given;

            if ($given === 0) {
                // Rounding left a few: newest months first.
                foreach (array_reverse($keys) as $key) {
                    if ($remaining === 0) {
                        break;
                    }
                    if ($alloc[$key] < $sizes[$key]) {
                        $alloc[$key]++;
                        $remaining--;
                    }
                }
            }
        }

        $picked = [];
        foreach ($keys as $key) {
            $rows = $buckets[$key];
            usort($rows, fn ($a, $b) => [crc32($seed.':'.$a['id']), $a['id']] <=> [crc32($seed.':'.$b['id']), $b['id']]);
            foreach (array_slice($rows, 0, $alloc[$key]) as $row) {
                $picked[] = $row;
            }
        }

        usort($picked, fn ($a, $b) => [$b['last_at'], $b['id']] <=> [$a['last_at'], $a['id']]);

        return $picked;
    }

    private function eligible(int $workspaceId, CarbonInterface $since, CarbonInterface $until): Builder
    {
        $message = ThreadType::Message->value;

        return DB::table('conversations as c')
            ->where('c.workspace_id', $workspaceId)
            ->whereNull('c.deleted_at')
            ->where('c.status', ConversationStatus::Closed->value)
            ->whereRaw('coalesce(c.last_reply_at, c.created_at) >= ?', [$since])
            ->whereRaw('coalesce(c.last_reply_at, c.created_at) <= ?', [$until])
            ->whereExists(fn (Builder $q) => $q->select(DB::raw(1))->from('threads as tc')
                ->whereColumn('tc.conversation_id', 'c.id')
                ->where('tc.type', $message)
                ->whereNotNull('tc.customer_id'))
            ->whereExists(fn (Builder $q) => $q->select(DB::raw(1))->from('threads as ts')
                ->whereColumn('ts.conversation_id', 'c.id')
                ->where('ts.type', $message)
                ->whereNull('ts.customer_id')
                ->where(fn (Builder $o) => $o->whereNull('ts.origin')->orWhere('ts.origin', '!=', 'ai'))
                ->whereNotExists(fn (Builder $f) => $f->select(DB::raw(1))->from('agent_feedback_events as f')
                    ->whereColumn('f.thread_id', 'ts.id')
                    ->where('f.kind', 'sent_as_is')));
    }
}
