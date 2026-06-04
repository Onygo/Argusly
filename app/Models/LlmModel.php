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
    'provider_id',
    'model',
    'name',
    'type',
    'context_window',
    'supports_json',
    'supports_tools',
    'supports_vision',
    'supports_streaming',
    'input_cost_per_1k',
    'output_cost_per_1k',
    'status',
    'metadata',
])]
class LlmModel extends Model
{
    use HasFactory;

    public const TYPES = ['chat', 'completion', 'embedding', 'vision'];

    public const STATUSES = ['active', 'inactive', 'deprecated', 'archived'];

    protected static function booted(): void
    {
        static::creating(function (LlmModel $model): void {
            $model->uuid ??= (string) Str::uuid();
            $model->status ??= 'active';
        });

        static::saving(function (LlmModel $model): void {
            if (! in_array($model->type, self::TYPES, true)) {
                throw new InvalidArgumentException("Invalid LLM model type [{$model->type}].");
            }

            if (! in_array($model->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid LLM model status [{$model->status}].");
            }
        });
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(LlmProvider::class, 'provider_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    protected function casts(): array
    {
        return [
            'supports_json' => 'boolean',
            'supports_tools' => 'boolean',
            'supports_vision' => 'boolean',
            'supports_streaming' => 'boolean',
            'input_cost_per_1k' => 'decimal:6',
            'output_cost_per_1k' => 'decimal:6',
            'metadata' => 'array',
        ];
    }
}
