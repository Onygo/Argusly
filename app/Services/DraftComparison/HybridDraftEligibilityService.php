<?php

namespace App\Services\DraftComparison;

use App\Models\Draft;
use App\Models\DraftComparison;
use App\Models\DraftComparisonVariant;
use App\Services\CreditWalletService;

class HybridDraftEligibilityService
{
    public const REASON_NOT_ENOUGH_SUCCESSFUL_VARIANTS = 'not_enough_successful_variants';

    public const REASON_FEATURE_NOT_AVAILABLE_ON_PLAN = 'feature_not_available_on_plan';

    public const REASON_GENERATION_ALREADY_RUNNING = 'generation_already_running';

    public const REASON_COMPARISON_NOT_FOUND = 'comparison_not_found';

    public const REASON_NO_SOURCE_CONTENT_AVAILABLE = 'no_source_content_available';

    public const REASON_INSUFFICIENT_CREDITS = 'insufficient_credits';

    public const REASON_COMPARISON_NOT_TERMINAL = 'comparison_not_terminal';

    public const REASON_HYBRID_ALREADY_GENERATED = 'hybrid_already_generated';

    public const REASON_COMPARE_DISABLED = 'compare_disabled';

    public function __construct(
        private readonly DraftComparisonFeatureGate $featureGate,
        private readonly CreditWalletService $creditWalletService,
        private readonly DraftComparisonCreditEstimator $creditEstimator,
    ) {}

    /**
     * Check if hybrid generation is eligible for a comparison.
     *
     * @return array{
     *   eligible: bool,
     *   reason: ?string,
     *   reason_message: ?string,
     *   can_retry: bool,
     *   successful_variant_count: int,
     *   required_variant_count: int,
     *   estimated_credit_cost: ?int,
     *   available_credits: ?int
     * }
     */
    public function checkEligibility(DraftComparison $comparison): array
    {
        $comparison->loadMissing([
            'brief.clientSite.workspace',
            'clientSite.workspace',
            'hybridDraft',
            'variants.draft',
            'items.draft',
        ]);

        $result = $this->buildEligibilityResult();

        // Check comparison exists (for completeness, model binding should handle this)
        if (! $comparison->exists) {
            return $this->ineligible($result, self::REASON_COMPARISON_NOT_FOUND, 'Draft comparison not found.', false);
        }

        // Check feature enabled
        $capabilities = $this->featureGate->capabilitiesForComparison($comparison);
        if (! $capabilities['enabled']) {
            return $this->ineligible(
                $result,
                self::REASON_COMPARE_DISABLED,
                $capabilities['blocked_reason'] ?? $this->featureGate->disabledMessage(),
                false
            );
        }

        // Check hybrid feature enabled on plan
        if (! $capabilities['hybrid_enabled']) {
            return $this->ineligible(
                $result,
                self::REASON_FEATURE_NOT_AVAILABLE_ON_PLAN,
                'Hybrid draft generation is not available on your current plan. Upgrade to unlock this feature.',
                false
            );
        }

        // Check comparison has finalized before hybrid synthesis.
        if (! $comparison->isTerminal()) {
            return $this->ineligible(
                $result,
                self::REASON_COMPARISON_NOT_TERMINAL,
                'Wait for the comparison to complete before generating a hybrid draft.',
                true
            );
        }

        // Check if already running
        if (in_array((string) $comparison->hybrid_status, ['queued', 'generating'], true)) {
            return $this->ineligible(
                $result,
                self::REASON_GENERATION_ALREADY_RUNNING,
                'Hybrid draft generation is already in progress.',
                false
            );
        }

        // Check if hybrid draft already exists.
        if ($this->hasGeneratedHybrid($comparison)) {
            return $this->ineligible(
                $result,
                self::REASON_HYBRID_ALREADY_GENERATED,
                'A hybrid draft has already been generated for this comparison.',
                false
            );
        }

        // Count successful variants
        $successfulVariants = $this->countSuccessfulVariants($comparison);
        $result['successful_variant_count'] = $successfulVariants;

        // Check minimum variant requirement
        if ($successfulVariants < 2) {
            return $this->ineligible(
                $result,
                self::REASON_NOT_ENOUGH_SUCCESSFUL_VARIANTS,
                'At least 2 successful model outputs are required to generate a hybrid draft.',
                true
            );
        }

        // Verify source content availability
        if (! $this->hasSourceContent($comparison)) {
            return $this->ineligible(
                $result,
                self::REASON_NO_SOURCE_CONTENT_AVAILABLE,
                'Source drafts do not have content available for hybrid synthesis.',
                false
            );
        }

        // Estimate credit cost
        $estimatedCredits = $this->estimateHybridCredits($comparison);
        $result['estimated_credit_cost'] = $estimatedCredits;

        // Check available credits
        $availableCredits = $this->creditWalletService->getAvailableForClientSite((string) $comparison->client_site_id);
        $result['available_credits'] = $availableCredits;

        if ($availableCredits < $estimatedCredits) {
            return $this->ineligible(
                $result,
                self::REASON_INSUFFICIENT_CREDITS,
                sprintf(
                    'Insufficient credits. Required: %d, available: %d.',
                    $estimatedCredits,
                    $availableCredits
                ),
                false
            );
        }

        // All checks passed
        $result['eligible'] = true;

        return $result;
    }

    /**
     * Quick boolean check for eligibility.
     */
    public function canGenerateHybrid(DraftComparison $comparison): bool
    {
        return $this->checkEligibility($comparison)['eligible'];
    }

    /**
     * Get reason message for ineligibility.
     */
    public function getIneligibilityMessage(DraftComparison $comparison): ?string
    {
        $result = $this->checkEligibility($comparison);

        return $result['eligible'] ? null : $result['reason_message'];
    }

