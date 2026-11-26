<?php

namespace App\Domains\Agent\Qa;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\FeedbackEvent;
use App\Domains\Agent\Pipeline\TranscriptBuilder;
use App\Domains\Agent\Pipeline\TranscriptTurn;
use App\Domains\Agent\Qa\Models\QaReview;
use App\Domains\Commerce\Models\ShopifySnapshot;
use App\Domains\Commerce\Models\TrackingSnapshot;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Domains\Mailbox\Sync\Enums\MailClassification;
use App\Enums\ConversationStatus;
use App\Enums\ThreadType;

/**
 * What a QA review looks at, read from the database only (never Shopify, a
 * carrier or the mailbox): the staff reply being judged (the newest one that
 * answers a customer message), who wrote it, the run that drafted it, the
 * transcript, and the evidence: the run's recorded evidence ledger when an
 * agent run exists, otherwise stored commerce snapshots of the time.
 *
 * Skip reasons: imported, spam, not_closed, no_staff_reply, not_support,
 * auto_closed_without_reply.
 */
class QaCaseBuilder
{
    private const SNAPSHOT_LOOKBACK_DAYS = 7;

    public function __construct(private readonly TranscriptBuilder $transcripts) {}

    /**
     * @return array{skip: string|null, reviewed?: Thread, customer?: Thread, author_type?: string, author_user_id?: int|null,
     *   run?: AgentRun|null, before?: list<TranscriptTurn>, reply?: TranscriptTurn, after?: list<TranscriptTurn>,
     *   evidence_source?: string, evidence?: string|null, draft?: string|null}
     */
    public function build(Conversation $conversation, bool $requireClosed = true, ?int $reviewedThreadId = null): array
    {
        if ($conversation->imported) {
            return ['skip' => 'imported'];
        }
        $conversation->loadMissing('customer');
        if ($conversation->trashed() || $conversation->status === ConversationStatus::Spam || (bool) ($conversation->customer->is_blocked ?? false)) {
            return ['skip' => 'spam'];
        }
        if ($requireClosed && $conversation->status !== ConversationStatus::Closed) {
            return ['skip' => 'not_closed'];
        }

        $turns = $this->transcripts->fromConversation($conversation)->turns;
        $replyIndex = null;
        foreach ($turns as $i => $turn) {
            if ($turn->isCustomer() || trim($turn->text) === '' || $turn->threadId === null) {
                continue;
            }
            $hasCustomerBefore = false;
            for ($j = $i - 1; $j >= 0; $j--) {
                if ($turns[$j]->isCustomer()) {
                    $hasCustomerBefore = true;
                    break;
                }
            }
            if ($hasCustomerBefore && ($reviewedThreadId === null || $turn->threadId === $reviewedThreadId)) {
                $replyIndex = $i;
            }
        }
        if ($replyIndex === null) {
            return ['skip' => 'no_staff_reply'];
        }

        $reply = $turns[$replyIndex];
        $customerTurn = null;
        for ($j = $replyIndex - 1; $j >= 0; $j--) {
            if ($turns[$j]->isCustomer()) {
                $customerTurn = $turns[$j];
                break;
            }
        }
        $reviewed = Thread::find($reply->threadId);
        $customer = $customerTurn !== null ? Thread::with('mailMessage')->find($customerTurn->threadId) : null;
        if ($reviewed === null || $customer === null) {
            return ['skip' => 'no_staff_reply'];
        }

        $base = ['reviewed' => $reviewed, 'customer' => $customer];
        $class = $customer->mailMessage?->classification;
        if ($customerTurn->autoSubmitted || in_array($class, [MailClassification::Bulk, MailClassification::Notification, MailClassification::Auto], true)) {
            return ['skip' => 'not_support', ...$base];
        }

        $after = array_slice($turns, $replyIndex + 1);
        if ($requireClosed && $reviewedThreadId === null && $this->closedWithoutReply($conversation, $after)) {
            return ['skip' => 'auto_closed_without_reply', ...$base];
        }

        [$authorType, $run, $draft] = $this->author($reviewed, $customer);
        [$evidenceSource, $evidence] = $this->evidence($conversation, $run, $customerTurn, $reply);

        return [
            'skip' => null,
            ...$base,
            'author_type' => $authorType,
            'author_user_id' => $reviewed->user_id,
            'run' => $run,
            'before' => array_slice($turns, 0, $replyIndex),
            'reply' => $reply,
            'after' => $after,
            'evidence_source' => $evidenceSource,
            'evidence' => $evidence,
            'draft' => $draft,
        ];
    }

