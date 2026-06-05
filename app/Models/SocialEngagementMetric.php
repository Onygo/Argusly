<?php

namespace App\Models;

use App\Enums\SocialPlatform;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialEngagementMetric extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'social_account_id',
        'social_publication_id',
        'platform',
        'measured_at',
        'impressions',
        'reach',
        'likes',
        'comments',
        'shares',
        'clicks',
        'follows',
        'engagement_rate',
        'raw_metrics',
    ];

    protected $casts = [
        'platform' => SocialPlatform::class,
        'measured_at' => 'datetime',
        'impressions' => 'integer',
        'reach' => 'integer',
        'likes' => 'integer',
        'comments' => 'integer',
        'shares' => 'integer',
        'clicks' => 'integer',
        'follows' => 'integer',
        'engagement_rate' => 'float',
        'raw_metrics' => 'array',
    ];

    public function publication(): BelongsTo
    {
        return $this->belongsTo(SocialPublication::class, 'social_publication_id');
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
