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
    'integration_connection_id',
    'owner_user_id',
    'provider',
    'provider_profile_id',
    'display_name',
    'profile_url',
    'avatar_url',
    'type',
    'status',
    'metadata',
])]
class SocialProfile extends Model
{
    use HasFactory;

    public const TYPES = [
        'person',
        'organization',
        'page',
    ];

    public const STATUSES = [
        'connected',
        'disconnected',
        'expired',
        'revoked',
        'error',
    ];

    protected static function booted(): void
    {
        static::creating(function (SocialProfile $profile): void {
            $profile->uuid ??= (string) Str::uuid();
            $profile->type ??= 'person';
            $profile->status ??= 'connected';
        });

        static::saving(function (SocialProfile $profile): void {
            if (! in_array($profile->type, self::TYPES, true)) {
                throw new InvalidArgumentException("Invalid social profile type [{$profile->type}].");
            }

            if (! in_array($profile->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid social profile status [{$profile->status}].");
            }

            if ($profile->brand_id === null) {
                return;
            }

            $brand = Brand::query()->find($profile->brand_id);

            if (! $brand || ($profile->account_id !== null && $brand->account_id !== $profile->account_id)) {
                throw new InvalidArgumentException('Social profile brand must belong to the same account.');
            }
        });
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<IntegrationConnection, $this>
     */
    public function integrationConnection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return HasMany<SocialProfilePermission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(SocialProfilePermission::class);
    }

    /**
     * @return HasMany<SocialPost, $this>
     */
    public function socialPosts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    /**
     * @param  Builder<SocialProfile>  $query
     * @return Builder<SocialProfile>
     */
    public function scopeConnected(Builder $query): Builder
    {
        return $query->where('status', 'connected');
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
