<?php

namespace Modules\CommerceAssist\Services;

use App\Domains\Conversation\Models\Conversation;
use App\Enums\ConversationStatus;
use App\Models\User;
use Illuminate\Support\Collection;
use Modules\CommerceAssist\Jobs\RefreshDraftsForFactJob;
use Modules\CommerceAssist\Models\Generation;
use Modules\CommerceAssist\Models\LiveFact;
use Modules\CommerceAssist\Models\ShopifySnapshot;
use Modules\CommerceAssist\Support\PlainText;

class LiveFactService
{
    /**
     * @return array{intents: list<string>, products: list<string>}
     */
    public function inferFromConversation(Conversation $conversation): array
    {
        $generation = Generation::where('conversation_id', $conversation->id)->latest()->first();
        $snapshot = ShopifySnapshot::where('conversation_id', $conversation->id)->latest()->first();

        $intents = array_values(array_unique(array_filter([
            $generation?->intent,
            $generation?->subtype,
        ])));

        return [
            'intents' => $intents,
            'products' => $this->keywordsFromSnapshot($snapshot),
        ];
    }

    /**
     * @param  list<string>  $intentSlugs
     * @param  list<string>  $productKeywords
     * @return Collection<int, array<string, mixed>>
     */
    public function previewDrafts(
        int $workspaceId,
        array $intentSlugs,
        array $productKeywords,
        ?int $sourceConversationId = null,
    ): Collection {
        return $this->matchingConversations($workspaceId, $intentSlugs, $productKeywords, $sourceConversationId)
            ->map(fn (Conversation $conversation) => $this->draftSummary($conversation));
    }

    /**
     * @param  list<string>  $intentSlugs
     * @param  list<string>  $productKeywords
     */
    public function publish(
        int $workspaceId,
        string $body,
        User $actor,
        array $intentSlugs = [],
        array $productKeywords = [],
        ?int $sourceConversationId = null,
        ?string $expiresAt = null,
    ): LiveFact {
        $body = trim($body);
        $intentSlugs = $this->cleanList($intentSlugs);
        $productKeywords = $this->cleanList($productKeywords);

        $matches = $this->matchingConversations($workspaceId, $intentSlugs, $productKeywords, $sourceConversationId);
        $ids = $matches->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        $fact = LiveFact::create([
            'workspace_id' => $workspaceId,
            'source_conversation_id' => $sourceConversationId,
            'created_by' => $actor->id,
            'title' => mb_substr(PlainText::from($body), 0, 80) ?: null,
            'body' => $body,
            'intent_slugs' => $intentSlugs,
            'product_keywords' => $productKeywords,
            'expires_at' => $expiresAt,
            'refresh_started_at' => now(),
            'refreshed_conversation_ids' => $ids,
        ]);

        RefreshDraftsForFactJob::dispatch($fact, $ids);

        return $fact;
    }

    /**
     * Active facts that should be injected into this conversation's prompt.
     *
     * @return Collection<int, LiveFact>
     */
    public function forConversation(
        Conversation $conversation,
        ?string $intent = null,
        ?string $subtype = null,
        ?ShopifySnapshot $snapshot = null,
    ): Collection {
        $snapshot ??= ShopifySnapshot::where('conversation_id', $conversation->id)->latest()->first();
        $generation = Generation::where('conversation_id', $conversation->id)->latest()->first();
        $intent ??= $generation?->intent;
        $subtype ??= $generation?->subtype;

        return LiveFact::query()
            ->active()
            ->where('workspace_id', $conversation->workspace_id)
            ->latest()
            ->limit(20)
            ->get()
            ->filter(fn (LiveFact $fact) => $this->appliesTo($fact, $conversation, $intent, $subtype, $snapshot))
            ->values();
    }

