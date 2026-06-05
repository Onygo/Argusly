<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgenticMarketingExecutionApproval extends Model
{
    use HasUuids;

    protected $fillable = [
        'pipeline_id', 'asset_id', 'status', 'approval_type', 'requested_role',
        'requested_by', 'reviewed_by', 'feedback', 'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingExecutionPipeline::class, 'pipeline_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingExecutionAsset::class, 'asset_id');
    }
}
