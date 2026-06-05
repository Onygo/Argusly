<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgenticMarketingOrchestrationRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id', 'workspace_id', 'client_site_id', 'objective_id',
        'workflow_key', 'status', 'mode', 'provider_key', 'trigger_source',
        'shared_context', 'input', 'normalized_result', 'confidence_score',
        'tasks_count', 'completed_tasks_count', 'failed_tasks_count',
        'conflicts_count', 'failure_reason', 'requested_by', 'started_at',
        'finished_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'shared_context' => 'array',
        'input' => 'array',
        'normalized_result' => 'array',
        'confidence_score' => 'float',
        'tasks_count' => 'integer',
        'completed_tasks_count' => 'integer',
        'failed_tasks_count' => 'integer',
        'conflicts_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class, 'client_site_id');
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingObjective::class, 'objective_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(AgenticMarketingAgentTask::class, 'orchestration_run_id')->orderBy('sequence_order');
    }

    public function traces(): HasMany
    {
        return $this->hasMany(AgenticMarketingAgentTrace::class, 'orchestration_run_id')->latest();
    }

    public function conflicts(): HasMany
    {
        return $this->hasMany(AgenticMarketingAgentConflict::class, 'orchestration_run_id');
    }
}
