<?php

namespace App\Models;

use App\Enums\GrowthAssetType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgrammaticDraftRequest extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_GENERATED = 'generated';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const MODE_MANUAL = 'manual';
    public const MODE_SUPERVISED = 'supervised';
    public const MODE_BATCH = 'batch';

    protected $fillable = [
        'workspace_id',
        'growth_program_id',
        'programmatic_brief_blueprint_id',
        'brief_id',
        'programmatic_cluster_id',
        'programmatic_cluster_item_id',
        'growth_asset_type',
        'title',
        'slug',
        'priority_score',
        'estimated_cost',
        'estimated_tokens',
        'status',
        'generation_mode',
        'metadata',
    ];

    protected $casts = [
        'growth_asset_type' => GrowthAssetType::class,
        'priority_score' => 'float',
        'estimated_cost' => 'float',
        'estimated_tokens' => 'integer',
        'metadata' => 'array',
    ];

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_QUEUED,
            self::STATUS_GENERATED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
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

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(ProgrammaticBriefBlueprint::class, 'programmatic_brief_blueprint_id');
    }

    public function brief(): BelongsTo
    {
        return $this->belongsTo(Brief::class);
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(ProgrammaticCluster::class, 'programmatic_cluster_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ProgrammaticClusterItem::class, 'programmatic_cluster_item_id');
    }

    public function linkedDraft(): ?Draft
    {
        $draftId = (string) data_get($this->metadata, 'generated_draft_id', '');
        if ($draftId !== '') {
            $draft = Draft::query()->whereKey($draftId)->first();
            if ($draft) {
                return $draft;
            }
        }

        return Draft::query()
            ->where('brief_id', $this->brief_id)
            ->get()
            ->first(fn (Draft $draft): bool => (string) data_get($draft->meta, 'programmatic_draft_request_id') === (string) $this->id);
    }

    public function review()
    {
        return $this->hasOne(ProgrammaticDraftReview::class, 'programmatic_draft_request_id');
    }

    public function approve(): self
    {
        $this->forceFill(['status' => self::STATUS_APPROVED])->save();

        return $this->refresh();
    }

    public function reject(): self
    {
        $this->forceFill(['status' => self::STATUS_REJECTED])->save();

        return $this->refresh();
    }

    public function cancel(): self
    {
        $this->forceFill(['status' => self::STATUS_CANCELLED])->save();

        return $this->refresh();
    }
}
