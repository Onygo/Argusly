<?php

namespace App\Models;

use App\Services\ContentLanguageService;
use App\Services\Visibility\ProviderRegistry;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'prompt_template_id',
    'provider',
    'language',
    'locale',
    'market',
    'persona',
    'intent',
    'frequency',
    'status',
    'last_run_at',
    'next_run_at',
    'settings',
])]
class VisibilityRunSchedule extends Model
{
    use HasFactory;

    public const FREQUENCIES = ['daily', 'weekly', 'monthly', 'manual'];

    public const STATUSES = ['active', 'paused', 'archived'];

    protected static function booted(): void
    {
        static::creating(function (VisibilityRunSchedule $schedule): void {
            $schedule->uuid ??= (string) Str::uuid();
            $schedule->status ??= 'active';
        });

        static::saving(function (VisibilityRunSchedule $schedule): void {
            if (! in_array($schedule->frequency, self::FREQUENCIES, true)) {
                throw new InvalidArgumentException("Invalid visibility run frequency [{$schedule->frequency}].");
            }

            if (! in_array($schedule->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid visibility run schedule status [{$schedule->status}].");
            }

            app(ProviderRegistry::class)->get($schedule->provider);

            $brand = Brand::query()->find($schedule->brand_id);

            if (! $brand || $brand->account_id !== $schedule->account_id) {
                throw new InvalidArgumentException('Visibility run schedule brand must belong to the schedule account.');
            }

            if ($schedule->language !== null) {
                app(ContentLanguageService::class)->validateForBrand($schedule->language, $brand);
            }

            $template = VisibilityPromptTemplate::query()->find($schedule->prompt_template_id);

            if (! $template || $template->account_id !== $schedule->account_id || $template->brand_id !== $schedule->brand_id) {
                throw new InvalidArgumentException('Visibility run schedule prompt template must belong to the same tenant.');
            }
        });
    }

    /**
     * @param  Builder<VisibilityRunSchedule>  $query
     * @return Builder<VisibilityRunSchedule>
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where('frequency', '!=', 'manual')
            ->where(fn (Builder $scope) => $scope
                ->whereNull('next_run_at')
                ->orWhere('next_run_at', '<=', now()));
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
     * @return BelongsTo<VisibilityPromptTemplate, $this>
     */
    public function promptTemplate(): BelongsTo
    {
        return $this->belongsTo(VisibilityPromptTemplate::class, 'prompt_template_id');
    }

    protected function casts(): array
    {
        return [
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'settings' => 'array',
        ];
    }
}
