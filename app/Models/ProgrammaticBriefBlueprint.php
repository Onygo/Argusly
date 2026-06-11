<?php

namespace App\Models;

use App\Enums\GrowthAssetType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgrammaticBriefBlueprint extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CONVERTED = 'converted';

    protected $fillable = [
        'workspace_id',
        'growth_program_id',
        'programmatic_cluster_id',
        'programmatic_cluster_item_id',
        'growth_asset_type',
        'title',
        'slug',
        'intent',
        'audience',
        'primary_keyword',
        'secondary_keywords',
        'outline',
        'required_sections',
        'faq_questions',
        'schema_recommendations',
        'internal_linking_plan',
        'cta_recommendation',
        'seo_requirements',
        'ai_visibility_requirements',
        'quality_requirements',
        'status',
        'metadata',
    ];

    protected $casts = [
        'growth_asset_type' => GrowthAssetType::class,
        'secondary_keywords' => 'array',
        'outline' => 'array',
        'required_sections' => 'array',
        'faq_questions' => 'array',
        'schema_recommendations' => 'array',
        'internal_linking_plan' => 'array',
        'seo_requirements' => 'array',
        'ai_visibility_requirements' => 'array',
        'quality_requirements' => 'array',
        'metadata' => 'array',
    ];

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_REVIEWED,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_CONVERTED,
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

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(ProgrammaticCluster::class, 'programmatic_cluster_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ProgrammaticClusterItem::class, 'programmatic_cluster_item_id');
    }

    public function linkedBrief(): ?Brief
    {
        return Brief::query()
            ->where('client_refs->programmatic_brief_blueprint_id', (string) $this->id)
            ->first();
    }

    public function draftRequest()
    {
        return $this->hasOne(ProgrammaticDraftRequest::class, 'programmatic_brief_blueprint_id');
    }

    public function markReviewed(): self
    {
        $this->forceFill(['status' => self::STATUS_REVIEWED])->save();

        return $this->refresh();
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

    public function readinessPercentage(): int
    {
        $checks = [
            filled($this->title),
            filled($this->primary_keyword),
            filled($this->intent),
            count($this->outline ?? []) > 0,
            count($this->required_sections ?? []) > 0,
            count($this->schema_recommendations ?? []) > 0,
            count($this->seo_requirements ?? []) > 0,
            count($this->ai_visibility_requirements ?? []) > 0,
            count($this->quality_requirements ?? []) > 0,
            filled($this->cta_recommendation),
        ];

        return (int) round(collect($checks)->filter()->count() / count($checks) * 100);
    }
}
