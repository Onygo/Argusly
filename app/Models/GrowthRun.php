<?php

namespace App\Models;

use App\Enums\GrowthProgramStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GrowthRun extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'growth_program_id',
        'status',
        'stage',
        'triggered_by',
        'input',
        'result',
        'metrics_snapshot',
        'failure_reason',
        'started_by',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'input' => 'array',
        'result' => 'array',
        'metrics_snapshot' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(GrowthProgram::class, 'growth_program_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(GrowthAsset::class);
    }

    public function stageStatus(): GrowthProgramStatus
    {
        return GrowthProgramStatus::tryFrom((string) $this->stage) ?? GrowthProgramStatus::DETECTED;
    }
}
