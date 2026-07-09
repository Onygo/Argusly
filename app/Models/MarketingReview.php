<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingReview extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_CHANGES_REQUESTED = 'changes_requested';
    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'marketing_objective_id',
        'marketing_initiative_id',
        'reviewer_id',
        'review_type',
        'status',
        'decision',
        'summary',
        'evidence_json',
        'due_at',
        'reviewed_at',
        'metadata_json',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'evidence_json' => 'array',
        'due_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'metadata_json' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(MarketingObjective::class, 'marketing_objective_id');
    }

    public function initiative(): BelongsTo
    {
        return $this->belongsTo(MarketingInitiative::class, 'marketing_initiative_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
