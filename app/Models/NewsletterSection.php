<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'newsletter_id',
    'type',
    'title',
    'body',
    'content_asset_id',
    'position',
    'metadata',
])]
class NewsletterSection extends Model
{
    use HasFactory;

    public const TYPES = [
        'intro',
        'content_asset',
        'cta',
        'event',
        'custom',
        'footer',
    ];

    protected static function booted(): void
    {
        static::creating(function (NewsletterSection $section): void {
            $section->uuid ??= (string) Str::uuid();
            $section->position ??= 0;
        });

        static::saving(function (NewsletterSection $section): void {
            if (! in_array($section->type, self::TYPES, true)) {
                throw new InvalidArgumentException("Invalid newsletter section type [{$section->type}].");
            }

            $section->validateContentAsset();
        });
    }

    public function newsletter(): BelongsTo
    {
        return $this->belongsTo(Newsletter::class);
    }

    public function contentAsset(): BelongsTo
    {
        return $this->belongsTo(ContentAsset::class);
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    private function validateContentAsset(): void
    {
        if ($this->content_asset_id === null) {
            return;
        }

        $newsletter = $this->newsletter ?: Newsletter::query()->find($this->newsletter_id);
        $asset = ContentAsset::query()->find($this->content_asset_id);

        if (! $newsletter || ! $asset || $asset->account_id !== $newsletter->account_id || $asset->brand_id !== $newsletter->brand_id) {
            throw new InvalidArgumentException('Newsletter section content asset must belong to the same tenant scope.');
        }
    }
}
