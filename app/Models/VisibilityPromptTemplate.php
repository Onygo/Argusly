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
    'name',
    'prompt',
    'language',
    'intent',
    'locale',
    'market',
    'persona',
    'status',
    'metadata',
])]
class VisibilityPromptTemplate extends Model
{
    use HasFactory;

    public const STATUSES = ['active', 'paused', 'archived'];

    protected static function booted(): void
    {
        static::creating(function (VisibilityPromptTemplate $template): void {
            $template->uuid ??= (string) Str::uuid();
            $template->status ??= 'active';
        });

        static::saving(function (VisibilityPromptTemplate $template): void {
            if (! in_array($template->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid visibility prompt template status [{$template->status}].");
            }

            $template->language ??= 'en';

            $brand = Brand::query()->find($template->brand_id);

            if (! $brand || $brand->account_id !== $template->account_id) {
                throw new InvalidArgumentException('Visibility prompt template brand must belong to the template account.');
            }

            app(ContentLanguageService::class)->validateForBrand($template->language, $brand);
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
     * @return HasMany<VisibilityProviderRun, $this>
     */
    public function providerRuns(): HasMany
    {
        return $this->hasMany(VisibilityProviderRun::class, 'prompt_template_id');
    }

    /**
     * @return HasMany<VisibilityRunSchedule, $this>
     */
    public function runSchedules(): HasMany
    {
        return $this->hasMany(VisibilityRunSchedule::class, 'prompt_template_id');
    }

    /**
     * @param  Builder<VisibilityPromptTemplate>  $query
     * @return Builder<VisibilityPromptTemplate>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
