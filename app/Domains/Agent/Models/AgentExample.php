<?php

namespace App\Domains\Agent\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An approved reply used for style and strategy only (never for facts).
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $conversation_id
 * @property int|null $thread_id
 * @property int|null $legacy_approved_response_id
 * @property string $customer_message
 * @property string $reply
 * @property string|null $playbook_slug
 * @property string|null $sentiment
 * @property string|null $country
 * @property list<string>|null $tags
 * @property string $source
 * @property string $status
 * @property string|null $embedding_model
 * @property array<int, float>|string|null $embedding
 */
class AgentExample extends Model
{
    protected $table = 'agent_examples';

    protected $fillable = [
        'workspace_id',
        'conversation_id',
        'thread_id',
        'legacy_approved_response_id',
        'customer_message',
        'reply',
        'playbook_slug',
        'sentiment',
        'country',
        'tags',
        'source',
        'status',
        'embedding',
        'embedding_model',
        'created_by',
    ];

    protected $casts = [
        'tags' => 'array',
    ];

    protected $hidden = ['embedding', 'tsv'];
}
