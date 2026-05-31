<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'property_id',
    'provider',
    'name',
    'status',
    'credentials',
    'settings',
    'last_connected_at',
])]
class PublishingChannel extends Model
{
    use HasFactory;

    public const PROVIDERS = [
        'wordpress',
        'laravel',
        'linkedin',
        'x',
        'meta',
        'youtube',
        'email',
        'webhook',
        'api',
    ];

    public const STATUSES = [
        'draft',
        'active',
        'disconnected',
        'failed',
        'archived',
    ];

    protected static function booted(): void
    {
        static::creating(function (PublishingChannel $channel): void {
            $channel->uuid ??= (string) Str::uuid();
            $channel->status ??= 'draft';
        });

        static::saving(function (PublishingChannel $channel): void {
            if ($channel->property_id === null) {
                return;
            }

            $property = Property::query()->find($channel->property_id);

            if (! $property || $property->account_id !== $channel->account_id || $property->brand_id !== $channel->brand_id) {
                throw new InvalidArgumentException('Publishing channel property must belong to the same account and brand.');
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
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return HasMany<ContentAsset, $this>
     */
    public function contentAssets(): HasMany
    {
        return $this->hasMany(ContentAsset::class, 'channel_id');
    }

    /**
     * @return HasMany<PublishingAction, $this>
     */
    public function publishingActions(): HasMany
    {
        return $this->hasMany(PublishingAction::class);
    }

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'last_connected_at' => 'datetime',
        ];
    }
}
