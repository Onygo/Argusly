<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'type',
    'title',
    'summary',
    'period_start',
    'period_end',
    'generated_by',
    'generated_at',
])]
class Report extends Model
{
    use HasFactory;

    public const TYPES = ['weekly', 'monthly', 'campaign', 'visibility', 'content', 'executive'];

    protected static function booted(): void
    {
        static::creating(function (Report $report): void {
            $report->uuid ??= (string) Str::uuid();
            $report->generated_at ??= now();
        });

        static::saving(function (Report $report): void {
            if (! in_array($report->type, self::TYPES, true)) {
                throw new InvalidArgumentException("Invalid report type [{$report->type}].");
            }

            if ($report->brand_id !== null) {
                $brand = Brand::query()->find($report->brand_id);

                if (! $brand || $brand->account_id !== $report->account_id) {
                    throw new InvalidArgumentException('Report brand must belong to the report account.');
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

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ReportSection::class)->orderBy('position')->orderBy('id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(ReportSnapshot::class)->latest('generated_at');
    }

    public function latestSnapshot(): HasMany
    {
        return $this->hasMany(ReportSnapshot::class)->latest('generated_at')->latest();
    }

    public function scopeForTenant(Builder $query, Account $account, ?Brand $brand = null): Builder
    {
        return $query->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $scope) => $scope->where(fn (Builder $brandScope) => $brandScope->whereNull('brand_id')->orWhere('brand_id', $brand->id)),
                fn (Builder $scope) => $scope->whereNull('brand_id'),
            );
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'generated_at' => 'datetime',
        ];
    }
}
