<?php

namespace App\Models;

use App\Models\Connectors\Normalized\NormalizedCrmDeal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttributionConversion extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'conversion_key',
        'contact_key',
        'email_hash',
        'deal_id',
        'conversion_type',
        'occurred_at',
        'value',
        'currency',
        'status',
        'raw_reference',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'value' => 'decimal:6',
        'raw_reference' => 'array',
    ];

    public function scopeForWorkspace(Builder $query, Workspace|string $workspace): Builder
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return $query->where('workspace_id', $workspaceId);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(NormalizedCrmDeal::class, 'deal_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(AttributionResult::class);
    }
}
