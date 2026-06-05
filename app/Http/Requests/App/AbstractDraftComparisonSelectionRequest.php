<?php

namespace App\Http\Requests\App;

use App\Models\Brief;
use App\Services\DraftComparison\DraftComparisonFeatureGate;
use App\Services\DraftComparison\DraftComparisonModelCatalog;
use App\Services\DraftComparison\DraftComparisonService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class AbstractDraftComparisonSelectionRequest extends FormRequest
{
    /**
     * @var array<int, string>|null
     */
    private ?array $cachedAllowedModelKeys = null;

    /**
     * @var int|null
     */
    private ?int $cachedMaxSelectableModels = null;

    /**
     * @var array{enabled:bool,max_models:int,hybrid_enabled:bool,scoring_enabled:bool,premium_models_enabled:bool,allowed_modes:array<int,string>,compare_mode_enabled:bool,blocked_reason:?string}|null
     */
    private ?array $cachedCompareCapabilities = null;

    public function rules(): array
    {
        $allowedModelKeys = $this->allowedModelKeys();
        $maxSelectableModels = $this->maxSelectableModels();

        return [
            'mode' => ['required', 'string', Rule::in(['compare_two', 'compare_multi'])],
            'model_keys' => ['required', 'array', 'min:2', 'max:' . $maxSelectableModels],
            'model_keys.*' => [
                'required',
                'string',
                'max:190',
                'distinct:strict',
                Rule::in($allowedModelKeys),
            ],
            'requested_max_output_tokens' => ['nullable', 'integer', 'min:1000', 'max:32000'],
            'compare_scope' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $capabilities = $this->compareCapabilities();
            if (! $capabilities['enabled']) {
                $validator->errors()->add('mode', (string) ($capabilities['blocked_reason'] ?: app(DraftComparisonFeatureGate::class)->disabledMessage()));

                return;
            }

            $mode = $this->normalizedMode();
            $allowedModes = (array) ($capabilities['allowed_modes'] ?? []);
            if (! in_array($mode, $allowedModes, true)) {
                $validator->errors()->add(
                    'mode',
                    sprintf(
                        'Your current plan does not support %s mode. Maximum models allowed: %d.',
                        str_replace('_', ' ', $mode),
                        (int) ($capabilities['max_models'] ?? 1),
                    )
                );

                return;
            }

            if ($this->allowedModelKeys() === []) {
                $validator->errors()->add('model_keys', 'No LLM text models are currently available for draft compare.');

                return;
            }

            $modelCount = count($this->selectedModelKeys());
            if ($modelCount > (int) ($capabilities['max_models'] ?? 1)) {
                $validator->errors()->add(
                    'model_keys',
                    sprintf(
                        'Your current plan allows up to %d model%s per comparison.',
                        (int) $capabilities['max_models'],
                        (int) $capabilities['max_models'] === 1 ? '' : 's'
                    )
                );

                return;
            }

            if (! (bool) $capabilities['premium_models_enabled']
                && app(DraftComparisonFeatureGate::class)->containsPremiumModelSelection($this->selectedModelKeys())) {
                $validator->errors()->add('model_keys', 'Premium model selection is not available on your current plan.');

                return;
            }

            if ($mode === 'compare_two' && $modelCount !== 2) {
                $validator->errors()->add('model_keys', 'Compare 2 models mode requires exactly two models.');

                return;
            }

            if ($mode === 'compare_multi' && $modelCount < 2) {
                $validator->errors()->add('model_keys', 'Compare multiple mode requires at least two models.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $mode = strtolower(trim((string) $this->input('mode', 'compare_two')));
        $mode = match ($mode) {
            'compare_multiple', 'compare-multi', 'multi', 'multiple' => 'compare_multi',
            'compare-2', 'two', 'dual' => 'compare_two',
            default => $mode !== '' ? $mode : 'compare_two',
        };

        $modelKeys = collect((array) $this->input('model_keys', []))
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->values()
            ->all();

        $compareScope = strtolower(trim((string) $this->input('compare_scope', DraftComparisonService::COMPARE_SCOPE_FULL_DRAFT)));
        $compareScope = match ($compareScope) {
            'intro', 'intro_compare', 'introduction' => DraftComparisonService::COMPARE_SCOPE_INTRO_ONLY,
            'headline', 'title', 'headline_compare' => DraftComparisonService::COMPARE_SCOPE_HEADLINE_ONLY,
            'section', 'sections', 'by_section' => DraftComparisonService::COMPARE_SCOPE_SECTION_COMPARE,
            default => $compareScope,
        };
        if (! in_array($compareScope, DraftComparisonService::COMPARE_SCOPES, true)) {
            $compareScope = DraftComparisonService::COMPARE_SCOPE_FULL_DRAFT;
        }

        $this->merge([
            'mode' => $mode,
            'model_keys' => $modelKeys,
            'compare_scope' => $compareScope,
        ]);
    }

    protected function selectedModelKeys(): array
    {
        return collect((array) $this->input('model_keys', []))
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function normalizedMode(): string
    {
        $mode = strtolower(trim((string) $this->input('mode', 'compare_two')));

        return in_array($mode, ['compare_two', 'compare_multi'], true) ? $mode : 'compare_two';
    }

    /**
     * @return array<int, string>
     */
    protected function allowedModelKeys(): array
    {
        if ($this->cachedAllowedModelKeys !== null) {
            return $this->cachedAllowedModelKeys;
        }

        $brief = $this->route('brief');
        $options = app(DraftComparisonModelCatalog::class)->options();
        $capabilities = $brief instanceof Brief
            ? $this->compareCapabilities()
            : [
                'enabled' => true,
                'max_models' => max(1, (int) config('credits.draft_compare.max_models', 6)),
                'hybrid_enabled' => true,
                'scoring_enabled' => true,
                'premium_models_enabled' => true,
                'allowed_modes' => ['compare_two', 'compare_multi'],
                'compare_mode_enabled' => true,
                'blocked_reason' => null,
            ];

        $this->cachedAllowedModelKeys = collect(
            app(DraftComparisonFeatureGate::class)->filterModelOptionsForCapabilities($options, $capabilities)
        )
            ->pluck('key')
            ->map(fn (mixed $value): string => (string) $value)
            ->filter()
            ->values()
            ->all();

        return $this->cachedAllowedModelKeys;
    }

    protected function maxSelectableModels(): int
    {
        if ($this->cachedMaxSelectableModels !== null) {
            return $this->cachedMaxSelectableModels;
        }

        $defaultMax = max(1, (int) config('credits.draft_compare.max_models', 6));
        $absoluteMax = max($defaultMax, (int) config('credits.draft_compare.absolute_max_models', 8));
        $brief = $this->route('brief');

        if (! $brief instanceof Brief) {
            $this->cachedMaxSelectableModels = $defaultMax;

            return $this->cachedMaxSelectableModels;
        }

        $this->cachedMaxSelectableModels = max(
            1,
            min($absoluteMax, (int) ($this->compareCapabilities()['max_models'] ?? $defaultMax))
        );

        return $this->cachedMaxSelectableModels;
    }

    /**
     * @return array{enabled:bool,max_models:int,hybrid_enabled:bool,scoring_enabled:bool,premium_models_enabled:bool,allowed_modes:array<int,string>,compare_mode_enabled:bool,blocked_reason:?string}
     */
    protected function compareCapabilities(): array
    {
        if ($this->cachedCompareCapabilities !== null) {
            return $this->cachedCompareCapabilities;
        }

        $brief = $this->route('brief');
        if (! $brief instanceof Brief) {
            $defaultMax = max(1, (int) config('credits.draft_compare.max_models', 6));
            $this->cachedCompareCapabilities = [
                'enabled' => true,
                'max_models' => $defaultMax,
                'hybrid_enabled' => true,
                'scoring_enabled' => true,
                'premium_models_enabled' => true,
                'allowed_modes' => ['compare_two', 'compare_multi'],
                'compare_mode_enabled' => $defaultMax >= 2,
                'blocked_reason' => $defaultMax >= 2 ? null : app(DraftComparisonFeatureGate::class)->disabledMessage(),
            ];

            return $this->cachedCompareCapabilities;
        }

        $this->cachedCompareCapabilities = app(DraftComparisonFeatureGate::class)->capabilitiesForBrief($brief);

        return $this->cachedCompareCapabilities;
    }
}
