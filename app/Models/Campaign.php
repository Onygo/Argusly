<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\CampaignApprovalStatus;
use App\Enums\CampaignStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Campaign extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'agentic_marketing_objective_id',
        'campaign_cluster_id',
        'tone_profile_id',
        'cta_preset_id',
        'owner_user_id',
        'name',
        'slug',
        'objective',
        'status',
        'approval_status',
        'planned_start_date',
        'planned_end_date',
        'scheduled_start_at',
        'scheduled_end_at',
        'submitted_for_approval_at',
        'approved_at',
        'approved_by',
        'last_planned_at',
        'audience',
        'goals',
        'kpis',
        'channel_mix',
        'ai_planning_context',
        'optimization_signals',
        'internal_linking_strategy',
        'metadata',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'status' => CampaignStatus::class,
        'approval_status' => CampaignApprovalStatus::class,
        'planned_start_date' => 'date',
        'planned_end_date' => 'date',
        'scheduled_start_at' => 'datetime',
        'scheduled_end_at' => 'datetime',
        'submitted_for_approval_at' => 'datetime',
        'approved_at' => 'datetime',
        'last_planned_at' => 'datetime',
        'audience' => 'array',
        'goals' => 'array',
        'kpis' => 'array',
        'channel_mix' => 'array',
        'ai_planning_context' => 'array',
        'optimization_signals' => 'array',
        'internal_linking_strategy' => 'array',
        'metadata' => 'array',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $campaign): void {
            if (! $campaign->slug) {
                $campaign->slug = Str::slug((string) $campaign->name) ?: (string) Str::uuid();
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function agenticMarketingObjective(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingObjective::class, 'agentic_marketing_objective_id');
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(CampaignCluster::class, 'campaign_cluster_id');
    }

    public function toneProfile(): BelongsTo
    {
        return $this->belongsTo(CampaignToneProfile::class, 'tone_profile_id');
    }

    public function ctaPreset(): BelongsTo
    {
        return $this->belongsTo(CampaignCtaPreset::class, 'cta_preset_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function contents(): HasMany
    {
        return $this->hasMany(CampaignContent::class)->orderBy('sequence_order');
    }

    public function distributionPlans(): HasMany
    {
        return $this->hasMany(CampaignDistributionPlan::class);
    }

    public function socialPostVariants(): HasMany
    {
        return $this->hasMany(SocialPostVariant::class);
    }

    public function socialPublications(): HasMany
    {
        return $this->hasMany(SocialPublication::class);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    public function contentRefreshTasks(): HasMany
    {
        return $this->hasMany(ContentRefreshTask::class);
    }

    public function learningProfile(): HasOne
    {
        return $this->hasOne(CampaignLearningProfile::class);
    }

    public function learningRecommendations(): HasMany
    {
        return $this->hasMany(LearningRecommendation::class)->latest('recommended_at');
    }

    public function isApproved(): bool
    {
        return $this->approval_status === CampaignApprovalStatus::APPROVED;
    }
}
