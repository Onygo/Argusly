<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class GrowthAsset extends Model
{
    use HasFactory;
    use HasUuids;

    public const ROLE_OPPORTUNITY = 'opportunity';
    public const ROLE_CONTENT_OPPORTUNITY = 'content_opportunity';
    public const ROLE_COMPETITOR_GAP = 'competitor_gap';
    public const ROLE_AGENTIC_OPPORTUNITY = 'agentic_opportunity';
    public const ROLE_SIGNAL = 'signal';
    public const ROLE_PROGRAMMATIC_OPPORTUNITY = 'programmatic_opportunity';
    public const ROLE_PROGRAMMATIC_CLUSTER = 'programmatic_cluster';
    public const ROLE_BRIEF_BLUEPRINT = 'brief_blueprint';
    public const ROLE_DRAFT_REQUEST = 'draft_request';
    public const ROLE_DRAFT_REVIEW = 'draft_review';
    public const ROLE_PUBLICATION_READINESS = 'publication_readiness';
    public const ROLE_PUBLICATION_PLAN = 'publication_plan';
    public const ROLE_EXECUTION_PLAN = 'execution_plan';
    public const ROLE_BRIEF = 'brief';
    public const ROLE_DRAFT = 'draft';
    public const ROLE_CONTENT = 'content';
    public const ROLE_PUBLICATION = 'publication';
    public const ROLE_CAMPAIGN_CLUSTER = 'campaign_cluster';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'growth_program_id',
        'growth_run_id',
        'role',
        'assetable_type',
        'assetable_id',
        'status_at_link',
        'source_type',
        'weight',
        'metadata',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'weight' => 'float',
        'metadata' => 'array',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(GrowthProgram::class, 'growth_program_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(GrowthRun::class, 'growth_run_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function assetable(): MorphTo
    {
        return $this->morphTo();
    }
}
