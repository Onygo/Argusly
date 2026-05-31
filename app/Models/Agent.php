<?php

namespace App\Models;

use App\Models\Concerns\HasTopics;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'key',
    'name',
    'description',
    'status',
    'capabilities',
])]
class Agent extends Model
{
    use HasFactory, HasTopics;

    public const STATUSES = ['active', 'idle', 'paused'];

    protected static function booted(): void
    {
        static::creating(function (Agent $agent): void {
            $agent->uuid ??= (string) Str::uuid();
            $agent->status ??= 'idle';
        });

        static::saving(function (Agent $agent): void {
            if (! in_array($agent->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid agent status [{$agent->status}].");
            }
        });
    }

    /**
     * @return HasMany<AgentRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    /**
     * @return HasMany<AgentTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(AgentTask::class);
    }

    /**
     * @param  Builder<Agent>  $query
     * @return Builder<Agent>
     */
    public function scopeRunnable(Builder $query): Builder
    {
        return $query->whereIn('status', ['active', 'idle']);
    }

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
        ];
    }
}
