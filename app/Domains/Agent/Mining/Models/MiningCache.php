<?php

namespace App\Domains\Agent\Mining\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Structured output of a cluster-merge / synthesis / voice call, keyed by a
 * hash of its input data and the prompt version.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $kind
 * @property string $input_hash
 * @property array<string, mixed> $output
 * @property string|null $model
 * @property int $tokens_in
 * @property int $tokens_out
 * @property int $cost_micro_usd
 */
class MiningCache extends Model
{
    protected $table = 'agent_mining_cache';

    protected $guarded = ['id'];

    protected $casts = [
        'output' => 'array',
        'tokens_in' => 'integer',
        'tokens_out' => 'integer',
        'cost_micro_usd' => 'integer',
    ];
}
