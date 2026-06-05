<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgenticMarketingAgentTask extends Model
{
    use HasUuids;

    protected $fillable = [
        'orchestration_run_id', 'agent_key', 'status', 'sequence_order',
        'attempts', 'max_attempts', 'input', 'normalized_result',
        'confidence_score', 'tool_plan', 'mcp_context', 'error_message',
        'started_at', 'finished_at',
    ];

    protected $casts = [
        'sequence_order' => 'integer',
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'input' => 'array',
        'normalized_result' => 'array',
        'confidence_score' => 'float',
        'tool_plan' => 'array',
        'mcp_context' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function orchestrationRun(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingOrchestrationRun::class, 'orchestration_run_id');
    }

    public function traces(): HasMany
    {
        return $this->hasMany(AgenticMarketingAgentTrace::class, 'agent_task_id');
    }
}
