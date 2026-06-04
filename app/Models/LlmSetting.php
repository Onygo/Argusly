<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'default_provider_id',
    'default_model_id',
    'fallback_provider_id',
    'fallback_model_id',
    'temperature',
    'max_tokens',
    'settings',
])]
class LlmSetting extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (LlmSetting $setting): void {
            $setting->uuid ??= (string) Str::uuid();
        });

        static::saving(function (LlmSetting $setting): void {
            $setting->validateBrand();
            $setting->validateModelProviders();
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

    public function defaultProvider(): BelongsTo
    {
        return $this->belongsTo(LlmProvider::class, 'default_provider_id');
    }

    public function defaultModel(): BelongsTo
    {
        return $this->belongsTo(LlmModel::class, 'default_model_id');
    }

    public function fallbackProvider(): BelongsTo
    {
        return $this->belongsTo(LlmProvider::class, 'fallback_provider_id');
    }

    public function fallbackModel(): BelongsTo
    {
        return $this->belongsTo(LlmModel::class, 'fallback_model_id');
    }

    protected function casts(): array
    {
        return [
            'temperature' => 'decimal:2',
            'settings' => 'array',
        ];
    }

    private function validateBrand(): void
    {
        if ($this->brand_id === null) {
            return;
        }

        $brand = Brand::query()->find($this->brand_id);

        if (! $brand || $this->account_id === null || $brand->account_id !== (int) $this->account_id) {
            throw new InvalidArgumentException('LLM setting brand must belong to the same account.');
        }
    }

    private function validateModelProviders(): void
    {
        $this->validateModelProvider($this->default_model_id, $this->default_provider_id, 'default');
        $this->validateModelProvider($this->fallback_model_id, $this->fallback_provider_id, 'fallback');
    }

    private function validateModelProvider(?int $modelId, ?int $providerId, string $label): void
    {
        if ($modelId === null || $providerId === null) {
            return;
        }

        $model = LlmModel::query()->find($modelId);

        if (! $model || $model->provider_id !== $providerId) {
            throw new InvalidArgumentException("LLM {$label} model must belong to the selected provider.");
        }
    }
}
