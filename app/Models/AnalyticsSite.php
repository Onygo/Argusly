<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AnalyticsSite extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'client_site_id',
        'public_key',
        'verification_token',
        'allowed_domains',
        'verified_at',
        'retention_days',
        'is_enabled',
        'respect_dnt',
        'sampling_rate',
        'flags',
    ];

    protected $casts = [
        'allowed_domains' => 'array',
        'verified_at' => 'datetime',
        'retention_days' => 'integer',
        'is_enabled' => 'boolean',
        'respect_dnt' => 'boolean',
        'sampling_rate' => 'integer',
        'flags' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (AnalyticsSite $site): void {
            if (empty($site->public_key)) {
                $site->public_key = self::generatePublicKey();
            }
            if (empty($site->verification_token)) {
                $site->verification_token = self::generateVerificationToken();
            }
        });
    }

    public static function generatePublicKey(): string
    {
        do {
            $key = 'pl_'.Str::random(28);
        } while (self::where('public_key', $key)->exists());

        return $key;
    }

    public static function generateVerificationToken(): string
    {
        return 'pl-verify-'.Str::random(48);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class);
    }

    public function rollups(): HasMany
    {
        return $this->hasMany(AnalyticsRollupDaily::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function markVerified(): void
    {
        $this->verified_at = now();
        $this->save();
    }

    /**
     * Check if this site is verified via internal/first-party domain.
     */
    public function isInternallyVerified(): bool
    {
        return $this->isVerified() && (bool) ($this->flags['internally_verified'] ?? false);
    }

    /**
     * Mark site as verified via internal/first-party domain.
     */
    public function markInternallyVerified(string $domain): void
    {
        $this->verified_at = now();
        $this->flags = array_merge($this->flags ?? [], [
            'internally_verified' => true,
            'internal_domain' => $domain,
            'verified_at' => now()->toIso8601String(),
        ]);
        $this->save();
    }
}
