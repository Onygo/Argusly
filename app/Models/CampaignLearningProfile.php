<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignLearningProfile extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'campaign_id',
        'performance_score',
        'content_score',
        'distribution_score',
        'ai_visibility_score',
        'conversion_score',
        'content_mix_analysis',
        'channel_analysis',
        'tone_analysis',
        'topic_analysis',
        'historical_trends',
        'score_breakdown',
        'evidence',
        'analyzed_at',
    ];

    protected $casts = [
        'performance_score' => 'float',
        'content_score' => 'float',
        'distribution_score' => 'float',
        'ai_visibility_score' => 'float',
        'conversion_score' => 'float',
        'content_mix_analysis' => 'array',
        'channel_analysis' => 'array',
        'tone_analysis' => 'array',
        'topic_analysis' => 'array',
        'historical_trends' => 'array',
        'score_breakdown' => 'array',
        'evidence' => 'array',
        'analyzed_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(LearningRecommendation::class);
    }
}
