<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AgenticMarketingWorkflowOverride extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    public const TYPE_PAUSE_WORKFLOW = 'pause_workflow';
    public const TYPE_FORCE_APPROVAL = 'force_approval';
    public const TYPE_BLOCK_ACTION = 'block_action';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'agent_workflow_run_id',
        'user_id',
        'override_type',
        'subject_type',
        'subject_id',
        'reason',
        'payload',
        'is_active',
        'expires_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'payload' => 'array',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function (Builder $builder): void {
                $builder->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(AgentWorkflowRun::class, 'agent_workflow_run_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
