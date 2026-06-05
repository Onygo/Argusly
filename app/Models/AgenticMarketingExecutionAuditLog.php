<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgenticMarketingExecutionAuditLog extends Model
{
    use HasUuids;

    protected $fillable = ['pipeline_id', 'asset_id', 'actor_id', 'event', 'before', 'after', 'metadata'];

    protected $casts = ['before' => 'array', 'after' => 'array', 'metadata' => 'array'];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingExecutionPipeline::class, 'pipeline_id');
    }
}
