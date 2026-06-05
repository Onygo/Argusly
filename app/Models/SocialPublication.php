<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\SocialPlatform;
use App\Enums\SocialPublicationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SocialPublication extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'social_account_id',
        'social_post_variant_id',
        'campaign_id',
        'campaign_distribution_plan_id',
        'platform',
        'status',
        'scheduled_for',
        'queued_at',
        'started_at',
        'published_at',
        'remote_post_id',
        'remote_url',
        'attempts',
        'last_attempt_at',
        'next_retry_at',
        'last_error_code',
        'last_error_message',
        'rate_limited_until',
        'payload_snapshot',
        'response_snapshot',
        'metadata',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'platform' => SocialPlatform::class,
        'status' => SocialPublicationStatus::class,
        'scheduled_for' => 'datetime',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'published_at' => 'datetime',
        'attempts' => 'integer',
        'last_attempt_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'rate_limited_until' => 'datetime',
        'payload_snapshot' => 'array',
        'response_snapshot' => 'array',
        'metadata' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(SocialPostVariant::class, 'social_post_variant_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function distributionPlan(): BelongsTo
    {
        return $this->belongsTo(CampaignDistributionPlan::class, 'campaign_distribution_plan_id');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(SocialEngagementMetric::class);
    }

    public function repostSuggestions(): HasMany
    {
        return $this->hasMany(SocialRepostSuggestion::class);
    }
}
