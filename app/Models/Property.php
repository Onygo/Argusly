<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'account_id',
    'brand_id',
    'name',
    'type',
    'url',
    'primary_language',
    'settings',
    'status',
])]
class Property extends Model
{
    use HasFactory;

    public const TYPES = [
        'website',
        'blog',
        'app',
        'knowledge_base',
        'store',
        'other',
    ];

    public const STATUSES = [
        'draft',
        'active',
        'inactive',
        'archived',
    ];

    protected static function booted(): void
    {
        static::creating(function (Property $property): void {
            $property->uuid ??= (string) Str::uuid();
            $property->status ??= 'active';
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
     * @return HasMany<PublishingChannel, $this>
     */
    public function publishingChannels(): HasMany
    {
        return $this->hasMany(PublishingChannel::class);
    }

    /**
     * @return HasMany<ContentAsset, $this>
     */
    public function contentAssets(): HasMany
    {
        return $this->hasMany(ContentAsset::class);
    }

    /**
     * @return HasMany<Ga4Property, $this>
     */
    public function ga4Properties(): HasMany
    {
        return $this->hasMany(Ga4Property::class);
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }
}
