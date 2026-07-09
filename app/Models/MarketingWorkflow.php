<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingWorkflow extends Model
{
    use HasUuids;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'marketing_objective_id',
        'marketing_initiative_id',
        'workflow_key',
        'name',
        'status',
        'current_stage',
        'stages_json',
        'gates_json',
        'metadata_json',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'stages_json' => 'array',
        'gates_json' => 'array',
        'metadata_json' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(MarketingObjective::class, 'marketing_objective_id');
    }

    public function initiative(): BelongsTo
    {
        return $this->belongsTo(MarketingInitiative::class, 'marketing_initiative_id');
    }
}
