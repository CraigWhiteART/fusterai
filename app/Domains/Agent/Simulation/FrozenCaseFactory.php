<?php

namespace App\Domains\Agent\Simulation;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\FrozenCase;
use App\Domains\Agent\Pipeline\TranscriptBuilder;
use App\Domains\Agent\Pipeline\TranscriptTurn;
use App\Domains\Commerce\Models\ShopifySnapshot;
use App\Domains\Commerce\Models\TrackingSnapshot;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Freezes a conversation at a customer message: the transcript up to and
 * including that message, "now" = when it was sent, the human's actual
 * reply (the first staff reply after it), and the evidence as it stood.
 *
 * Evidence here is only what was recorded at the time (quality
 * `recorded`): the Shopify / carrier snapshot the live run used for that
 * message, else a snapshot of this conversation fetched between 7 days
 * before the cut and the human's reply (what the human could see). Nothing
 * here calls Shopify or a carrier; `EvidenceReconstructor` (the separate,
 * rate-limited "prepare" step) fills `reconstructed` evidence later.
 *
 * Idempotent: the same conversation + cut + content returns the same row.
 */
class FrozenCaseFactory
{
    private const RECORDED_LOOKBACK_DAYS = 7;

    private const RECORDED_WINDOW_HOURS = 6;

    public function __construct(
        private readonly TranscriptBuilder $transcripts,
    ) {}

    /**
     * @param  Thread  $cutThread  A customer message of this conversation (the last message the agent sees)
     * @param  string  $source  feedback | mining | manual | qa
     * @param  array<string, mixed>  $meta  Free-form provenance stored on the case
     *
     * @throws InvalidArgumentException when the thread is not a customer message of this conversation
     */
    public function fromConversation(Conversation $conversation, Thread $cutThread, string $source = 'manual', array $meta = []): FrozenCase
    {
        if ((int) $cutThread->conversation_id !== (int) $conversation->id || $cutThread->customer_id === null) {
            throw new InvalidArgumentException('The cut must be a customer message of this conversation.');
        }
        if (! in_array($source, FrozenCase::SOURCES, true)) {
            throw new InvalidArgumentException("Unknown frozen case source [{$source}].");
        }

        $conversation->loadMissing('customer');
        $turns = $this->transcripts->fromConversation($conversation)->turns;
        $cutIndex = null;
        foreach ($turns as $i => $turn) {
            if ($turn->threadId === $cutThread->id) {
                $cutIndex = $i;
            }
        }
        if ($cutIndex === null) {
            throw new InvalidArgumentException('The cut message has no text the agent could read.');
        }

        $kept = array_slice($turns, 0, $cutIndex + 1);
        $cutAt = $turns[$cutIndex]->at;
        $human = $this->humanReplyAfter($turns, $cutIndex);
        $humanThread = $human !== null && $human->threadId !== null ? Thread::find($human->threadId) : null;

        $transcript = array_map(fn (TranscriptTurn $t) => $t->toArray(), $kept);
        $hash = hash('sha256', (string) json_encode([
            $conversation->id,
            $cutThread->id,
            array_map(fn ($t) => [$t['role'], $t['text'], $t['at'], $t['thread_id']], $transcript),
            $human?->text,
        ]));

        $existing = FrozenCase::where('workspace_id', $conversation->workspace_id)->where('content_hash', $hash)->first();
        if ($existing !== null) {
            if ($existing->evidence_quality !== FrozenCase::QUALITY_RECORDED) {
                $recorded = $this->recordedEvidence($conversation, $cutThread, $cutAt, $human?->at);
                if ($recorded !== null) {
                    $existing->forceFill($this->evidenceAttributes($recorded, FrozenCase::QUALITY_RECORDED))->save();
                }
            }

            return $existing;
        }

        $recorded = $this->recordedEvidence($conversation, $cutThread, $cutAt, $human?->at);

        return FrozenCase::create([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'cut_thread_id' => $cutThread->id,
            'human_thread_id' => $humanThread?->id,
            'subject' => $conversation->subject !== null ? mb_substr((string) $conversation->subject, 0, 255) : null,
            'customer_name' => $conversation->customer?->name,
            'customer_email' => $conversation->customer?->email,
            'reply_to' => $this->replyTo($conversation, $kept),
            'transcript' => $transcript,
            'cut_at' => $cutAt,
            ...($recorded !== null
                ? $this->evidenceAttributes($recorded, FrozenCase::QUALITY_RECORDED)
                : ['evidence' => null, 'evidence_quality' => FrozenCase::QUALITY_NONE, 'evidence_hash' => null]),
            'human_reply' => $human?->text,
            'human_reply_by' => $human?->author,
            'human_reply_user_id' => $humanThread?->user_id,
            'human_reply_at' => $human?->at,
            'human_reply_origin' => $human?->origin,
            'source' => $source,
            'playbook_slug' => $this->playbookSlug($cutThread),
            'meta' => $meta === [] ? null : $meta,
            'content_hash' => $hash,
        ]);
    }

