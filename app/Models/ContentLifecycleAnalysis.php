<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\ContentDecayRiskLevel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentLifecycleAnalysis extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'content_id',
        'workspace_id',
        'client_site_id',
        'lifecycle_score',
        'decay_score',
        'decay_risk_level',
        'refresh_priority_score',
        'confidence_score',
        'signals',
        'score_breakdown',
        'refresh_recommendations',
        'campaign_reconnect_suggestions',
        'related_content_suggestions',
        'internal_linking_suggestions',
        'analyzed_at',
    ];

    protected $casts = [
        'lifecycle_score' => 'float',
        'decay_score' => 'float',
        'decay_risk_level' => ContentDecayRiskLevel::class,
        'refresh_priority_score' => 'float',
        'confidence_score' => 'float',
        'signals' => 'array',
        'score_breakdown' => 'array',
        'refresh_recommendations' => 'array',
        'campaign_reconnect_suggestions' => 'array',
        'related_content_suggestions' => 'array',
        'internal_linking_suggestions' => 'array',
        'analyzed_at' => 'datetime',
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function refreshTasks(): HasMany
    {
        return $this->hasMany(ContentRefreshTask::class);
    }
}
