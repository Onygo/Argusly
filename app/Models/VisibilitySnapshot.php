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
    'provider',
    'language',
    'locale',
    'market',
    'persona',
    'intent',
    'score',
    'position',
    'mention_found',
    'results_count',
    'metadata',
    'captured_at',
])]
class VisibilitySnapshot extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (VisibilitySnapshot $snapshot): void {
            $snapshot->uuid ??= (string) Str::uuid();
            $snapshot->captured_at ??= now();
            $snapshot->results_count ??= 0;
        });

        static::saving(function (VisibilitySnapshot $snapshot): void {
            if ($snapshot->provider !== null && ! in_array($snapshot->provider, VisibilityCheck::PROVIDERS, true)) {
                throw new InvalidArgumentException("Invalid visibility provider [{$snapshot->provider}].");
            }

            $brand = Brand::query()->find($snapshot->brand_id);

            if (! $brand || $brand->account_id !== $snapshot->account_id) {
                throw new InvalidArgumentException('Visibility snapshot brand must belong to the snapshot account.');
            }

            if ($snapshot->language !== null) {
                app(ContentLanguageService::class)->validateForBrand($snapshot->language, $brand);
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
    public function brandModel(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'position' => 'integer',
            'mention_found' => 'boolean',
            'results_count' => 'integer',
            'metadata' => 'array',
            'captured_at' => 'datetime',
        ];
    }
}
