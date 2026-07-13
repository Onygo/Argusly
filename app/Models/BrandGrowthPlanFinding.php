<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\BrandGrowthFindingType;
use App\Enums\BrandGrowthPlanReviewState;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BrandGrowthPlanFinding extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'brand_growth_plan_id',
        'content_id',
        'monitored_page_id',
        'site_competitor_id',
        'opportunity_id',
        'type',
        'status',
        'review_state',
        'title',
        'description',
        'rationale',
        'impact_score',
        'urgency_score',
        'confidence_score',
        'affected_audience',
        'affected_industry',
        'affected_funnel_stage',
        'recommended_action',
        'source_references',
        'source_summary',
        'metadata_json',
        'dedupe_hash',
        'reviewed_by',
        'reviewed_at',
        'promoted_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'type' => BrandGrowthFindingType::class,
        'review_state' => BrandGrowthPlanReviewState::class,
        'impact_score' => 'float',
        'urgency_score' => 'float',
        'confidence_score' => 'float',
        'source_references' => 'array',
        'source_summary' => 'array',
        'metadata_json' => 'array',
        'reviewed_at' => 'datetime',
        'promoted_at' => 'datetime',
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

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function monitoredPage(): BelongsTo
    {
        return $this->belongsTo(MonitoredPage::class);
    }

    public function competitor(): BelongsTo
    {
        return $this->belongsTo(SiteCompetitor::class, 'site_competitor_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isApproved(): bool
    {
        return $this->review_state === BrandGrowthPlanReviewState::APPROVED;
    }
}
