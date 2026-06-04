<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'property_id',
    'public_key',
    'verification_token',
    'allowed_domains',
    'verified_at',
    'retention_days',
    'is_enabled',
    'respect_dnt',
    'sampling_rate',
    'flags',
])]
class AnalyticsSite extends Model
{
    protected static function booted(): void
    {
        static::creating(function (AnalyticsSite $site): void {
            $site->public_key ??= self::generatePublicKey();
            $site->verification_token ??= self::generateVerificationToken();
        });
    }

    public static function generatePublicKey(): string
    {
        do {
            $key = 'arg_'.Str::random(28);
        } while (self::query()->where('public_key', $key)->exists());

        return $key;
    }

    public static function generateVerificationToken(): string
    {
        return 'arg-verify-'.Str::random(48);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return HasMany<AnalyticsEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class);
    }

    protected function casts(): array
    {
        return [
            'allowed_domains' => 'array',
            'verified_at' => 'datetime',
            'retention_days' => 'integer',
            'is_enabled' => 'boolean',
            'respect_dnt' => 'boolean',
            'sampling_rate' => 'integer',
            'flags' => 'array',
        ];
    }
}