    /**
     * Assert that hybrid generation can proceed, throwing on failure.
     *
     * @throws \RuntimeException
     */
    public function assertCanGenerateHybrid(DraftComparison $comparison): void
    {
        $result = $this->checkEligibility($comparison);

        if (! $result['eligible']) {
            throw new \RuntimeException($result['reason_message'] ?? 'Hybrid generation is not available.');
        }
    }

    /**
     * Get user-friendly message for a reason code.
     */
    public function messageForReason(string $reason): string
    {
        return match ($reason) {
            self::REASON_NOT_ENOUGH_SUCCESSFUL_VARIANTS => 'At least 2 successful model outputs are required.',
            self::REASON_FEATURE_NOT_AVAILABLE_ON_PLAN => 'Hybrid drafts are not available on your current plan.',
            self::REASON_GENERATION_ALREADY_RUNNING => 'Hybrid generation is already in progress.',
            self::REASON_COMPARISON_NOT_FOUND => 'Draft comparison not found.',
            self::REASON_NO_SOURCE_CONTENT_AVAILABLE => 'Source content is not available.',
            self::REASON_INSUFFICIENT_CREDITS => 'Insufficient credits available.',
            self::REASON_COMPARISON_NOT_TERMINAL => 'Wait for the comparison to complete before generating a hybrid.',
            self::REASON_HYBRID_ALREADY_GENERATED => 'A hybrid draft has already been generated.',
            self::REASON_COMPARE_DISABLED => 'Draft Compare is not available.',
            default => 'Hybrid generation is not available.',
        };
    }

    /**
     * @return array{eligible:bool,reason:?string,reason_message:?string,can_retry:bool,successful_variant_count:int,required_variant_count:int,estimated_credit_cost:?int,available_credits:?int}
     */
    private function buildEligibilityResult(): array
    {
        return [
            'eligible' => false,
            'reason' => null,
            'reason_message' => null,
            'can_retry' => false,
            'successful_variant_count' => 0,
            'required_variant_count' => 2,
            'estimated_credit_cost' => null,
            'available_credits' => null,
        ];
    }

    /**
     * @param array{eligible:bool,reason:?string,reason_message:?string,can_retry:bool,successful_variant_count:int,required_variant_count:int,estimated_credit_cost:?int,available_credits:?int} $result
     * @return array{eligible:bool,reason:?string,reason_message:?string,can_retry:bool,successful_variant_count:int,required_variant_count:int,estimated_credit_cost:?int,available_credits:?int}
     */
    private function ineligible(array $result, string $reason, string $message, bool $canRetry): array
    {
        $result['eligible'] = false;
        $result['reason'] = $reason;
        $result['reason_message'] = $message;
        $result['can_retry'] = $canRetry;

        return $result;
    }

    private function countSuccessfulVariants(DraftComparison $comparison): int
    {
        // Prefer variants if they exist
        if ($comparison->variants->isNotEmpty()) {
            return $comparison->variants
                ->filter(function (DraftComparisonVariant $variant): bool {
                    if ((string) $variant->status !== DraftComparisonVariant::STATUS_COMPLETED) {
                        return false;
                    }

                    $draft = $variant->draft;

                    return $draft !== null
                        && (string) $draft->status === 'generated'
                        && trim((string) $draft->content_html) !== '';
                })
                ->count();
        }

        // Fallback to legacy items
        return $comparison->items
            ->filter(function ($item): bool {
                $draft = $item->draft;

                return in_array((string) $item->status, ['generated', 'completed'], true)
                    && $draft !== null
                    && (string) $draft->status === 'generated'
                    && trim((string) $draft->content_html) !== '';
            })
            ->count();
    }

    private function hasGeneratedHybrid(DraftComparison $comparison): bool
    {
        $hybridDraft = $comparison->hybridDraft;
        if (! $hybridDraft && $comparison->hybrid_draft_id) {
            $hybridDraft = Draft::query()->find((string) $comparison->hybrid_draft_id);
        }

        return $hybridDraft !== null && (string) $hybridDraft->status === 'generated';
    }

    private function hasSourceContent(DraftComparison $comparison): bool
    {
        return $this->countSuccessfulVariants($comparison) >= 2;
    }

    private function estimateHybridCredits(DraftComparison $comparison): int
    {
        $brief = $comparison->brief ?: $comparison->brief()->first();
        if (! $brief) {
            return max(1, (int) data_get($comparison->meta, 'per_draft_credits', 1));
        }

        // Get selections from successful variants
        $successfulVariants = $comparison->variants->isNotEmpty()
            ? $comparison->variants
                ->filter(fn ($v) => (string) $v->status === DraftComparisonVariant::STATUS_COMPLETED && $v->draft)
                ->map(fn ($v) => ['provider' => $v->provider_key, 'model' => $v->model_key])
            : $comparison->items
                ->filter(fn ($i) => in_array((string) $i->status, ['generated', 'completed'], true) && $i->draft)
                ->map(fn ($i) => ['provider' => $i->provider, 'model' => $i->model]);

        $selections = $successfulVariants
            ->unique(fn ($s) => $s['provider'] . ':' . $s['model'])
            ->values()
            ->all();

        if (empty($selections)) {
            return max(1, (int) data_get($comparison->meta, 'per_draft_credits', 1));
        }

        $estimate = $this->creditEstimator->estimateForComparison(
            brief: $brief,
            selections: $selections,
            requestedMaxOutputTokens: $comparison->requested_max_output_tokens,
            includeScoring: false,
            includeHybrid: true,
        );

        return max(1, (int) ($estimate['hybrid_credit_cost'] ?? data_get($comparison->meta, 'per_draft_credits', 1)));
    }
}
