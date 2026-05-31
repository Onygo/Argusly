<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'subject_type',
    'subject_id',
    'requested_by',
    'approved_by',
    'rejected_by',
    'status',
    'notes',
    'requested_at',
    'decided_at',
])]
class Approval extends Model
{
    use HasFactory;

    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    protected static function booted(): void
    {
        static::creating(function (Approval $approval): void {
            $approval->uuid ??= (string) Str::uuid();
            $approval->requested_at ??= now();
            $approval->status ??= 'pending';
        });

        static::saving(function (Approval $approval): void {
            if (! in_array($approval->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid approval status [{$approval->status}].");
            }

            if ($approval->brand_id !== null) {
                $brand = Brand::query()->find($approval->brand_id);

                if (! $brand || $brand->account_id !== $approval->account_id) {
                    throw new InvalidArgumentException('Approval brand must belong to the same account.');
                }
            }
        });

        static::saved(function (Approval $approval): void {
            app(\App\Services\MarketingCalendarService::class)->syncApproval($approval);
        });
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }
}