    /**
     * @param  list<string>  $intentSlugs
     * @param  list<string>  $productKeywords
     * @return Collection<int, Conversation>
     */
    public function matchingConversations(
        int $workspaceId,
        array $intentSlugs,
        array $productKeywords,
        ?int $sourceConversationId = null,
    ): Collection {
        $intentSlugs = $this->cleanList($intentSlugs);
        $productKeywords = $this->cleanList($productKeywords);

        $openStatuses = [ConversationStatus::Open->value, ConversationStatus::Pending->value];

        $conversationIds = Generation::query()
            ->where('workspace_id', $workspaceId)
            ->whereNull('reply_thread_id')
            ->whereIn('status', ['draft', 'flagged'])
            ->whereHas('conversation', function ($query) use ($openStatuses) {
                $query->whereIn('status', $openStatuses);
            })
            ->pluck('conversation_id')
            ->unique()
            ->values();

        if ($sourceConversationId) {
            $conversationIds = $conversationIds->push($sourceConversationId)->unique()->values();
        }

        if ($conversationIds->isEmpty()) {
            return collect();
        }

        $conversations = Conversation::query()
            ->whereIn('id', $conversationIds)
            ->with(['customer:id,name,email'])
            ->get();

        $snapshots = ShopifySnapshot::query()
            ->whereIn('conversation_id', $conversationIds)
            ->orderByDesc('id')
            ->get()
            ->unique('conversation_id')
            ->keyBy('conversation_id');

        $generations = Generation::query()
            ->whereIn('conversation_id', $conversationIds)
            ->orderByDesc('id')
            ->get()
            ->unique('conversation_id')
            ->keyBy('conversation_id');

        $probe = new LiveFact([
            'intent_slugs' => $intentSlugs,
            'product_keywords' => $productKeywords,
            'source_conversation_id' => $sourceConversationId,
        ]);

        return $conversations
            ->filter(function (Conversation $conversation) use ($probe, $snapshots, $generations) {
                $generation = $generations->get($conversation->id);

                return $this->appliesTo(
                    $probe,
                    $conversation,
                    $generation?->intent,
                    $generation?->subtype,
                    $snapshots->get($conversation->id),
                );
            })
            ->values();
    }

    public function appliesTo(
        LiveFact $fact,
        Conversation $conversation,
        ?string $intent,
        ?string $subtype,
        ?ShopifySnapshot $snapshot,
    ): bool {
        if ($fact->source_conversation_id && (int) $fact->source_conversation_id === (int) $conversation->id) {
            return true;
        }

        $products = $this->cleanList($fact->product_keywords ?? []);
        $intents = $this->cleanList($fact->intent_slugs ?? []);

        if ($products !== [] && $this->productsOverlap($products, $conversation, $snapshot)) {
            return true;
        }

        if ($products === [] && $intents !== [] && $this->intentOverlaps($intents, $intent, $subtype)) {
            return true;
        }

        if ($products === [] && $intents === []) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<string>  $keywords
     */
    private function productsOverlap(array $keywords, Conversation $conversation, ?ShopifySnapshot $snapshot): bool
    {
        $haystack = strtolower(trim(
            ($conversation->subject ?? '').' '.
            implode(' ', $this->keywordsFromSnapshot($snapshot))
        ));

        foreach ($keywords as $keyword) {
            if ($keyword !== '' && str_contains($haystack, strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $intents
     */
    private function intentOverlaps(array $intents, ?string $intent, ?string $subtype): bool
    {
        $have = array_filter([$intent, $subtype]);

        foreach ($intents as $slug) {
            foreach ($have as $candidate) {
                if ($candidate === $slug) {
                    return true;
                }
                if (str_starts_with((string) $candidate, $slug.'.') || str_starts_with($slug, (string) $candidate.'.')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function keywordsFromSnapshot(?ShopifySnapshot $snapshot): array
    {
        if (! $snapshot?->found()) {
            return [];
        }

        $keywords = [];
        foreach ($snapshot->payload['products'] ?? [] as $product) {
            foreach (['title', 'variant', 'sku'] as $field) {
                $value = trim((string) ($product[$field] ?? ''));
                if ($value !== '' && strtolower($value) !== 'default' && strtolower($value) !== 'n/a') {
                    $keywords[] = $value;
                }
            }
        }

        return $this->cleanList($keywords);
    }

    /**
     * @return array<string, mixed>
     */
    private function draftSummary(Conversation $conversation): array
    {
        $generation = Generation::where('conversation_id', $conversation->id)->latest()->first();

        return [
            'id' => $conversation->id,
            'subject' => $conversation->subject,
            'customer' => $conversation->customer?->name ?: $conversation->customer?->email,
            'intent' => $generation?->subtype ?: $generation?->intent,
            'status' => $conversation->status instanceof \BackedEnum ? $conversation->status->value : $conversation->status,
        ];
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function cleanList(array $values): array
    {
        $clean = [];
        foreach ($values as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                $clean[] = $text;
            }
        }

        return array_values(array_unique($clean));
    }
}
