<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgenticMarketingAgentConflict extends Model
{
    use HasUuids;

    protected $fillable = [
        'orchestration_run_id', 'conflict_key', 'status', 'claims', 'resolution',
    ];

    protected $casts = [
        'claims' => 'array',
        'resolution' => 'array',
    ];

    public function orchestrationRun(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingOrchestrationRun::class, 'orchestration_run_id');
    }
}
