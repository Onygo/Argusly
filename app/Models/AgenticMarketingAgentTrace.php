<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgenticMarketingAgentTrace extends Model
{
    use HasUuids;

    protected $fillable = [
        'orchestration_run_id', 'agent_task_id', 'event', 'payload', 'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function orchestrationRun(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingOrchestrationRun::class, 'orchestration_run_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingAgentTask::class, 'agent_task_id');
    }
}