    /**
     * A customer message after the reviewed reply that nobody answered or
     * closed by hand: the conversation was closed by automation / the
     * system, not resolved by a person.
     *
     * @param  list<TranscriptTurn>  $after
     */
    private function closedWithoutReply(Conversation $conversation, array $after): bool
    {
        $lastCustomer = null;
        foreach ($after as $turn) {
            if ($turn->isCustomer()) {
                $lastCustomer = $turn;
            }
        }
        if ($lastCustomer === null) {
            return false;
        }

        return ! Thread::where('conversation_id', $conversation->id)
            ->where('type', ThreadType::Activity->value)
            ->whereNotNull('user_id')
            ->where('created_at', '>=', $lastCustomer->at)
            ->exists();
    }

    /** @return array{0: string, 1: AgentRun|null, 2: string|null} */
    private function author(Thread $reviewed, Thread $customer): array
    {
        $event = FeedbackEvent::with('run')->where('thread_id', $reviewed->id)->first();
        $run = $event?->run;
        $run ??= AgentRun::where('customer_thread_id', $customer->id)
            ->whereIn('purpose', [AgentRun::PURPOSE_LIVE, AgentRun::PURPOSE_SHADOW])
            ->where('status', AgentRun::STATUS_COMPLETED)
            ->latest('id')->first();

        $type = match (true) {
            $reviewed->origin === Thread::ORIGIN_AI => QaReview::AUTHOR_AI,
            $event?->kind === FeedbackEvent::KIND_SENT_AS_IS => QaReview::AUTHOR_AI,
            $event?->kind === FeedbackEvent::KIND_SENT_EDITED => QaReview::AUTHOR_AI_EDITED,
            default => QaReview::AUTHOR_HUMAN,
        };

        return [$type, $run, $type === QaReview::AUTHOR_AI_EDITED ? ($event->draft_before ?? $run?->draft) : null];
    }

    /** @return array{0: string, 1: string|null} */
    private function evidence(Conversation $conversation, ?AgentRun $run, TranscriptTurn $customer, TranscriptTurn $reply): array
    {
        $facts = (array) ($run->evidence['facts'] ?? []);
        if ($run !== null && $facts !== []) {
            $lines = [];
            foreach (array_slice($facts, 0, 60) as $fact) {
                $lines[] = '['.($fact['id'] ?? '').'] '.($fact['source'] ?? '').' / '.($fact['label'] ?? $fact['key'] ?? '').': '.mb_substr((string) ($fact['value'] ?? ''), 0, 300);
            }
            foreach (array_slice((array) ($run->evidence['notes'] ?? []), 0, 10) as $note) {
                $lines[] = 'note: '.mb_substr((string) $note, 0, 300);
            }
            foreach (array_slice((array) ($run->evidence['knowledge'] ?? []), 0, 8) as $item) {
                $lines[] = '['.($item['id'] ?? '').'] knowledge: '.mb_substr((string) ($item['title'] ?? ''), 0, 100).' - '.mb_substr((string) ($item['body'] ?? ''), 0, 400);
            }

            return ['run_ledger', implode("\n", $lines)];
        }

        $from = $customer->at->subDays(self::SNAPSHOT_LOOKBACK_DAYS);
        $to = $reply->at->addHour();
        $shopify = ShopifySnapshot::where('conversation_id', $conversation->id)->whereNull('error')
            ->whereBetween('fetched_at', [$from, $to])->latest('fetched_at')->first();
        $tracking = TrackingSnapshot::where('conversation_id', $conversation->id)->whereNull('error')
            ->whereBetween('fetched_at', [$from, $to])->latest('fetched_at')->first();
        if ($shopify === null && $tracking === null) {
            return ['none', null];
        }

        $parts = [];
        if ($shopify !== null) {
            $parts[] = 'Shopify order as fetched '.$shopify->fetched_at?->toIso8601String().":\n".mb_substr((string) json_encode($shopify->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 3500);
        }
        if ($tracking !== null) {
            $parts[] = 'Carrier tracking as fetched '.$tracking->fetched_at?->toIso8601String().":\n".mb_substr((string) json_encode($tracking->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 2500);
        }

        return ['recorded_snapshot', implode("\n\n", $parts)];
    }
}
