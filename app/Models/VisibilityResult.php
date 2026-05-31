<?php

namespace App\Models;

use App\Models\Concerns\HasEvidence;
use App\Models\Concerns\RecordsDomainEvents;
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
    'visibility_check_id',
    'provider',
    'query',
    'language',
    'locale',
    'market',
    'persona',
    'intent',
    'brand',
    'score',
    'position',
    'mention_found',
    'metadata',
    'captured_at',
])]
class VisibilityResult extends Model
{
    use HasEvidence, HasFactory, RecordsDomainEvents;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (VisibilityResult $result): void {
            $result->uuid ??= (string) Str::uuid();
            $result->captured_at ??= now();
            $result->language ??= 'en';
        });

        static::saving(function (VisibilityResult $result): void {
            if (! in_array($result->provider, VisibilityCheck::PROVIDERS, true)) {
                throw new InvalidArgumentException("Invalid visibility provider [{$result->provider}].");
            }

            $brand = Brand::query()->find($result->brand_id);

            if (! $brand || $brand->account_id !== $result->account_id) {
                throw new InvalidArgumentException('Visibility result brand must belong to the result account.');
            }

            app(ContentLanguageService::class)->validateForBrand($result->language, $brand);
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
    public function brandModel(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    /**
     * @return BelongsTo<VisibilityCheck, $this>
     */
    public function visibilityCheck(): BelongsTo
    {
        return $this->belongsTo(VisibilityCheck::class);
    }

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'position' => 'integer',
            'mention_found' => 'boolean',
            'metadata' => 'array',
            'captured_at' => 'datetime',
        ];
    }
}
