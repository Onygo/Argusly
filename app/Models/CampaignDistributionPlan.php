<?php

namespace App\Models;

use App\Enums\CampaignContentAssetType;
use App\Enums\DistributionPlanStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CampaignDistributionPlan extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'campaign_id',
        'campaign_content_id',
        'distribution_channel_id',
        'asset_type',
        'status',
        'scheduled_for',
        'queued_at',
        'distributed_at',
        'payload',
        'planning_notes',
        'result',
        'last_error',
    ];

    protected $casts = [
        'asset_type' => CampaignContentAssetType::class,
        'status' => DistributionPlanStatus::class,
        'scheduled_for' => 'datetime',
        'queued_at' => 'datetime',
        'distributed_at' => 'datetime',
        'payload' => 'array',
        'planning_notes' => 'array',
        'result' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function campaignContent(): BelongsTo
    {
        return $this->belongsTo(CampaignContent::class);
    }

    public function distributionChannel(): BelongsTo
    {
        return $this->belongsTo(DistributionChannel::class);
    }

    public function socialPostVariants(): HasMany
    {
        return $this->hasMany(SocialPostVariant::class);
    }

    public function socialPublications(): HasMany
    {
        return $this->hasMany(SocialPublication::class);
    }
}
