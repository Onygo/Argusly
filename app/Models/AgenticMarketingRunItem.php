<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgenticMarketingRunItem extends Model
{
    use HasUuids;

    public const TYPE_DETECTION = 'detection';
    public const TYPE_PLANNING = 'planning';
    public const TYPE_EXECUTION = 'execution';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'run_id',
        'objective_id',
        'opportunity_id',
        'action_id',
        'type',
        'name',
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

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingRun::class, 'run_id');
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingObjective::class, 'objective_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingOpportunity::class, 'opportunity_id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingAction::class, 'action_id');
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

    public function markSkipped(string $message): self
    {
        $this->forceFill([
            'status' => self::STATUS_SKIPPED,
            'error_message' => $message,
            'completed_at' => now(),
        ])->save();

        return $this;
    }
}
