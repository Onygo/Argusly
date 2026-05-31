<?php

namespace App\Models;

use App\Services\ContentLanguageService;
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
    'content_asset_id',
    'campaign_id',
    'social_profile_id',
    'provider',
    'status',
    'post_text',
    'media',
    'metadata',
    'language',
    'locale',
    'market',
    'scheduled_at',
    'published_at',
    'external_id',
    'external_url',
    'error_message',
    'created_by',
    'approved_by',
    'approved_at',
])]
class SocialPost extends Model
{
    use HasFactory;

    public const STATUSES = [
        'draft',
        'review',
        'approved',
        'scheduled',
        'queued',
        'publishing',
        'published',
        'failed',
        'cancelled',
    ];

    protected static function booted(): void
    {
        static::creating(function (SocialPost $post): void {
            $post->uuid ??= (string) Str::uuid();
            $post->status ??= 'draft';
            $post->language ??= app(ContentLanguageService::class)->defaultFor($post->brand, $post->account);
            $post->locale ??= app(ContentLanguageService::class)->localeForLanguage($post->language);
        });

        static::saving(function (SocialPost $post): void {
            if (! in_array($post->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid social post status [{$post->status}].");
            }

            $brand = Brand::query()->find($post->brand_id);

            if (! $brand || $brand->account_id !== $post->account_id) {
                throw new InvalidArgumentException('Social post brand must belong to the same account.');
            }

            app(ContentLanguageService::class)->validateForBrand($post->language, $brand);
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
     * @return BelongsTo<ContentAsset, $this>
     */
    public function contentAsset(): BelongsTo
    {
        return $this->belongsTo(ContentAsset::class);
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<SocialProfile, $this>
     */
    public function socialProfile(): BelongsTo
    {
        return $this->belongsTo(SocialProfile::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<SocialPostVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(SocialPostVariant::class);
    }

    /**
     * @return HasMany<SocialMediaAsset, $this>
     */
    public function mediaAssets(): HasMany
    {
        return $this->hasMany(SocialMediaAsset::class);
    }

    /**
     * @param  Builder<SocialPost>  $query
     * @return Builder<SocialPost>
     */
    public function scopeForTenant(Builder $query, Account $account, ?Brand $brand = null): Builder
    {
        return $query->where('account_id', $account->id)
            ->when($brand !== null, fn (Builder $scope) => $scope->where('brand_id', $brand->id));
    }

    protected function casts(): array
    {
        return [
            'media' => 'array',
            'metadata' => 'array',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }
}