    /**
     * Every point in the conversation where a customer message was answered
     * by a human: one frozen case per (last customer message before a human
     * reply). Newest first, at most $limit.
     *
     * @return list<FrozenCase>
     */
    public function allFromConversation(Conversation $conversation, string $source = 'mining', int $limit = 10): array
    {
        $turns = $this->transcripts->fromConversation($conversation)->turns;
        $cuts = [];
        foreach ($turns as $i => $turn) {
            $next = $turns[$i + 1] ?? null;
            if ($turn->isCustomer() && $turn->threadId !== null && $next !== null && ! $next->isCustomer() && $next->origin !== Thread::ORIGIN_AI) {
                $cuts[] = $turn->threadId;
            }
        }

        $cases = [];
        foreach (array_slice(array_reverse($cuts), 0, max(1, $limit)) as $threadId) {
            $thread = Thread::find($threadId);
            if ($thread !== null) {
                $cases[] = $this->fromConversation($conversation, $thread, $source);
            }
        }

        return $cases;
    }

    /**
     * Store prepared evidence (used by EvidenceReconstructor).
     *
     * @param  array<string, mixed>|null  $evidence
     * @return array<string, mixed>
     */
    public function evidenceAttributes(?array $evidence, string $quality): array
    {
        return [
            'evidence' => $evidence,
            'evidence_quality' => $quality,
            'evidence_hash' => $evidence !== null ? hash('sha256', (string) json_encode($evidence)) : null,
            'evidence_prepared_at' => now(),
            'evidence_error' => null,
        ];
    }

    /** @param  list<TranscriptTurn>  $turns */
    private function humanReplyAfter(array $turns, int $cutIndex): ?TranscriptTurn
    {
        for ($i = $cutIndex + 1; $i < count($turns); $i++) {
            $turn = $turns[$i];
            if ($turn->isCustomer()) {
                continue;
            }
            if ($turn->origin === Thread::ORIGIN_AI || trim($turn->text) === '') {
                continue;
            }

            return $turn;
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function recordedEvidence(Conversation $conversation, Thread $cutThread, CarbonImmutable $cutAt, ?CarbonImmutable $humanAt): ?array
    {
        $run = AgentRun::where('customer_thread_id', $cutThread->id)
            ->whereIn('purpose', [AgentRun::PURPOSE_LIVE, AgentRun::PURPOSE_SHADOW])
            ->where('status', AgentRun::STATUS_COMPLETED)
            ->latest('id')
            ->first();

        $meta = (array) ($run?->evidence['meta'] ?? []);
        $shopify = isset($meta['shopify_snapshot_id']) ? ShopifySnapshot::where('conversation_id', $conversation->id)->find((int) $meta['shopify_snapshot_id']) : null;
        $tracking = isset($meta['tracking_snapshot_id']) ? TrackingSnapshot::where('conversation_id', $conversation->id)->find((int) $meta['tracking_snapshot_id']) : null;

        if ($shopify === null) {
            $windowEnd = $humanAt ?? $cutAt->addHours(self::RECORDED_WINDOW_HOURS);
            $shopify = ShopifySnapshot::where('conversation_id', $conversation->id)
                ->whereNull('error')
                ->whereBetween('fetched_at', [$cutAt->subDays(self::RECORDED_LOOKBACK_DAYS), $windowEnd])
                ->latest('fetched_at')
                ->first();
            $tracking = $shopify === null ? null : TrackingSnapshot::where('conversation_id', $conversation->id)
                ->whereNull('error')
                ->whereBetween('fetched_at', [$cutAt->subDays(self::RECORDED_LOOKBACK_DAYS), $windowEnd])
                ->latest('fetched_at')
                ->first();
        }

        if ($shopify === null) {
            return null;
        }
        // Snapshots from before the order email was recorded cannot pass the
        // sender check, so every replay would withhold the order: leave the
        // case to be reconstructed instead.
        $payload = (array) $shopify->payload;
        if (($payload['found'] ?? false) && ! array_key_exists('order_email', $payload)) {
            return null;
        }

        return [
            'source' => FrozenCase::QUALITY_RECORDED,
            'shopify' => $shopify->payload,
            'tracking' => $tracking?->payload,
            'shopify_snapshot_id' => $shopify->id,
            'tracking_snapshot_id' => $tracking?->id,
            'as_of' => $shopify->fetched_at?->toIso8601String(),
            'notes' => [],
        ];
    }

    /**
     * @param  list<TranscriptTurn>  $turns
     * @return list<string>
     */
    private function replyTo(Conversation $conversation, array $turns): array
    {
        $ids = array_values(array_filter(array_map(fn (TranscriptTurn $t) => $t->isCustomer() ? $t->threadId : null, $turns)));
        if ($ids === []) {
            return [];
        }

        $emails = [];
        $threads = Thread::with('mailMessage')->whereIn('id', $ids)->whereNotNull('mail_message_id')->get();
        foreach ($threads as $thread) {
            foreach ($thread->mailMessage->reply_to ?? [] as $address) {
                if (is_array($address) && filled($address['email'] ?? null)) {
                    $emails[] = mb_strtolower((string) $address['email']);
                }
            }
        }

        return array_values(array_unique($emails));
    }

    private function playbookSlug(Thread $cutThread): ?string
    {
        $run = AgentRun::with('playbook')
            ->where('customer_thread_id', $cutThread->id)
            ->where('status', AgentRun::STATUS_COMPLETED)
            ->whereNotNull('playbook_id')
            ->latest('id')
            ->first();

        return $run?->playbook?->slug;
    }
}
