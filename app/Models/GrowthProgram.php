<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\GrowthProgramStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class GrowthProgram extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'name',
        'description',
        'status',
        'owner_user_id',
        'score',
        'estimated_impact',
        'estimated_reach',
        'estimated_ai_visibility_impact',
        'metrics',
        'metadata',
        'detected_at',
        'qualified_at',
        'planned_at',
        'briefed_at',
        'drafting_at',
        'review_at',
        'scheduled_at',
        'published_at',
        'measured_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'status' => GrowthProgramStatus::class,
        'score' => 'float',
        'estimated_impact' => 'float',
        'estimated_reach' => 'float',
        'estimated_ai_visibility_impact' => 'float',
        'metrics' => 'array',
        'metadata' => 'array',
        'detected_at' => 'datetime',
        'qualified_at' => 'datetime',
        'planned_at' => 'datetime',
        'briefed_at' => 'datetime',
        'drafting_at' => 'datetime',
        'review_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
        'measured_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(GrowthRun::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(GrowthAsset::class);
    }

    public function progress(): int
    {
        $status = $this->status instanceof GrowthProgramStatus
            ? $this->status
            : GrowthProgramStatus::tryFrom((string) $this->status) ?? GrowthProgramStatus::DETECTED;

        return $status->progress();
    }
}
