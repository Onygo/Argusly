<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'competitor_id',
    'visibility_check_id',
    'provider',
    'competitor_name',
    'mentions_count',
    'presence_score',
    'captured_at',
    'metadata_json',
])]
class VisibilityCompetitorSnapshot extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (VisibilityCompetitorSnapshot $snapshot): void {
            $brand = Brand::query()->find($snapshot->brand_id);

            if (! $brand || $brand->account_id !== $snapshot->account_id) {
                throw new InvalidArgumentException('Visibility competitor snapshot brand must belong to the snapshot account.');
            }

            if ($snapshot->competitor_id !== null) {
                $competitor = Competitor::query()->find($snapshot->competitor_id);

                if (! $competitor || $competitor->account_id !== $snapshot->account_id || $competitor->brand_id !== $snapshot->brand_id) {
                    throw new InvalidArgumentException('Visibility competitor snapshot competitor must belong to the same tenant.');
                }
            }

            if ($snapshot->visibility_check_id !== null) {
                $check = VisibilityCheck::query()->find($snapshot->visibility_check_id);

                if (! $check || $check->account_id !== $snapshot->account_id || $check->brand_id !== $snapshot->brand_id) {
                    throw new InvalidArgumentException('Visibility competitor snapshot check must belong to the same tenant.');
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

    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }

    public function visibilityCheck(): BelongsTo
    {
        return $this->belongsTo(VisibilityCheck::class);
    }

    protected function casts(): array
    {
        return [
            'mentions_count' => 'integer',
            'presence_score' => 'integer',
            'captured_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }
}
