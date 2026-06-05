<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LlmRoutingRule extends Model
{
    use HasUuids;

    protected $table = 'llm_routing_rules';

    protected $fillable = [
        'scope_type',
        'scope_id',
        'feature',
        'modality',
        'inherit_global',
        'provider',
        'model',
        'fallback_enabled',
        'fallback_provider',
        'fallback_model',
        'is_enabled',
        'meta',
    ];

    protected $casts = [
        'inherit_global' => 'boolean',
        'fallback_enabled' => 'boolean',
        'is_enabled' => 'boolean',
        'meta' => 'array',
    ];
}
