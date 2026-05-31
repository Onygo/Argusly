<?php

namespace App\Models;

use App\Models\Concerns\HasEvidence;
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
    'content_asset_id',
    'language',
    'locale',
    'status',
    'score',
    'seo_score',
    'ai_visibility_score',
    'readability_score',
    'entity_score',
    'answer_score',
    'issues',
    'recommendations',
    'summary',
    'audited_at',
])]
class ContentAudit extends Model
{
    use HasEvidence, HasFactory, RecordsDomainEvents;

    public const STATUSES = [
        'queued',
        'processing',
        'completed',
        'failed',
    ];

    protected static function booted(): void
    {
        static::creating(function (ContentAudit $audit): void {
            $audit->uuid ??= (string) Str::uuid();
            $audit->status ??= 'queued';
            $audit->language ??= $audit->contentAsset?->language ?? 'en';
            $audit->locale ??= $audit->contentAsset?->locale;
        });

        static::saving(function (ContentAudit $audit): void {
            $contentAsset = ContentAsset::query()->find($audit->content_asset_id);

            if (! $contentAsset || $contentAsset->account_id !== $audit->account_id || $contentAsset->brand_id !== $audit->brand_id) {
                throw new InvalidArgumentException('Content audit asset must belong to the same account and brand.');
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

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'seo_score' => 'integer',
            'ai_visibility_score' => 'integer',
            'readability_score' => 'integer',
            'entity_score' => 'integer',
            'answer_score' => 'integer',
            'issues' => 'array',
            'recommendations' => 'array',
            'audited_at' => 'datetime',
        ];
    }
}
