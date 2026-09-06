<?php

namespace Modules\CommerceAssist\Models;

use Illuminate\Database\Eloquent\Model;

class CommerceIntent extends Model
{
    protected $table = 'commerce_assist_intents';

    protected $fillable = [
        'workspace_id',
        'slug',
        'parent_slug',
        'name',
        'rules',
        'data_requirements',
        'always_human',
        'auto_send_allowed',
        'sort_order',
    ];

    protected $casts = [
        'data_requirements' => 'array',
        'always_human' => 'boolean',
        'auto_send_allowed' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function isSubtype(): bool
    {
        return filled($this->parent_slug);
    }
}
