<?php

namespace Modules\CommerceAssist\Services;

use App\Domains\AI\Models\KbDocument;
use App\Domains\AI\Models\KnowledgeBase;
use App\Domains\Conversation\Models\Thread;
use App\Enums\ThreadType;
use App\Models\User;
use App\Services\AiSettingsService;
use App\Services\KnowledgeBaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Modules\CommerceAssist\Agents\MineHistoryAgent;
use Modules\CommerceAssist\Jobs\MineSentHistoryJob;
use Modules\CommerceAssist\Models\ApprovedResponse;
use Modules\CommerceAssist\Models\HistoryLearning;
use Modules\CommerceAssist\Models\HistoryScan;
use Modules\CommerceAssist\Models\LiveFact;
use Modules\CommerceAssist\Support\PlainText;
use RuntimeException;
use Throwable;

class HistoryMiner
{
    public const BATCH_SIZE = 5;

    public const DEFAULT_LIMIT = 40;

    public const MIN_REPLY_LENGTH = 40;

    public function __construct(
        private readonly AiSettingsService $aiSettings,
        private readonly ExampleService $examples,
        private readonly LiveFactService $facts,
        private readonly KnowledgeBaseService $knowledge,
    ) {}

    public function activeScan(int $workspaceId): ?HistoryScan
    {
        return HistoryScan::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('status', ['queued', 'running'])
            ->latest()
            ->first();
    }

    public function latestScan(int $workspaceId): ?HistoryScan
    {
        return HistoryScan::query()
            ->where('workspace_id', $workspaceId)
            ->latest()
            ->first();
    }

    /**
     * @return array{
     *     sent_replies: int,
     *     unscanned: int,
     *     pending: int,
     *     accepted: int,
     *     examples: int,
     *     facts: int,
     *     documents: int
     * }
     */
    public function stats(int $workspaceId): array
    {
        return [
            'sent_replies' => $this->sentReplyQuery($workspaceId)->count(),
            'unscanned' => $this->candidateThreads($workspaceId)->count(),
            'pending' => HistoryLearning::query()
                ->where('workspace_id', $workspaceId)
                ->where('status', 'pending')
                ->whereIn('kind', HistoryLearning::REVIEW_KINDS)
                ->count(),
            'accepted' => HistoryLearning::query()
                ->where('workspace_id', $workspaceId)
                ->where('status', 'accepted')
                ->count(),
            'examples' => ApprovedResponse::where('workspace_id', $workspaceId)->count(),
            'facts' => LiveFact::query()->where('workspace_id', $workspaceId)->active()->count(),
            'documents' => KbDocument::query()
                ->whereHas('knowledgeBase', fn ($query) => $query->where('workspace_id', $workspaceId))
                ->count(),
        ];
    }

    public function start(int $workspaceId, User $actor, int $limit = self::DEFAULT_LIMIT): HistoryScan
    {
        if ($this->activeScan($workspaceId)) {
            throw new RuntimeException('A scan is already running.');
        }

        $candidates = $this->candidates($workspaceId, $limit);

        $scan = HistoryScan::create([
            'workspace_id' => $workspaceId,
            'started_by' => $actor->id,
            'status' => 'queued',
            'total_count' => $candidates->count(),
        ]);

        MineSentHistoryJob::dispatch($scan);

        return $scan;
    }

    public function process(HistoryScan $scan): void
    {
        $scan->update(['status' => 'running', 'error' => null]);

        $candidates = $this->candidates($scan->workspace_id, max(1, (int) $scan->total_count) ?: self::DEFAULT_LIMIT);

        $scan->update(['total_count' => $candidates->count()]);

        if ($candidates->isEmpty()) {
            $scan->update([
                'status' => 'completed',
                'finished_at' => now(),
            ]);

            return;
        }

        $slugs = IntentCatalog::slugs();

        foreach ($candidates->chunk(self::BATCH_SIZE) as $batch) {
            /** @var Collection<int, array<string, mixed>> $batch */
            $this->processBatch($scan, $batch->values(), $slugs);
        }

        $scan->update([
            'status' => 'completed',
            'finished_at' => now(),
        ]);
    }

