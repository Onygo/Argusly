<?php

namespace App\Http\Requests\App;

use App\Models\DraftComparison;
use App\Services\DraftComparison\DraftComparisonFeatureGate;
use App\Services\DraftComparison\HybridDraftEligibilityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateHybridDraftComparisonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $comparison = $this->route('comparison');
        $user = $this->user();

        return $comparison instanceof DraftComparison
            && $user !== null
            && $user->can('queueHybrid', $comparison);
    }

    public function rules(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $comparison = $this->route('comparison');
            if (! $comparison instanceof DraftComparison) {
                $validator->errors()->add('draft_compare', 'Draft comparison context is missing.');

                return;
            }

            $capabilities = app(DraftComparisonFeatureGate::class)->capabilitiesForComparison($comparison);
            if (! $capabilities['enabled']) {
                $validator->errors()->add('draft_compare', (string) ($capabilities['blocked_reason'] ?: app(DraftComparisonFeatureGate::class)->disabledMessage()));

                return;
            }

            if (! $capabilities['hybrid_enabled']) {
                $validator->errors()->add('draft_compare', 'Hybrid draft generation is not available on your current plan.');

                return;
            }

            $eligibility = app(HybridDraftEligibilityService::class)->checkEligibility($comparison);
            if (! (bool) ($eligibility['eligible'] ?? false)) {
                $validator->errors()->add(
                    'draft_compare',
                    (string) ($eligibility['reason_message'] ?? 'Hybrid draft generation is not available.')
                );
            }
        });
    }
}
