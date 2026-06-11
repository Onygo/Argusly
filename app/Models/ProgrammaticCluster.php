<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\ProgrammaticPatternType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProgrammaticCluster extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;

    public const STATUS_PREVIEW = 'preview';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_PLANNED = 'planned';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'growth_program_id',
        'programmatic_opportunity_id',
        'name',
        'description',
        'pattern_type',
        'base_topic',
        'variable_axis',
        'status',
        'estimated_assets_count',
        'estimated_reach',
        'estimated_ai_visibility',
        'estimated_business_impact',
        'confidence_score',
        'metadata',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'pattern_type' => ProgrammaticPatternType::class,
        'estimated_assets_count' => 'integer',
        'estimated_reach' => 'float',
        'estimated_ai_visibility' => 'float',
        'estimated_business_impact' => 'float',
        'confidence_score' => 'float',
        'metadata' => 'array',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function growthProgram(): BelongsTo
    {
        return $this->belongsTo(GrowthProgram::class);
    }

    public function programmaticOpportunity(): BelongsTo
    {
        return $this->belongsTo(ProgrammaticOpportunity::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProgrammaticClusterItem::class);
    }

    public function validate(): self
    {
        $this->forceFill(['status' => self::STATUS_VALIDATED])->save();

        return $this->refresh();
    }

    public function reject(): self
    {
        $this->forceFill(['status' => self::STATUS_REJECTED])->save();

        return $this->refresh();
    }
}
