<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'agent_id',
    'agent_run_id',
    'account_id',
    'brand_id',
    'recommendation_id',
    'title',
    'description',
    'status',
    'payload',
    'dispatched_at',
    'completed_at',
])]
class AgentTask extends Model
{
    use HasFactory;

    public const STATUSES = ['pending', 'approved', 'queued', 'dispatched', 'running', 'completed', 'failed', 'cancelled'];

    protected static function booted(): void
    {
        static::creating(function (AgentTask $task): void {
            $task->uuid ??= (string) Str::uuid();
            $task->status ??= 'pending';
        });

        static::saving(function (AgentTask $task): void {
            if (! in_array($task->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid agent task status [{$task->status}].");
            }

            if ($task->brand_id !== null) {
                $brand = Brand::query()->find($task->brand_id);

                if (! $brand || $brand->account_id !== $task->account_id) {
                    throw new InvalidArgumentException('Agent task brand must belong to the task account.');
                }
            }

            if ($task->recommendation_id !== null) {
                $recommendation = Recommendation::query()->find($task->recommendation_id);

                if (! $recommendation || $recommendation->account_id !== $task->account_id || $recommendation->brand_id !== $task->brand_id) {
                    throw new InvalidArgumentException('Agent task recommendation must belong to the same account and brand scope.');
                }
            }
        });
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return BelongsTo<AgentRun, $this>
     */
    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<Recommendation, $this>
     */
    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class);
    }

    /**
     * @param  Builder<AgentTask>  $query
     * @return Builder<AgentTask>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'approved', 'queued', 'dispatched', 'running']);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'dispatched_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
