<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\BrandGrowthAudienceProposalType;
use App\Enums\BrandGrowthAudienceSourceType;
use App\Enums\BrandGrowthPlanReviewState;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BrandGrowthAudienceProposal extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'brand_growth_plan_id',
        'persona_id',
        'proposal_type',
        'source_type',
        'review_state',
        'name',
        'role',
        'seniority',
        'department',
        'industry',
        'company_size',
        'responsibilities',
        'goals',
        'pain_points',
        'objections',
        'buying_triggers',
        'kpis',
        'preferred_content',
        'buying_stage_relevance',
        'buying_committee_role',
        'confidence_score',
        'source_references',
        'metadata_json',
        'dedupe_hash',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'persona_id' => 'integer',
        'proposal_type' => BrandGrowthAudienceProposalType::class,
        'source_type' => BrandGrowthAudienceSourceType::class,
        'review_state' => BrandGrowthPlanReviewState::class,
        'responsibilities' => 'array',
        'goals' => 'array',
        'pain_points' => 'array',
        'objections' => 'array',
        'buying_triggers' => 'array',
        'kpis' => 'array',
        'preferred_content' => 'array',
        'buying_stage_relevance' => 'array',
        'confidence_score' => 'float',
        'source_references' => 'array',
        'metadata_json' => 'array',
        'reviewed_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BrandGrowthPlan::class, 'brand_growth_plan_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function isApproved(): bool
    {
        return $this->review_state === BrandGrowthPlanReviewState::APPROVED;
    }
}
