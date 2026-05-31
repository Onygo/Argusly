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
    'content_asset_id',
    'type',
    'status',
    'prompt',
    'input_payload',
    'output_payload',
    'title',
    'body',
    'language',
    'locale',
    'model',
    'provider',
    'cost_credits',
    'created_by',
    'approved_by',
    'approved_at',
])]
class GeneratedAsset extends Model
{
    use HasFactory;

    public const STATUSES = [
        'queued',
        'processing',
        'completed',
        'failed',
        'cancelled',
        'approved',
        'rejected',
    ];

    public const TYPES = [
        'article',
        'refresh',
        'answer_block',
        'faq',
        'social_post',
        'newsletter',
        'campaign_copy',
        'translation',
    ];

    protected static function booted(): void
    {
        static::creating(function (GeneratedAsset $asset): void {
            $asset->uuid ??= (string) Str::uuid();
            $asset->status ??= 'queued';
            $asset->cost_credits ??= 0;
        });

        static::saving(function (GeneratedAsset $asset): void {
            if ($asset->content_asset_id !== null) {
                $contentAsset = ContentAsset::query()->find($asset->content_asset_id);

                if (! $contentAsset || $contentAsset->account_id !== $asset->account_id || $contentAsset->brand_id !== $asset->brand_id) {
                    throw new InvalidArgumentException('Generated asset content asset must belong to the same account and brand.');
                }
            }

            if ($asset->language !== null) {
                app(ContentLanguageService::class)->validateForBrand($asset->language, $asset->brand);
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    protected function casts(): array
    {
        return [
            'input_payload' => 'array',
            'output_payload' => 'array',
            'cost_credits' => 'integer',
            'approved_at' => 'datetime',
        ];
    }
}
