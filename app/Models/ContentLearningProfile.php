<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentLearningProfile extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'content_id',
        'client_site_id',
        'performance_score',
        'article_score',
        'linkedin_score',
        'ai_visibility_score',
        'conversion_score',
        'cta_score',
        'hook_score',
        'tone_score',
        'topic_score',
        'primary_topic',
        'hook_analysis',
        'cta_analysis',
        'tone_analysis',
        'topic_analysis',
        'ai_visibility_trend',
        'historical_trends',
        'score_breakdown',
        'evidence',
        'analyzed_at',
    ];

    protected $casts = [
        'performance_score' => 'float',
        'article_score' => 'float',
        'linkedin_score' => 'float',
        'ai_visibility_score' => 'float',
        'conversion_score' => 'float',
        'cta_score' => 'float',
        'hook_score' => 'float',
        'tone_score' => 'float',
        'topic_score' => 'float',
        'hook_analysis' => 'array',
        'cta_analysis' => 'array',
        'tone_analysis' => 'array',
        'topic_analysis' => 'array',
        'ai_visibility_trend' => 'array',
        'historical_trends' => 'array',
        'score_breakdown' => 'array',
        'evidence' => 'array',
        'analyzed_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(LearningRecommendation::class);
    }
}
