<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\ProgrammaticPatternType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ProgrammaticOpportunity extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;

    public const STATUS_DETECTED = 'detected';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_PLANNED = 'planned';
    public const STATUS_EXPANDED = 'expanded';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'growth_program_id',
        'source_type',
        'source_id',
        'pattern_type',
        'base_topic',
        'variable_axis',
        'example_variables',
        'estimated_variants_count',
        'scale_score',
        'business_value_score',
        'seo_opportunity_score',
        'ai_visibility_score',
        'competition_score',
        'confidence_score',
        'status',
        'explanation',
        'metadata',
        'detected_at',
        'validated_at',
        'rejected_at',
        'planned_at',
        'expanded_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'pattern_type' => ProgrammaticPatternType::class,
        'example_variables' => 'array',
        'estimated_variants_count' => 'integer',
        'scale_score' => 'float',
        'business_value_score' => 'float',
        'seo_opportunity_score' => 'float',
        'ai_visibility_score' => 'float',
        'competition_score' => 'float',
        'confidence_score' => 'float',
        'explanation' => 'array',
        'metadata' => 'array',
        'detected_at' => 'datetime',
        'validated_at' => 'datetime',
        'rejected_at' => 'datetime',
        'planned_at' => 'datetime',
        'expanded_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function growthProgram(): BelongsTo
    {
        return $this->belongsTo(GrowthProgram::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    public function validate(): self
    {
        $this->forceFill([
            'status' => self::STATUS_VALIDATED,
            'validated_at' => now(),
            'rejected_at' => null,
        ])->save();

        return $this->refresh();
    }

    public function reject(): self
    {
        $this->forceFill([
            'status' => self::STATUS_REJECTED,
            'rejected_at' => now(),
        ])->save();

        return $this->refresh();
    }
}
