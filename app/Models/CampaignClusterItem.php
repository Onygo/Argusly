<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignClusterItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'campaign_cluster_id', 'content_opportunity_id', 'content_id', 'type',
        'status', 'title', 'target_entity', 'funnel_stage', 'search_intent',
        'sequence_order', 'planned_publish_date', 'authority_contribution',
        'coverage_contribution', 'payload',
    ];

    protected $casts = [
        'sequence_order' => 'integer',
        'planned_publish_date' => 'date',
        'authority_contribution' => 'float',
        'coverage_contribution' => 'float',
        'payload' => 'array',
    ];

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(CampaignCluster::class, 'campaign_cluster_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(ContentOpportunity::class, 'content_opportunity_id');
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function outgoingDependencies(): HasMany
    {
        return $this->hasMany(CampaignClusterDependency::class, 'source_item_id');
    }

    public function incomingDependencies(): HasMany
    {
        return $this->hasMany(CampaignClusterDependency::class, 'target_item_id');
    }
}
