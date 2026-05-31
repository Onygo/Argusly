<?php

namespace App\Models;

use App\Models\Concerns\RecordsDomainEvents;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'source_content_asset_id',
    'translated_content_asset_id',
    'source_language',
    'source_locale',
    'target_language',
    'target_locale',
    'status',
    'provider',
    'model',
    'input_payload',
    'output_payload',
    'metadata',
    'requested_by',
    'approved_by',
    'approved_at',
])]
class ContentTranslation extends Model
{
    use HasFactory, RecordsDomainEvents;

    public const STATUSES = ['draft', 'queued', 'completed', 'failed', 'archived'];

    protected static function booted(): void
    {
        static::creating(function (ContentTranslation $translation): void {
            $translation->uuid ??= (string) Str::uuid();
            $translation->status ??= 'draft';
        });

        static::saving(function (ContentTranslation $translation): void {
            if (! in_array($translation->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid content translation status [{$translation->status}].");
            }

            $source = ContentAsset::query()->find($translation->source_content_asset_id);

            if (! $source || $source->account_id !== $translation->account_id || $source->brand_id !== $translation->brand_id) {
                throw new InvalidArgumentException('Translation source asset must belong to the same account and brand.');
            }

            if ($translation->translated_content_asset_id !== null) {
                $translated = ContentAsset::query()->find($translation->translated_content_asset_id);

                if (! $translated || $translated->account_id !== $translation->account_id || $translated->brand_id !== $translation->brand_id) {
                    throw new InvalidArgumentException('Translated asset must belong to the same account and brand.');
                }
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

    public function sourceContentAsset(): BelongsTo
    {
        return $this->belongsTo(ContentAsset::class, 'source_content_asset_id');
    }

    public function translatedContentAsset(): BelongsTo
    {
        return $this->belongsTo(ContentAsset::class, 'translated_content_asset_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    protected function casts(): array
    {
        return [
            'input_payload' => 'array',
            'output_payload' => 'array',
            'metadata' => 'array',
            'approved_at' => 'datetime',
        ];
    }
}
