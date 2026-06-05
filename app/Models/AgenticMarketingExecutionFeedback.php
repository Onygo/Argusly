<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgenticMarketingExecutionFeedback extends Model
{
    use HasUuids;

    protected $fillable = ['pipeline_id', 'asset_id', 'user_id', 'type', 'body', 'payload'];

    protected $casts = ['payload' => 'array'];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingExecutionPipeline::class, 'pipeline_id');
    }
}
