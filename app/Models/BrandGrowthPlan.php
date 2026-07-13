<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\BrandGrowthPlanStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BrandGrowthPlan extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'supersedes_plan_id',
        'status',
        'version',
        'planning_horizon',
        'business_objective',
        'brand_objective',
        'generated_at',
        'source_data_cutoff_at',
        'confidence_score',
        'confidence_summary',
        'assumptions',
        'missing_information',
        'context_snapshot',
        'recommended_primary_audiences',
        'recommended_secondary_audiences',
        'priority_industries',
        'buying_committee_roles',
        'positioning_observations',
        'messaging_priorities',
        'authority_priorities',
        'evidence_priorities',
        'content_priorities',
        'campaign_themes',
        'channel_recommendations',
        'kpi_recommendations',
        'top_prioritized_actions',
        'generated_by_metadata',
        'created_by',
        'reviewed_by',
        'approved_by',
        'reviewed_at',
        'approved_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'status' => BrandGrowthPlanStatus::class,
        'version' => 'integer',
        'generated_at' => 'datetime',
        'source_data_cutoff_at' => 'datetime',
        'confidence_score' => 'float',
        'confidence_summary' => 'array',
        'assumptions' => 'array',
        'missing_information' => 'array',
        'context_snapshot' => 'array',
        'recommended_primary_audiences' => 'array',
        'recommended_secondary_audiences' => 'array',
        'priority_industries' => 'array',
        'buying_committee_roles' => 'array',
        'positioning_observations' => 'array',
        'messaging_priorities' => 'array',
        'authority_priorities' => 'array',
        'evidence_priorities' => 'array',
        'content_priorities' => 'array',
        'campaign_themes' => 'array',
        'channel_recommendations' => 'array',
        'kpi_recommendations' => 'array',
        'top_prioritized_actions' => 'array',
        'generated_by_metadata' => 'array',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function supersedesPlan(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_plan_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(BrandGrowthPlanFinding::class);
    }

    public function audienceProposals(): HasMany
    {
        return $this->hasMany(BrandGrowthAudienceProposal::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->status === BrandGrowthPlanStatus::APPROVED;
    }
}
