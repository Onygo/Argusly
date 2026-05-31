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
    'question',
    'answer',
    'type',
    'status',
    'language',
    'position',
    'metadata',
])]
class AnswerBlock extends Model
{
    use HasFactory;

    public const TYPES = [
        'direct_answer',
        'faq',
        'how_to',
        'comparison',
        'definition',
        'summary',
        'pros_cons',
    ];

    public const STATUSES = [
        'draft',
        'review',
        'approved',
        'published',
        'archived',
    ];

    protected static function booted(): void
    {
        static::creating(function (AnswerBlock $answerBlock): void {
            $answerBlock->uuid ??= (string) Str::uuid();
            $answerBlock->status ??= 'draft';
            $answerBlock->language ??= app(ContentLanguageService::class)->defaultFor($answerBlock->brand, $answerBlock->account);
        });

        static::saving(function (AnswerBlock $answerBlock): void {
            $answerBlock->language ??= app(ContentLanguageService::class)->defaultFor($answerBlock->brand, $answerBlock->account);

            if ($answerBlock->content_asset_id !== null) {
                $contentAsset = ContentAsset::query()->find($answerBlock->content_asset_id);

                if (! $contentAsset || $contentAsset->account_id !== $answerBlock->account_id || $contentAsset->brand_id !== $answerBlock->brand_id) {
                    throw new InvalidArgumentException('Answer block content asset must belong to the same account and brand.');
                }
            }

            app(ContentLanguageService::class)->validateForBrand($answerBlock->language, $answerBlock->brand);
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

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'position' => 'integer',
        ];
    }
}
