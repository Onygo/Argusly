<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgenticMarketingAgentMemory extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id', 'workspace_id', 'client_site_id', 'agent_key',
        'memory_type', 'memory_key', 'payload', 'confidence_score',
        'last_used_at', 'expires_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'payload' => 'array',
        'confidence_score' => 'float',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class, 'client_site_id');
    }
}
