<?php

namespace App\Domains\Agent\Models;

use App\Domains\AI\Models\KbDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One retrievable unit of approved knowledge. Scope is required: an item is
 * only "global" when scope.global is explicitly true; anything else must name
 * the playbooks, products or countries it applies to.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $kind
 * @property string|null $title
 * @property string $body
 * @property array{playbooks?: list<string>, products?: list<string>, countries?: list<string>, global?: bool} $scope
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_until
 * @property string $status
 * @property int|null $supersedes_id
 * @property int|null $source_document_id
 * @property int|null $chunk_index
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string|null $content_hash
 * @property string|null $embedding_model
 * @property array<int, float>|string|null $embedding
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class KnowledgeItem extends Model
{
    public const KINDS = ['fact', 'policy', 'kb_chunk', 'snippet'];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_NEEDS_SCOPE = 'needs_scope';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_RETIRED = 'retired';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_NEEDS_SCOPE, self::STATUS_DRAFT, self::STATUS_RETIRED];

    protected $table = 'agent_knowledge_items';

    protected $fillable = [
        'workspace_id',
        'kind',
        'title',
        'body',
        'scope',
        'valid_from',
        'valid_until',
        'status',
        'supersedes_id',
        'source_document_id',
        'chunk_index',
        'source_type',
        'source_id',
        'content_hash',
        'embedding',
        'embedding_model',
        'created_by',
    ];

    protected $casts = [
        'scope' => 'array',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'chunk_index' => 'integer',
        'source_id' => 'integer',
    ];

    protected $hidden = ['embedding', 'tsv'];

    /** @return BelongsTo<KbDocument, $this> */
    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(KbDocument::class, 'source_document_id');
    }

    /** @return BelongsTo<KnowledgeItem, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /**
     * Active and inside its validity window at the given instant.
     *
     * @param  Builder<KnowledgeItem>  $query
     * @return Builder<KnowledgeItem>
     */
    public function scopeUsableAt(Builder $query, \DateTimeInterface $at): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', $at));
    }

    /** @return array{playbooks: list<string>, products: list<string>, countries: list<string>, global: bool} */
    public static function normalizeScope(mixed $scope): array
    {
        $scope = is_array($scope) ? $scope : [];
        $list = fn (mixed $value): array => array_values(array_unique(array_filter(array_map(
            fn ($item) => trim((string) $item),
            is_array($value) ? $value : [],
        ), fn ($item) => $item !== '')));

        return [
            'playbooks' => $list($scope['playbooks'] ?? []),
            'products' => array_map('mb_strtolower', $list($scope['products'] ?? [])),
            'countries' => array_map('strtoupper', $list($scope['countries'] ?? [])),
            'global' => ($scope['global'] ?? false) === true,
        ];
    }

    /** True when the scope names nothing and is not explicitly global. */
    public static function scopeIsEmpty(array $scope): bool
    {
        $scope = self::normalizeScope($scope);

        return ! $scope['global'] && $scope['playbooks'] === [] && $scope['products'] === [] && $scope['countries'] === [];
    }

    /** @return array<string, mixed> */
    public function toUiArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'scope' => self::normalizeScope($this->scope),
            'valid_from' => $this->valid_from?->toIso8601String(),
            'valid_until' => $this->valid_until?->toIso8601String(),
            'status' => $this->status,
            'supersedes_id' => $this->supersedes_id,
            'source_document_id' => $this->source_document_id,
            'chunk_index' => $this->chunk_index,
            'source_type' => $this->source_type,
            'has_embedding' => $this->embedding_model !== null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
