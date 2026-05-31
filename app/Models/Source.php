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
    'name',
    'type',
    'provider',
    'status',
    'metadata',
])]
class Source extends Model
{
    use HasFactory;

    public const TYPES = ['social', 'news', 'blog', 'forum', 'video', 'podcast', 'website', 'ai', 'search'];

    public const PROVIDERS = ['linkedin', 'reddit', 'youtube', 'chatgpt', 'claude', 'gemini', 'perplexity', 'google', 'rss', 'wordpress'];

    public const STATUSES = ['active', 'paused', 'archived'];

    protected static function booted(): void
    {
        static::creating(function (Source $source): void {
            $source->uuid ??= (string) Str::uuid();
            $source->status ??= 'active';
        });

        static::saving(function (Source $source): void {
            if (! in_array($source->type, self::TYPES, true)) {
                throw new InvalidArgumentException("Invalid source type [{$source->type}].");
            }

            if (! in_array($source->provider, self::PROVIDERS, true)) {
                throw new InvalidArgumentException("Invalid source provider [{$source->provider}].");
            }

            if (! in_array($source->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid source status [{$source->status}].");
            }

            if ($source->brand_id !== null) {
                $brand = Brand::query()->find($source->brand_id);

                if (! $brand || $source->account_id === null || $brand->account_id !== $source->account_id) {
                    throw new InvalidArgumentException('Source brand must belong to the same account.');
                }
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
     * @return HasMany<SourceConnection, $this>
     */
    public function connections(): HasMany
    {
        return $this->hasMany(SourceConnection::class);
    }

    /**
     * @return HasMany<SourceSync, $this>
     */
    public function syncs(): HasMany
    {
        return $this->hasMany(SourceSync::class);
    }

    /**
     * @return HasMany<Mention, $this>
     */
    public function mentions(): HasMany
    {
        return $this->hasMany(Mention::class);
    }

    /**
     * @param  Builder<Source>  $query
     * @return Builder<Source>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
