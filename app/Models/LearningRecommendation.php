<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\LearningRecommendationStatus;
use App\Enums\LearningRecommendationType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LearningRecommendation extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'content_id',
        'campaign_id',
        'content_learning_profile_id',
        'campaign_learning_profile_id',
        'type',
        'status',
        'priority_score',
        'confidence_score',
        'title',
        'summary',
        'recommended_actions',
        'explanation',
        'evidence',
        'expected_impact',
        'recommended_at',
        'actioned_at',
    ];

    protected $casts = [
        'type' => LearningRecommendationType::class,
        'status' => LearningRecommendationStatus::class,
        'priority_score' => 'float',
        'confidence_score' => 'float',
        'recommended_actions' => 'array',
        'explanation' => 'array',
        'evidence' => 'array',
        'expected_impact' => 'array',
        'recommended_at' => 'datetime',
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

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function contentLearningProfile(): BelongsTo
    {
        return $this->belongsTo(ContentLearningProfile::class);
    }

    public function campaignLearningProfile(): BelongsTo
    {
        return $this->belongsTo(CampaignLearningProfile::class);
    }
}
