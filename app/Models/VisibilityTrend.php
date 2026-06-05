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
    'period_date',
    'period',
    'provider',
    'answer_presence_score',
    'citation_score',
    'source_presence_score',
    'authority_score',
    'competitor_presence_score',
    'ai_attention_score',
    'scores_count',
    'metadata_json',
])]
class VisibilityTrend extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (VisibilityTrend $trend): void {
            $brand = Brand::query()->find($trend->brand_id);

            if (! $brand || $brand->account_id !== $trend->account_id) {
                throw new InvalidArgumentException('Visibility trend brand must belong to the trend account.');
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

    protected function casts(): array
    {
        return [
            'period_date' => 'date',
            'answer_presence_score' => 'integer',
            'citation_score' => 'integer',
            'source_presence_score' => 'integer',
            'authority_score' => 'integer',
            'competitor_presence_score' => 'integer',
            'ai_attention_score' => 'integer',
            'scores_count' => 'integer',
            'metadata_json' => 'array',
        ];
    }
}
