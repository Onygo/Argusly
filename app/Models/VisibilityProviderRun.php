<?php

namespace App\Models;

use App\Models\Concerns\HasEvidence;
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
    'visibility_check_id',
    'provider',
    'model',
    'prompt_template_id',
    'query',
    'language',
    'locale',
    'market',
    'persona',
    'intent',
    'input_language',
    'target_market',
    'raw_response',
    'normalized_answer',
    'normalized_answer_language',
    'detected_language',
    'latency_ms',
    'cost_credits',
    'status',
    'captured_at',
    'metadata',
])]
class VisibilityProviderRun extends Model
{
    use HasEvidence, HasFactory;

    public const STATUSES = ['pending', 'processing', 'completed', 'failed', 'cancelled'];

    protected static function booted(): void
    {
        static::creating(function (VisibilityProviderRun $run): void {
            $run->uuid ??= (string) Str::uuid();
            $run->status ??= 'pending';
            $run->captured_at ??= now();
            $run->cost_credits ??= 0;
            $run->language ??= $run->input_language ?? 'en';
            $run->input_language ??= $run->language;
            $run->normalized_answer_language ??= $run->detected_language ?? $run->language;
        });

        static::saving(function (VisibilityProviderRun $run): void {
            if (! in_array($run->provider, VisibilityCheck::PROVIDERS, true)) {
                throw new InvalidArgumentException("Invalid visibility provider [{$run->provider}].");
            }

            if (! in_array($run->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid visibility provider run status [{$run->status}].");
            }

            $run->language ??= $run->input_language ?? 'en';
            $run->input_language ??= $run->language;
            $run->normalized_answer_language ??= $run->detected_language ?? $run->language;

            $brand = Brand::query()->find($run->brand_id);

            if (! $brand || $brand->account_id !== $run->account_id) {
                throw new InvalidArgumentException('Visibility provider run brand must belong to the run account.');
            }

            app(ContentLanguageService::class)->validateForBrand($run->language, $brand);

            if ($run->visibility_check_id !== null) {
                $check = VisibilityCheck::query()->find($run->visibility_check_id);

                if (! $check || $check->account_id !== $run->account_id || $check->brand_id !== $run->brand_id) {
                    throw new InvalidArgumentException('Visibility provider run check must belong to the same tenant.');
                }
            }

            if ($run->prompt_template_id !== null) {
                $template = VisibilityPromptTemplate::query()->find($run->prompt_template_id);

                if (! $template || $template->account_id !== $run->account_id || $template->brand_id !== $run->brand_id) {
                    throw new InvalidArgumentException('Visibility provider run prompt template must belong to the same tenant.');
                }
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

    /**
     * @return BelongsTo<VisibilityCheck, $this>
     */
    public function visibilityCheck(): BelongsTo
    {
        return $this->belongsTo(VisibilityCheck::class);
    }

    /**
     * @return BelongsTo<VisibilityPromptTemplate, $this>
     */
    public function promptTemplate(): BelongsTo
    {
        return $this->belongsTo(VisibilityPromptTemplate::class, 'prompt_template_id');
    }

    /**
     * @return HasMany<VisibilityCitation, $this>
     */
    public function citations(): HasMany
    {
        return $this->hasMany(VisibilityCitation::class, 'provider_run_id');
    }

    /**
     * @return HasMany<VisibilityAnswerEntity, $this>
     */
    public function answerEntities(): HasMany
    {
        return $this->hasMany(VisibilityAnswerEntity::class, 'provider_run_id');
    }

    /**
     * @param  Builder<VisibilityProviderRun>  $query
     * @return Builder<VisibilityProviderRun>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    protected function casts(): array
    {
        return [
            'latency_ms' => 'integer',
            'cost_credits' => 'integer',
            'metadata' => 'array',
            'captured_at' => 'datetime',
        ];
    }
}
