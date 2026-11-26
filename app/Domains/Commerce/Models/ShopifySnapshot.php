<?php

namespace App\Domains\Commerce\Models;

use App\Domains\Conversation\Models\Conversation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $conversation_id
 * @property string|null $customer_email
 * @property string|null $shopify_customer_id
 * @property string|null $shopify_order_id
 * @property string|null $order_number
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $fetched_at
 * @property string|null $error
 */
class ShopifySnapshot extends Model
{
    protected $table = 'commerce_shopify_snapshots';

    protected $fillable = [
        'workspace_id',
        'conversation_id',
        'customer_email',
        'shopify_customer_id',
        'shopify_order_id',
        'order_number',
        'payload',
        'fetched_at',
        'error',
    ];

    protected $casts = [
        'payload' => 'array',
        'fetched_at' => 'datetime',
    ];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function found(): bool
    {
        return (bool) ($this->payload['found'] ?? false);
    }
}
