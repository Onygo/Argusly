<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable(['provider', 'name', 'status', 'base_url', 'api_key_env', 'settings'])]
class LlmProvider extends Model
{
    use HasFactory;

    public const PROVIDERS = ['openai', 'anthropic', 'google', 'mistral', 'groq', 'openrouter'];

    public const STATUSES = ['active', 'inactive', 'archived'];

    protected static function booted(): void
    {
        static::creating(function (LlmProvider $provider): void {
            $provider->uuid ??= (string) Str::uuid();
            $provider->status ??= 'active';
        });

        static::saving(function (LlmProvider $provider): void {
            if (! in_array($provider->provider, self::PROVIDERS, true)) {
                throw new InvalidArgumentException("Invalid LLM provider [{$provider->provider}].");
            }

            if (! in_array($provider->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid LLM provider status [{$provider->status}].");
            }
        });
    }

    public function models(): HasMany
    {
        return $this->hasMany(LlmModel::class, 'provider_id');
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }
}
