<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgenticMarketingExecutionPipeline extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id', 'objective_id', 'opportunity_id', 'run_id', 'mode', 'status',
        'current_stage', 'approval_status', 'publishing_readiness', 'assets_count',
        'pending_approvals_count', 'input', 'result', 'rollback_snapshot', 'failure_reason',
        'requested_by', 'started_at', 'completed_at', 'failed_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'assets_count' => 'integer',
        'pending_approvals_count' => 'integer',
        'input' => 'array',
        'result' => 'array',
        'rollback_snapshot' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function objective(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingObjective::class, 'objective_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingOpportunity::class, 'opportunity_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingRun::class, 'run_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(AgenticMarketingExecutionAsset::class, 'pipeline_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(AgenticMarketingExecutionApproval::class, 'pipeline_id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(AgenticMarketingExecutionFeedback::class, 'pipeline_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AgenticMarketingExecutionAuditLog::class, 'pipeline_id');
    }
}
