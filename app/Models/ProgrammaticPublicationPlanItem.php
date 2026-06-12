<?php

namespace App\Models;

use App\Enums\GrowthAssetType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgrammaticPublicationPlanItem extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PLANNED = 'planned';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_NEEDS_ATTENTION = 'needs_attention';

    protected $fillable = [
        'workspace_id',
        'programmatic_publication_plan_id',
        'content_id',
        'publication_readiness_id',
        'content_publication_id',
        'growth_asset_type',
        'title',
        'slug',
        'destination_id',
        'planned_publish_at',
        'status',
        'priority_score',
        'publication_risk_score',
        'metadata',
    ];

    protected $casts = [
        'growth_asset_type' => GrowthAssetType::class,
        'planned_publish_at' => 'datetime',
        'priority_score' => 'float',
        'publication_risk_score' => 'float',
        'metadata' => 'array',
    ];

    public static function statuses(): array
    {
        return [
            self::STATUS_PLANNED,
            self::STATUS_APPROVED,
            self::STATUS_SCHEDULED,
            self::STATUS_PUBLISHED,
            self::STATUS_SKIPPED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_CONFLICT,
            self::STATUS_NEEDS_ATTENTION,
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProgrammaticPublicationPlan::class, 'programmatic_publication_plan_id');
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function readiness(): BelongsTo
    {
        return $this->belongsTo(ProgrammaticPublicationReadiness::class, 'publication_readiness_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(ContentDestination::class, 'destination_id');
    }

    public function contentPublication(): BelongsTo
    {
        return $this->belongsTo(ContentPublication::class, 'content_publication_id');
    }

    public function linkedPublication(): ?ContentPublication
    {
        if ($this->content_publication_id) {
            $publication = ContentPublication::query()->whereKey($this->content_publication_id)->first();
            if ($publication) {
                return $publication;
            }
        }

        $publicationId = (string) data_get($this->metadata, 'content_publication_id', '');
        if ($publicationId !== '') {
            $publication = ContentPublication::query()->whereKey($publicationId)->first();
            if ($publication) {
                return $publication;
            }
        }

        return ContentPublication::query()
            ->where('meta->programmatic_publication_plan_item_id', (string) $this->id)
            ->first();
    }
}
