<?php

namespace App\Http\Requests\App;

use App\Models\Brief;
use App\Services\CreditWalletService;
use App\Services\DraftComparison\DraftComparisonCreditEstimator;
use Illuminate\Validation\Validator;

class StoreDraftComparisonRequest extends AbstractDraftComparisonSelectionRequest
{
    public function authorize(): bool
    {
        $brief = $this->route('brief');
        $user = $this->user();

        return $brief instanceof Brief
            && $user !== null
            && $user->can('generateDraft', $brief);
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $brief = $this->route('brief');
            if (! $brief instanceof Brief) {
                $validator->errors()->add('brief', 'Brief context is missing.');

                return;
            }

            $selections = app(\App\Services\DraftComparison\DraftComparisonModelCatalog::class)
                ->resolveSelections($this->selectedModelKeys());

            if ($selections === []) {
                $validator->errors()->add('model_keys', 'Selected models are not available for draft compare.');

                return;
            }

            $estimate = app(DraftComparisonCreditEstimator::class)->estimateForComparison(
                brief: $brief,
                selections: $selections,
                requestedMaxOutputTokens: $this->integer('requested_max_output_tokens') ?: null,
                includeScoring: (bool) ($this->compareCapabilities()['scoring_enabled'] ?? false),
                includeHybrid: false,
            );

            $requiredCredits = max(0, (int) ($estimate['estimated_credit_cost'] ?? 0));
            $availableCredits = app(CreditWalletService::class)->getAvailableForClientSite((string) $brief->client_site_id);

            if ($availableCredits < $requiredCredits) {
                $validator->errors()->add(
                    'model_keys',
                    sprintf('Insufficient credits. Required: %d, available: %d.', $requiredCredits, $availableCredits)
                );
            }
        });
    }
}
