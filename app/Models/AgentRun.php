<?php

namespace App\Models;

use App\Agents\Support\AgentRunStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'agent_key',
        'trigger_type',
        'trigger_source',
        'status',
        'organization_id',
        'workspace_id',
        'site_id',
        'content_id',
        'draft_id',
        'user_id',
        'workflow_run_id',
        'workflow_step_key',
        'input_payload',
        'output_payload',
        'summary',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'status' => AgentRunStatus::class,
        'input_payload' => 'array',
        'output_payload' => 'array',
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
        return $this->belongsTo(ClientSite::class, 'site_id');
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(AgentWorkflowRun::class, 'workflow_run_id');
    }
}
