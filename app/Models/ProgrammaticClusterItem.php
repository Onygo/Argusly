<?php

namespace App\Models;

use App\Enums\GrowthAssetType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProgrammaticClusterItem extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PREVIEW = 'preview';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_PLANNED = 'planned';

    protected $fillable = [
        'workspace_id',
        'programmatic_cluster_id',
        'variable_value',
        'title',
        'slug',
        'asset_type',
        'growth_asset_type',
        'intent',
        'priority_score',
        'seo_score',
        'ai_visibility_score',
        'business_value_score',
        'recommended_word_count_min',
        'recommended_word_count_max',
        'recommended_schema_types',
        'recommended_cta',
        'internal_linking_role',
        'briefing_requirements',
        'ai_visibility_requirements',
        'seo_requirements',
        'duplicate_risk_score',
        'canonical_group_key',
        'status',
        'metadata',
    ];

    protected $casts = [
        'growth_asset_type' => GrowthAssetType::class,
        'priority_score' => 'float',
        'seo_score' => 'float',
        'ai_visibility_score' => 'float',
        'business_value_score' => 'float',
        'recommended_word_count_min' => 'integer',
        'recommended_word_count_max' => 'integer',
        'recommended_schema_types' => 'array',
        'briefing_requirements' => 'array',
        'ai_visibility_requirements' => 'array',
        'seo_requirements' => 'array',
        'duplicate_risk_score' => 'float',
        'metadata' => 'array',
    ];

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(ProgrammaticCluster::class, 'programmatic_cluster_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function briefBlueprint(): HasOne
    {
        return $this->hasOne(ProgrammaticBriefBlueprint::class, 'programmatic_cluster_item_id');
    }
}
