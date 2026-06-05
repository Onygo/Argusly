<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AgenticMarketingExecutionAsset extends Model
{
    use HasUuids;

    protected $fillable = [
        'pipeline_id', 'objective_id', 'opportunity_id', 'type', 'status', 'title',
        'payload', 'assetable_type', 'assetable_id', 'requires_approval', 'approved_by',
        'approved_at', 'rejected_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'requires_approval' => 'boolean',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingExecutionPipeline::class, 'pipeline_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingOpportunity::class, 'opportunity_id');
    }

    public function assetable(): MorphTo
    {
        return $this->morphTo();
    }
}