    /**
     * @return Collection<int, array{
     *     conversation_id: int,
     *     thread_id: int,
     *     subject: ?string,
     *     customer_message: string,
     *     final_reply: string
     * }>
     */
    public function candidates(int $workspaceId, int $limit = self::DEFAULT_LIMIT): Collection
    {
        $threads = $this->candidateThreads($workspaceId)
            ->with(['conversation.threads' => fn ($query) => $query->orderBy('created_at')])
            ->latest()
            ->limit($limit)
            ->get();

        return $threads
            ->map(fn (Thread $reply) => $this->pairFromReply($reply))
            ->filter()
            ->values();
    }

    /**
     * @param  array<string, mixed>  $edits
     */
    public function accept(HistoryLearning $learning, User $actor, array $edits = []): HistoryLearning
    {
        if ($learning->status !== 'pending' || ! in_array($learning->kind, HistoryLearning::REVIEW_KINDS, true)) {
            throw new RuntimeException('This item cannot be accepted.');
        }

        $kind = (string) ($edits['kind'] ?? $learning->kind);
        if (! in_array($kind, HistoryLearning::REVIEW_KINDS, true)) {
            throw new RuntimeException('Unknown learning kind.');
        }

        $title = trim((string) ($edits['title'] ?? $learning->title ?? ''));
        $body = trim((string) ($edits['body'] ?? $learning->body ?? ''));
        $customerMessage = trim((string) ($edits['customer_message'] ?? $learning->customer_message ?? ''));
        $finalReply = trim((string) ($edits['final_reply'] ?? $learning->final_reply ?? ''));
        $intent = $this->nullableString($edits['intent'] ?? $learning->intent);
        $subtype = $this->nullableString($edits['subtype'] ?? $learning->subtype);
        $keywords = $this->keywords($edits['product_keywords'] ?? $learning->product_keywords ?? []);
        $expiresAt = $edits['expires_at'] ?? ($learning->expires_at?->toDateString());

        $acceptedType = null;
        $acceptedId = null;

        if ($kind === 'approved_reply') {
            if ($customerMessage === '' || $finalReply === '') {
                throw new RuntimeException('Approved replies need a customer message and a final reply.');
            }

            $example = $this->examples->store(
                workspaceId: $learning->workspace_id,
                customerMessage: $customerMessage,
                finalReply: $finalReply,
                actor: $actor,
                source: 'history',
                conversationId: $learning->conversation_id,
                threadId: $learning->thread_id,
                intent: $intent,
                subtype: $subtype,
                tags: $keywords,
            );

            $acceptedType = 'approved_response';
            $acceptedId = $example->id;
        } elseif ($kind === 'knowledge') {
            if ($title === '' || $body === '') {
                throw new RuntimeException('Knowledge items need a title and a body.');
            }

            $document = $this->knowledge->createDocument(
                $this->knowledgeBaseFor($learning->workspace_id),
                [
                    'title' => $title,
                    'content' => $this->stripIdentifiers($body),
                    'meta' => [
                        'source' => 'history',
                        'learning_id' => $learning->id,
                        'conversation_id' => $learning->conversation_id,
                    ],
                ],
            );

            $acceptedType = 'kb_document';
            $acceptedId = $document->id;
        } else {
            if ($body === '') {
                throw new RuntimeException('Current facts need a body.');
            }

            $fact = $this->facts->publish(
                workspaceId: $learning->workspace_id,
                body: $this->stripIdentifiers($body),
                actor: $actor,
                intentSlugs: array_values(array_filter([$intent, $subtype])),
                productKeywords: $keywords,
                sourceConversationId: $learning->conversation_id,
                expiresAt: filled($expiresAt) ? (string) $expiresAt : null,
            );

            $acceptedType = 'live_fact';
            $acceptedId = $fact->id;
        }

        $learning->update([
            'kind' => $kind,
            'title' => $title !== '' ? $title : $learning->title,
            'body' => $body !== '' ? $body : $learning->body,
            'customer_message' => $customerMessage !== '' ? $customerMessage : $learning->customer_message,
            'final_reply' => $finalReply !== '' ? $finalReply : $learning->final_reply,
            'intent' => $intent,
            'subtype' => $subtype,
            'product_keywords' => $keywords,
            'expires_at' => filled($expiresAt) ? $expiresAt : null,
            'status' => 'accepted',
            'accepted_type' => $acceptedType,
            'accepted_id' => $acceptedId,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);

        return $learning->fresh();
    }

    public function reject(HistoryLearning $learning, User $actor): HistoryLearning
    {
        if ($learning->status !== 'pending') {
            throw new RuntimeException('This item has already been reviewed.');
        }

        $learning->update([
            'status' => 'rejected',
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);

        return $learning->fresh();
    }

    /**
     * @return Collection<int, HistoryLearning>
     */
    public function acceptRemainingReplies(int $workspaceId, User $actor): Collection
    {
        $accepted = collect();

        HistoryLearning::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'pending')
            ->where('kind', 'approved_reply')
            ->orderBy('id')
            ->each(function (HistoryLearning $learning) use ($actor, $accepted): void {
                $accepted->push($this->accept($learning, $actor));
            });

        return $accepted;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $batch
     * @param  list<string>  $slugs
     */
    private function processBatch(HistoryScan $scan, Collection $batch, array $slugs): void
    {
        $prompt = $this->batchPrompt($batch);
        $covered = [];

        try {
            /** @var StructuredAgentResponse $response */
            $response = $this->aiSettings->withWorkspaceCredentials(
                $scan->workspace_id,
                fn ($lab, $model, $providerOptions = []) => (new MineHistoryAgent($slugs))
                    ->withProviderOptions($providerOptions)
                    ->prompt($prompt, provider: $lab, model: $model),
                task: 'reply_suggestions',
            );
        } catch (Throwable $e) {
            Log::warning('Commerce Assist history mining batch failed', [
                'scan_id' => $scan->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $items = $response->toArray()['items'] ?? [];
        if (! is_array($items)) {
            $items = [];
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $index = $this->emailIndex($item, $batch->count());
            if ($index === null) {
                continue;
            }

            $email = $batch[$index];
            $covered[$index] = true;
            $this->storeItem($scan, $email, $item);
        }

        foreach ($batch as $index => $email) {
            if (! isset($covered[$index])) {
                $this->storeSkip($scan, $email, 'No reusable content extracted.');
            }
        }

        $scanRows = HistoryLearning::query()->where('scan_id', $scan->id)->get(['thread_id', 'kind']);

        $scan->update([
            'scanned_count' => $scanRows->pluck('thread_id')->unique()->count(),
            'proposed_count' => $scanRows->whereIn('kind', HistoryLearning::REVIEW_KINDS)->count(),
            'skipped_count' => $scanRows->where('kind', 'skip')->count(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $email
     * @param  array<string, mixed>  $item
     */
    private function storeItem(HistoryScan $scan, array $email, array $item): void
    {
        $kind = (string) ($item['kind'] ?? 'skip');
        if (! in_array($kind, HistoryLearning::KINDS, true)) {
            $kind = 'skip';
        }

        $keywords = $this->keywords($item['product_keywords'] ?? []);
        $timeSensitive = (bool) ($item['time_sensitive'] ?? $kind === 'live_fact');
        $body = trim((string) ($item['body'] ?? ''));
        $title = $this->nullableString($item['title'] ?? null);

        if ($kind === 'knowledge') {
            $body = $this->stripIdentifiers($body);
            if ($title === null || $body === '') {
                $kind = 'skip';
                $body = $body !== '' ? $body : 'Knowledge proposal was empty.';
            }
        }

        if ($kind === 'live_fact') {
            $body = $this->stripIdentifiers($body);
            if ($body === '') {
                $kind = 'skip';
                $body = 'Fact proposal was empty.';
            }
        }

        $customerMessage = $this->nullableString($item['customer_message'] ?? null)
            ?: ($kind === 'approved_reply' ? (string) $email['customer_message'] : null);
        $finalReply = $this->nullableString($item['final_reply'] ?? null)
            ?: ($kind === 'approved_reply' ? (string) $email['final_reply'] : null);

        if ($kind === 'approved_reply' && ($customerMessage === null || $finalReply === null)) {
            $kind = 'skip';
            $body = 'Reply proposal was empty.';
        }

        HistoryLearning::create([
            'workspace_id' => $scan->workspace_id,
            'scan_id' => $scan->id,
            'conversation_id' => $email['conversation_id'],
            'thread_id' => $email['thread_id'],
            'kind' => $kind,
            'status' => $kind === 'skip' ? 'skipped' : 'pending',
            'title' => $title,
            'body' => $body !== '' ? $body : null,
            'customer_message' => $customerMessage,
            'final_reply' => $finalReply,
            'source_customer_message' => $email['customer_message'],
            'source_final_reply' => $email['final_reply'],
            'subject' => $email['subject'],
            'intent' => $this->nullableString($item['intent'] ?? null),
            'subtype' => $this->nullableString($item['subtype'] ?? null),
            'product_keywords' => $keywords,
            'time_sensitive' => $timeSensitive,
            'expires_at' => $kind === 'live_fact' && $timeSensitive ? now()->addDays(21) : null,
            'rationale' => $this->nullableString($item['rationale'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $email
     */
    private function storeSkip(HistoryScan $scan, array $email, string $reason): void
    {
        HistoryLearning::create([
            'workspace_id' => $scan->workspace_id,
            'scan_id' => $scan->id,
            'conversation_id' => $email['conversation_id'],
            'thread_id' => $email['thread_id'],
            'kind' => 'skip',
            'status' => 'skipped',
            'body' => $reason,
            'source_customer_message' => $email['customer_message'],
            'source_final_reply' => $email['final_reply'],
            'subject' => $email['subject'],
            'rationale' => $reason,
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $batch
     */
    private function batchPrompt(Collection $batch): string
    {
        return $batch
            ->values()
            ->map(function (array $email, int $index) {
                $n = $index + 1;
                $customer = mb_substr((string) $email['customer_message'], 0, 1500);
                $reply = mb_substr((string) $email['final_reply'], 0, 2000);
                $subject = (string) ($email['subject'] ?? '');

                return <<<TXT
EMAIL {$n}
Subject: {$subject}
Customer: {$customer}
Agent reply: {$reply}
TXT;
            })
            ->implode("\n\n");
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function emailIndex(array $item, int $count): ?int
    {
        if (! isset($item['email_index'])) {
            return null;
        }

        $raw = (int) $item['email_index'];

        if ($raw >= 1 && $raw <= $count) {
            return $raw - 1;
        }

        if ($raw >= 0 && $raw < $count) {
            return $raw;
        }

        return null;
    }

    /**
     * @return array{
     *     conversation_id: int,
     *     thread_id: int,
     *     subject: ?string,
     *     customer_message: string,
     *     final_reply: string
     * }|null
     */
    private function pairFromReply(Thread $reply): ?array
    {
        $conversation = $reply->conversation;
        if (! $conversation) {
            return null;
        }

        $customer = $conversation->threads
            ->filter(fn (Thread $thread) => $thread->customer_id && $thread->created_at <= $reply->created_at)
            ->sortByDesc('created_at')
            ->first();

        $customerMessage = PlainText::from($customer?->body_plain ?: $customer?->body) ?: (string) $conversation->subject;
        $finalReply = PlainText::from($reply->body_plain ?: $reply->body);

        if (mb_strlen($finalReply) < self::MIN_REPLY_LENGTH) {
            return null;
        }

        return [
            'conversation_id' => (int) $conversation->id,
            'thread_id' => (int) $reply->id,
            'subject' => $conversation->subject,
            'customer_message' => $customerMessage,
            'final_reply' => $finalReply,
        ];
    }

    /** @return Builder<Thread> */
    private function candidateThreads(int $workspaceId): Builder
    {
        $exclude = ApprovedResponse::query()
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('thread_id')
            ->pluck('thread_id')
            ->merge(
                HistoryLearning::query()
                    ->where('workspace_id', $workspaceId)
                    ->whereNotNull('thread_id')
                    ->pluck('thread_id')
            )
            ->unique()
            ->filter()
            ->values()
            ->all();

        return $this->sentReplyQuery($workspaceId)
            ->when($exclude !== [], fn ($query) => $query->whereNotIn('id', $exclude));
    }

    /** @return Builder<Thread> */
    private function sentReplyQuery(int $workspaceId): Builder
    {
        return Thread::query()
            ->where('type', ThreadType::Message->value)
            ->whereNotNull('user_id')
            ->whereHas('conversation', fn ($query) => $query->where('workspace_id', $workspaceId));
    }

    private function knowledgeBaseFor(int $workspaceId): KnowledgeBase
    {
        $existing = KnowledgeBase::query()
            ->where('workspace_id', $workspaceId)
            ->where('active', true)
            ->orderBy('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        return $this->knowledge->create($workspaceId, [
            'name' => 'Support knowledge',
            'description' => 'Policies and process learned from emails this team already sent.',
        ]);
    }

    private function stripIdentifiers(string $text): string
    {
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $text) ?? $text;
        $text = preg_replace('/\b(?:SD|WG|#)?\d{5,}\b/i', 'the order', $text) ?? $text;

        return trim($text);
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function keywords(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($keyword) => mb_substr(trim((string) $keyword), 0, 120),
            $value,
        ))));
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
