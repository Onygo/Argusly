<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignClusterDependency extends Model
{
    use HasUuids;

    protected $fillable = [
        'campaign_cluster_id', 'source_item_id', 'target_item_id', 'type',
        'anchor_text', 'reason', 'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(CampaignCluster::class, 'campaign_cluster_id');
    }

    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(CampaignClusterItem::class, 'source_item_id');
    }

    public function targetItem(): BelongsTo
    {
        return $this->belongsTo(CampaignClusterItem::class, 'target_item_id');
    }
}
