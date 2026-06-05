<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Opportunity extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'content_id',
        'content_cluster_id',
        'campaign_id',
        'content_opportunity_id',
        'agentic_marketing_opportunity_id',
        'category',
        'status',
        'title',
        'topic',
        'summary',
        'priority_score',
        'confidence_score',
        'impact_score',
        'urgency_score',
        'effort_score',
        'score_breakdown',
        'recommended_actions',
        'evidence',
        'source_signal_summary',
        'metadata',
        'dedupe_hash',
        'first_seen_at',
        'last_seen_at',
        'planned_at',
        'actioned_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'category' => OpportunityCategory::class,
        'status' => OpportunityStatus::class,
        'priority_score' => 'float',
        'confidence_score' => 'float',
        'impact_score' => 'float',
        'urgency_score' => 'float',
        'effort_score' => 'float',
        'score_breakdown' => 'array',
        'recommended_actions' => 'array',
        'evidence' => 'array',
        'source_signal_summary' => 'array',
        'metadata' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'planned_at' => 'datetime',
        'actioned_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function contentCluster(): BelongsTo
    {
        return $this->belongsTo(ContentCluster::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function contentOpportunity(): BelongsTo
    {
        return $this->belongsTo(ContentOpportunity::class);
    }

    public function agenticMarketingOpportunity(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingOpportunity::class);
    }

    public function signals(): BelongsToMany
    {
        return $this->belongsToMany(OpportunitySignal::class, 'opportunity_signal_links')
            ->withPivot(['weight', 'contribution'])
            ->withTimestamps();
    }
}
