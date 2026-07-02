<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiTransparencyRecord extends Model
{
    use HasUuids;

    public const ORIGIN_UNKNOWN = 'unknown';
    public const ORIGIN_HUMAN = 'human';
    public const ORIGIN_AI_ASSISTED = 'ai_assisted';
    public const ORIGIN_AI_GENERATED = 'ai_generated';
    public const ORIGIN_AI_EDITED = 'ai_edited';

    public const REVIEW_NOT_REVIEWED = 'not_reviewed';
    public const REVIEW_REVIEWED = 'reviewed';
    public const REVIEW_APPROVED = 'approved';
    public const REVIEW_NEEDS_CHANGES = 'needs_changes';
    public const REVIEW_REJECTED = 'rejected';

    public const FACT_UNCHECKED = 'unchecked';
    public const FACT_SUPPORTED = 'supported';
    public const FACT_PARTIAL = 'partial';
    public const FACT_CONFLICTING = 'conflicting';
    public const FACT_NEEDS_REVIEW = 'needs_human_review';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'content_id',
        'draft_id',
        'asset_type',
        'asset_id',
        'origin',
        'ai_badge',
        'disclosure_label',
        'human_review_status',
        'fact_check_status',
        'trust_score',
        'metadata_standard',
        'content_hash',
        'last_reviewed_at',
        'last_fact_checked_at',
        'metadata_exported_at',
        'machine_metadata',
        'score_breakdown',
    ];

    protected $casts = [
        'trust_score' => 'integer',
        'last_reviewed_at' => 'datetime',
        'last_fact_checked_at' => 'datetime',
        'metadata_exported_at' => 'datetime',
        'machine_metadata' => 'array',
        'score_breakdown' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AiProvenanceEvent::class)->latest('occurred_at');
    }

    public function chronologicalEvents(): HasMany
    {
        return $this->hasMany(AiProvenanceEvent::class)->oldest('occurred_at');
    }

    public function modelRuns(): HasMany
    {
        return $this->hasMany(AiModelRun::class)->latest('ran_at');
    }

    public function promptVersions(): HasMany
    {
        return $this->hasMany(AiPromptVersion::class)->orderBy('version');
    }

    public function sourceTraces(): HasMany
    {
        return $this->hasMany(AiSourceTrace::class)->latest('retrieved_at');
    }

    public function factChecks(): HasMany
    {
        return $this->hasMany(AiFactCheck::class)->latest('reviewed_at');
    }

    public function humanReviews(): HasMany
    {
        return $this->hasMany(AiHumanReview::class)->latest('reviewed_at');
    }

    public function auditReports(): HasMany
    {
        return $this->hasMany(AiAuditReport::class)->latest('generated_at');
    }
}
