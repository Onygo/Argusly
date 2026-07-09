<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingPriority extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'marketing_objective_id',
        'marketing_initiative_id',
        'name',
        'priority_level',
        'priority_score',
        'confidence_score',
        'reason',
        'status',
        'evidence_json',
        'metadata_json',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'priority_score' => 'integer',
        'confidence_score' => 'decimal:4',
        'evidence_json' => 'array',
        'metadata_json' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(MarketingObjective::class, 'marketing_objective_id');
    }

    public function initiative(): BelongsTo
    {
        return $this->belongsTo(MarketingInitiative::class, 'marketing_initiative_id');
    }
}
