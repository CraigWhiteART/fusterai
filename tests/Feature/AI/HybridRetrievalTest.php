<?php

use App\Ai\EmbeddingService;
use App\Ai\Retrieval\HybridRetriever;
use App\Domains\AI\Models\KbDocument;
use App\Domains\AI\Models\KnowledgeBase;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/** A unit vector along one axis, so cosine similarity is exactly 1 or 0. */
function axisVector(int $axis): array
{
    $vector = array_fill(0, EmbeddingService::DIMENSIONS, 0.0);
    $vector[$axis] = 1.0;

    return $vector;
}

/**
 * KB documents of the test workspace through the shared retriever (the agent's
 * KnowledgeSearch / ExampleSearch use the same HybridRetriever).
 *
 * @param  list<float>|null  $vector
 */
function hrTSearch(int $workspaceId, string $query, ?array $vector = null): Collection
{
    return app(HybridRetriever::class)->search(
        KbDocument::whereHas('knowledgeBase', fn ($q) => $q->where('workspace_id', $workspaceId)),
        ['title', 'content'],
        $query,
        $vector,
        5,
        0.5,
    );
}

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    $this->kb = KnowledgeBase::create(['workspace_id' => $this->workspace->id, 'name' => 'Docs', 'active' => true]);
});

test('a whole customer email finds the right article by full-text search with embeddings off', function () {
    KbDocument::create(['kb_id' => $this->kb->id, 'title' => 'Shipping times', 'content' => 'Orders ship within 3 business days. Tracking updates can take 48 hours.']);
    KbDocument::create(['kb_id' => $this->kb->id, 'title' => 'Gift cards', 'content' => 'Gift cards never expire.']);

    $email = "Hi there,\n\nI ordered last week and my tracking number still shows nothing. When will my order ship? Thanks, Sam";

    $docs = hrTSearch($this->workspace->id, $email);

    expect($docs->pluck('title')->all())->toBe(['Shipping times']);
});

test('keyword results are ranked by relevance', function () {
    KbDocument::create(['kb_id' => $this->kb->id, 'title' => 'Refunds', 'content' => 'Refunds are issued to the original payment method. Refund requests take 5 days.']);
    KbDocument::create(['kb_id' => $this->kb->id, 'title' => 'Contact', 'content' => 'Email us about anything, including a refund.']);

    $docs = hrTSearch($this->workspace->id, 'how long does a refund take');

    expect($docs->first()->title)->toBe('Refunds')
        ->and($docs)->toHaveCount(2);
});

test('documents without embeddings stay visible next to vector matches', function () {
    $embedded = KbDocument::create([
        'kb_id' => $this->kb->id,
        'title' => 'Parcel delays',
        'content' => 'Carriers are slower in December.',
        'embedding' => EmbeddingService::toColumn(axisVector(0)),
    ]);
    $plain = KbDocument::create([
        'kb_id' => $this->kb->id,
        'title' => 'Tracking numbers',
        'content' => 'Your tracking number arrives by email.',
    ]);
    KbDocument::create([
        'kb_id' => $this->kb->id,
        'title' => 'Unrelated',
        'content' => 'Gift cards never expire.',
        'embedding' => EmbeddingService::toColumn(axisVector(1)),
    ]);

    $ids = hrTSearch($this->workspace->id, 'where is my tracking number', axisVector(0))->pluck('id')->all();

    expect($ids)->toContain($embedded->id)
        ->and($ids)->toContain($plain->id)
        ->and($ids)->toHaveCount(2);
});

test('queries with no searchable words return nothing instead of erroring', function () {
    KbDocument::create(['kb_id' => $this->kb->id, 'title' => 'Shipping', 'content' => 'We ship worldwide.']);

    expect(hrTSearch($this->workspace->id, 'the and of ?!'))->toBeEmpty()
        ->and(hrTSearch($this->workspace->id, '   '))->toBeEmpty();
});

test('the non-postgres keyword branch matches individual words', function () {
    KbDocument::create(['kb_id' => $this->kb->id, 'title' => 'Shipping', 'content' => 'We ship worldwide.']);

    // The LIKE branch only runs on non-Postgres connections; call it directly.
    $builder = KbDocument::where('kb_id', $this->kb->id);
    $docs = Closure::bind(
        fn () => $this->likeSearch($builder, ['title', 'content'], 'Do you SHIP to Canada?', 5, ['*']),
        app(HybridRetriever::class),
        HybridRetriever::class,
    )();

    expect($docs->pluck('title')->all())->toBe(['Shipping']);
});
