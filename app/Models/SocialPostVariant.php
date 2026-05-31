<?php

namespace App\Models;

use App\Services\ContentLanguageService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'social_post_id',
    'content_asset_id',
    'variant_type',
    'status',
    'post_text',
    'language',
    'metadata',
    'created_by',
])]
class SocialPostVariant extends Model
{
    use HasFactory;

    public const TYPES = [
        'short',
        'long',
        'thread',
        'linkedin_personal',
        'linkedin_company',
        'x_post',
        'instagram_caption',
    ];

    public const STATUSES = [
        'draft',
        'selected',
        'rejected',
        'approved',
    ];

    protected static function booted(): void
    {
        static::creating(function (SocialPostVariant $variant): void {
            $variant->uuid ??= (string) Str::uuid();
            $variant->status ??= 'draft';
        });

        static::saving(function (SocialPostVariant $variant): void {
            if (! in_array($variant->variant_type, self::TYPES, true)) {
                throw new InvalidArgumentException("Invalid social post variant type [{$variant->variant_type}].");
            }

            if (! in_array($variant->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid social post variant status [{$variant->status}].");
            }

            $brand = Brand::query()->find($variant->brand_id);

            if (! $brand || $brand->account_id !== $variant->account_id) {
                throw new InvalidArgumentException('Social post variant brand must belong to the same account.');
            }

            if ($variant->language !== null) {
                app(ContentLanguageService::class)->validateForBrand($variant->language, $brand);
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
     * @return BelongsTo<SocialPost, $this>
     */
    public function socialPost(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class);
    }

    /**
     * @return BelongsTo<ContentAsset, $this>
     */
    public function contentAsset(): BelongsTo
    {
        return $this->belongsTo(ContentAsset::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
