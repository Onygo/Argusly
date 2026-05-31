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
    'social_post_id',
    'provider',
    'type',
    'status',
    'file_path',
    'external_asset_urn',
    'mime_type',
    'size_bytes',
    'metadata',
    'uploaded_at',
    'error_message',
])]
class SocialMediaAsset extends Model
{
    use HasFactory;

    public const TYPES = ['image', 'video', 'document'];

    public const STATUSES = ['draft', 'uploading', 'uploaded', 'failed'];

    protected static function booted(): void
    {
        static::creating(function (SocialMediaAsset $asset): void {
            $asset->uuid ??= (string) Str::uuid();
            $asset->status ??= 'draft';
        });

        static::saving(function (SocialMediaAsset $asset): void {
            if (! in_array($asset->type, self::TYPES, true)) {
                throw new InvalidArgumentException("Invalid social media asset type [{$asset->type}].");
            }

            if (! in_array($asset->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid social media asset status [{$asset->status}].");
            }

            $brand = Brand::query()->find($asset->brand_id);

            if (! $brand || $brand->account_id !== $asset->account_id) {
                throw new InvalidArgumentException('Social media asset brand must belong to the same account.');
            }
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

    public function socialPost(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class);
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'uploaded_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }
}
