<?php

namespace App\Models;

use App\Models\Concerns\RecordsDomainEvents;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'agent_id',
    'account_id',
    'brand_id',
    'started_at',
    'completed_at',
    'status',
    'result',
])]
class AgentRun extends Model
{
    use HasFactory, RecordsDomainEvents;

    public const STATUSES = ['queued', 'running', 'completed', 'failed', 'cancelled'];

    protected static function booted(): void
    {
        static::creating(function (AgentRun $run): void {
            $run->uuid ??= (string) Str::uuid();
            $run->status ??= 'queued';
        });

        static::saving(function (AgentRun $run): void {
            if (! in_array($run->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid agent run status [{$run->status}].");
            }

            if ($run->brand_id !== null) {
                $brand = Brand::query()->find($run->brand_id);

                if (! $brand || $brand->account_id !== $run->account_id) {
                    throw new InvalidArgumentException('Agent run brand must belong to the run account.');
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
     * @return HasMany<AgentTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(AgentTask::class);
    }

    /**
     * @param  Builder<AgentRun>  $query
     * @return Builder<AgentRun>
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->latest('started_at')->latest();
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'result' => 'array',
        ];
    }
}
