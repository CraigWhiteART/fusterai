<?php

namespace Modules\CommerceAssist\Models;

use App\Domains\Conversation\Models\Conversation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifySnapshot extends Model
{
    protected $table = 'commerce_assist_shopify_snapshots';

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
