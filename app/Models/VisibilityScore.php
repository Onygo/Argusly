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
    'visibility_check_id',
    'visibility_result_id',
    'provider',
    'model',
    'prompt_hash',
    'answer_presence_score',
    'citation_score',
    'source_presence_score',
    'authority_score',
    'competitor_presence_score',
    'ai_attention_score',
    'summary',
    'raw_metrics_json',
])]
class VisibilityScore extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (VisibilityScore $score): void {
            $brand = Brand::query()->find($score->brand_id);

            if (! $brand || $brand->account_id !== $score->account_id) {
                throw new InvalidArgumentException('Visibility score brand must belong to the score account.');
            }

            if ($score->visibility_check_id !== null) {
                $check = VisibilityCheck::query()->find($score->visibility_check_id);

                if (! $check || $check->account_id !== $score->account_id || $check->brand_id !== $score->brand_id) {
                    throw new InvalidArgumentException('Visibility score check must belong to the same tenant.');
                }
            }

            if ($score->visibility_result_id !== null) {
                $result = VisibilityResult::query()->find($score->visibility_result_id);

                if (! $result || $result->account_id !== $score->account_id || $result->brand_id !== $score->brand_id) {
                    throw new InvalidArgumentException('Visibility score result must belong to the same tenant.');
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

    public function visibilityCheck(): BelongsTo
    {
        return $this->belongsTo(VisibilityCheck::class);
    }

    public function visibilityResult(): BelongsTo
    {
        return $this->belongsTo(VisibilityResult::class);
    }

    protected function casts(): array
    {
        return [
            'answer_presence_score' => 'integer',
            'citation_score' => 'integer',
            'source_presence_score' => 'integer',
            'authority_score' => 'integer',
            'competitor_presence_score' => 'integer',
            'ai_attention_score' => 'integer',
            'raw_metrics_json' => 'array',
        ];
    }
}
