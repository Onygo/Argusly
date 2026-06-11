<?php

namespace App\Models;

use App\Enums\GrowthAssetType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class ProgrammaticPublicationReadiness extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_READY = 'ready';
    public const STATUS_NEEDS_WORK = 'needs_work';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $table = 'programmatic_publication_readiness';

    protected $fillable = [
        'workspace_id',
        'growth_program_id',
        'content_id',
        'programmatic_draft_review_id',
        'programmatic_draft_request_id',
        'programmatic_cluster_id',
        'programmatic_cluster_item_id',
        'growth_asset_type',
        'status',
        'readiness_score',
        'seo_score',
        'schema_score',
        'internal_linking_score',
        'publication_risk_score',
        'destination_readiness_score',
        'checks',
        'missing_requirements',
        'recommendations',
        'approved_by',
        'approved_at',
        'metadata',
    ];

    protected $casts = [
        'growth_asset_type' => GrowthAssetType::class,
        'readiness_score' => 'float',
        'seo_score' => 'float',
        'schema_score' => 'float',
        'internal_linking_score' => 'float',
        'publication_risk_score' => 'float',
        'destination_readiness_score' => 'float',
        'checks' => 'array',
        'missing_requirements' => 'array',
        'recommendations' => 'array',
        'approved_at' => 'datetime',
        'metadata' => 'array',
    ];

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_READY,
            self::STATUS_NEEDS_WORK,
            self::STATUS_BLOCKED,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function growthProgram(): BelongsTo
    {
        return $this->belongsTo(GrowthProgram::class);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(ProgrammaticDraftReview::class, 'programmatic_draft_review_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ProgrammaticDraftRequest::class, 'programmatic_draft_request_id');
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(ProgrammaticCluster::class, 'programmatic_cluster_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ProgrammaticClusterItem::class, 'programmatic_cluster_item_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function planItems(): HasMany
    {
        return $this->hasMany(ProgrammaticPublicationPlanItem::class, 'publication_readiness_id');
    }

    public function approve(?User $user = null, bool $override = false): self
    {
        if ($this->status === self::STATUS_BLOCKED && ! $override) {
            throw new InvalidArgumentException('Blocked publication readiness requires an explicit override before approval.');
        }

        if (! in_array($this->status, [self::STATUS_READY, self::STATUS_NEEDS_WORK, self::STATUS_APPROVED], true) && ! $override) {
            throw new InvalidArgumentException('Only ready or needs work publication readiness can be approved.');
        }

        $this->forceFill([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $user?->id,
            'approved_at' => now(),
        ])->save();

        return $this->refresh();
    }

    public function needsWork(): self
    {
        $this->forceFill(['status' => self::STATUS_NEEDS_WORK])->save();

        return $this->refresh();
    }

    public function block(): self
    {
        $this->forceFill(['status' => self::STATUS_BLOCKED])->save();

        return $this->refresh();
    }

    public function reject(): self
    {
        $this->forceFill(['status' => self::STATUS_REJECTED])->save();

        return $this->refresh();
    }
}
