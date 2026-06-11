<?php

namespace App\Models;

use App\Enums\GrowthAssetType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use InvalidArgumentException;

class ProgrammaticDraftReview extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PASSED = 'passed';
    public const STATUS_NEEDS_WORK = 'needs_work';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'workspace_id',
        'growth_program_id',
        'programmatic_draft_request_id',
        'draft_id',
        'brief_id',
        'programmatic_cluster_id',
        'programmatic_cluster_item_id',
        'growth_asset_type',
        'status',
        'overall_score',
        'seo_score',
        'ai_visibility_score',
        'duplication_score',
        'brand_fit_score',
        'completeness_score',
        'schema_readiness_score',
        'internal_linking_score',
        'risk_score',
        'checks',
        'recommendations',
        'blocking_issues',
        'reviewer_id',
        'reviewed_at',
        'metadata',
    ];

    protected $casts = [
        'growth_asset_type' => GrowthAssetType::class,
        'overall_score' => 'float',
        'seo_score' => 'float',
        'ai_visibility_score' => 'float',
        'duplication_score' => 'float',
        'brand_fit_score' => 'float',
        'completeness_score' => 'float',
        'schema_readiness_score' => 'float',
        'internal_linking_score' => 'float',
        'risk_score' => 'float',
        'checks' => 'array',
        'recommendations' => 'array',
        'blocking_issues' => 'array',
        'reviewed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_PASSED,
            self::STATUS_NEEDS_WORK,
            self::STATUS_BLOCKED,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ProgrammaticDraftRequest::class, 'programmatic_draft_request_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }

    public function brief(): BelongsTo
    {
        return $this->belongsTo(Brief::class);
    }

    public function growthProgram(): BelongsTo
    {
        return $this->belongsTo(GrowthProgram::class);
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(ProgrammaticCluster::class, 'programmatic_cluster_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ProgrammaticClusterItem::class, 'programmatic_cluster_item_id');
    }

    public function publicationReadiness(): HasOne
    {
        return $this->hasOne(ProgrammaticPublicationReadiness::class, 'programmatic_draft_review_id');
    }

    public function linkedContent(): ?Content
    {
        $contentId = (string) data_get($this->metadata, 'converted_content_id', '');
        if ($contentId !== '') {
            $content = Content::query()->whereKey($contentId)->first();
            if ($content) {
                return $content;
            }
        }

        if ($this->draft?->content_id) {
            return Content::query()->whereKey($this->draft->content_id)->first();
        }

        return Content::query()
            ->where('workspace_id', $this->workspace_id)
            ->where('external_key', 'programmatic-draft-review-'.$this->id)
            ->first();
    }

    public function approve(?User $reviewer = null, bool $override = false): self
    {
        if ($this->status === self::STATUS_BLOCKED && ! $override) {
            throw new InvalidArgumentException('Blocked reviews require an explicit override before approval.');
        }

        if (! in_array($this->status, [self::STATUS_PASSED, self::STATUS_NEEDS_WORK, self::STATUS_APPROVED], true) && ! $override) {
            throw new InvalidArgumentException('Only passed or needs work reviews can be approved.');
        }

        $this->forceFill([
            'status' => self::STATUS_APPROVED,
            'reviewer_id' => $reviewer?->id,
            'reviewed_at' => now(),
        ])->save();

        return $this->refresh();
    }

    public function needsWork(?User $reviewer = null): self
    {
        $this->forceFill(['status' => self::STATUS_NEEDS_WORK, 'reviewer_id' => $reviewer?->id, 'reviewed_at' => now()])->save();

        return $this->refresh();
    }

    public function block(?User $reviewer = null): self
    {
        $this->forceFill(['status' => self::STATUS_BLOCKED, 'reviewer_id' => $reviewer?->id, 'reviewed_at' => now()])->save();

        return $this->refresh();
    }

    public function reject(?User $reviewer = null): self
    {
        $this->forceFill(['status' => self::STATUS_REJECTED, 'reviewer_id' => $reviewer?->id, 'reviewed_at' => now()])->save();

        return $this->refresh();
    }
}
