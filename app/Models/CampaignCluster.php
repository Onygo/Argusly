<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignCluster extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasUuids;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PLANNED = 'planned';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'organization_id', 'workspace_id', 'client_site_id', 'campaign_cluster_run_id',
        'status', 'name', 'primary_entity', 'primary_topic', 'authority_strategy',
        'cta_strategy', 'refresh_cadence', 'planned_start_date', 'planned_end_date',
        'authority_score', 'topical_coverage_score', 'funnel_coverage_score',
        'ai_visibility_score', 'completeness_score', 'funnel_coverage',
        'internal_link_architecture', 'localization_strategy', 'publishing_sequence',
        'timeline', 'visual_map', 'missing_coverage', 'authority_gaps',
        'source_signals', 'dedupe_hash',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'planned_start_date' => 'date',
        'planned_end_date' => 'date',
        'authority_score' => 'float',
        'topical_coverage_score' => 'float',
        'funnel_coverage_score' => 'float',
        'ai_visibility_score' => 'float',
        'completeness_score' => 'float',
        'funnel_coverage' => 'array',
        'internal_link_architecture' => 'array',
        'localization_strategy' => 'array',
        'publishing_sequence' => 'array',
        'timeline' => 'array',
        'visual_map' => 'array',
        'missing_coverage' => 'array',
        'authority_gaps' => 'array',
        'source_signals' => 'array',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class, 'client_site_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CampaignClusterRun::class, 'campaign_cluster_run_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CampaignClusterItem::class, 'campaign_cluster_id')->orderBy('sequence_order');
    }

    public function dependencies(): HasMany
    {
        return $this->hasMany(CampaignClusterDependency::class, 'campaign_cluster_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'campaign_cluster_id');
    }
}
