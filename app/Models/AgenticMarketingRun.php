<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgenticMarketingRun extends Model
{
    use HasUuids;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'objective_id',
        'status',
        'payload',
        'result',
        'error_message',
        'started_at',
        'completed_at',
        'failed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function objective(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingObjective::class, 'objective_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AgenticMarketingAction::class, 'run_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AgenticMarketingRunItem::class, 'run_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AgenticMarketingAuditLog::class, 'run_id');
    }

    public function markRunning(): self
    {
        $this->forceFill([
            'status' => self::STATUS_RUNNING,
            'started_at' => $this->started_at ?: now(),
            'failed_at' => null,
            'error_message' => null,
        ])->save();

        return $this;
    }

    public function markCompleted(array $result = []): self
    {
        $this->forceFill([
            'status' => self::STATUS_COMPLETED,
            'result' => $result,
            'completed_at' => now(),
            'failed_at' => null,
            'error_message' => null,
        ])->save();

        return $this;
    }

    public function markFailed(string $message, array $result = []): self
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'result' => $result ?: $this->result,
            'error_message' => mb_substr($message, 0, 5000),
            'failed_at' => now(),
            'completed_at' => null,
        ])->save();

        return $this;
    }
}
