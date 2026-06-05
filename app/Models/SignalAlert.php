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
    'account_id',
    'brand_id',
    'intelligence_signal_id',
    'source_type',
    'source_id',
    'severity',
    'status',
    'title',
    'body',
    'payload',
    'triggered_at',
    'acknowledged_by',
    'acknowledged_at',
    'resolved_by',
    'resolved_at',
])]
class SignalAlert extends Model
{
    use HasFactory;

    public const SEVERITIES = ['info', 'low', 'medium', 'high', 'critical'];

    public const STATUSES = ['open', 'acknowledged', 'resolved', 'suppressed'];

    protected static function booted(): void
    {
        static::creating(function (SignalAlert $alert): void {
            $alert->uuid ??= (string) Str::uuid();
            $alert->status ??= 'open';
            $alert->triggered_at ??= now();
        });

        static::saving(function (SignalAlert $alert): void {
            if (! in_array($alert->severity, self::SEVERITIES, true)) {
                throw new InvalidArgumentException("Invalid alert severity [{$alert->severity}].");
            }

            if (! in_array($alert->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid alert status [{$alert->status}].");
            }

            if ($alert->brand_id !== null) {
                $brand = Brand::query()->find($alert->brand_id);

                if (! $brand || $brand->account_id !== $alert->account_id) {
                    throw new InvalidArgumentException('Alert brand must belong to the same account.');
                }
            }

            if ($alert->intelligence_signal_id !== null) {
                $signal = IntelligenceSignal::query()->find($alert->intelligence_signal_id);

                if (! $signal || $signal->account_id !== $alert->account_id || $signal->brand_id !== $alert->brand_id) {
                    throw new InvalidArgumentException('Alert signal must belong to the same tenant scope.');
                }
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(IntelligenceSignal::class, 'intelligence_signal_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'acknowledged']);
    }

    public function acknowledge(?User $user = null): bool
    {
        return $this->forceFill([
            'status' => 'acknowledged',
            'acknowledged_by' => $user?->id,
            'acknowledged_at' => now(),
        ])->save();
    }

    public function resolve(?User $user = null): bool
    {
        return $this->forceFill([
            'status' => 'resolved',
            'resolved_by' => $user?->id,
            'resolved_at' => now(),
        ])->save();
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'triggered_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
