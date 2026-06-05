<?php

namespace App\Services\DraftComparison;

use App\Models\Brief;
use App\Services\Credits\GenerationPricing;

class DraftComparisonCreditEstimator
{
    public function __construct(
        private readonly GenerationPricing $pricing,
    ) {}

    /**
     * @param array<int, array{provider?:string,model?:string,key?:string}> $selections
     * @return array{
     *   generation_type:string,
     *   requested_max_output_tokens:int,
     *   requested_model_count:int,
     *   estimated_input_tokens:int,
     *   estimated_output_tokens:int,
     *   per_model_baseline_credits:int,
     *   per_draft_credits:int,
     *   variants:array<int, array{provider:string,model:string,provider_multiplier:float,model_tier_multiplier:float,estimated_credit_cost:int}>,
     *   scoring_credit_cost:int,
     *   hybrid_credit_cost:int,
     *   estimated_credit_cost:int,
     *   total_credits:int
     * }
     */
    public function estimateForComparison(
        Brief $brief,
        array $selections,
        ?int $requestedMaxOutputTokens = null,
        bool $includeScoring = false,
        bool $includeHybrid = false,
    ): array {
        $generationType = $this->generationTypeForBrief($brief);
        $normalizedTokens = $this->pricing->normalizeRequestedMaxOutputTokens(
            $generationType,
            $requestedMaxOutputTokens
        );
        $baselineCredits = $this->pricing->requiredCredits($generationType, $normalizedTokens);

        $normalizedSelections = collect($selections)
            ->map(function (array $selection): array {
                $provider = strtolower(trim((string) ($selection['provider'] ?? '')));
                $model = trim((string) ($selection['model'] ?? ''));

                if ($provider === '' && isset($selection['key']) && str_contains((string) $selection['key'], ':')) {
                    [$providerFromKey, $modelFromKey] = explode(':', (string) $selection['key'], 2);
                    $provider = strtolower(trim((string) $providerFromKey));
                    $model = trim((string) $modelFromKey);
                }

                return [
                    'provider' => $provider,
                    'model' => $model,
                ];
            })
            ->filter(fn (array $selection): bool => $selection['provider'] !== '' && $selection['model'] !== '')
            ->values();

        $variants = $normalizedSelections->map(function (array $selection) use ($baselineCredits): array {
            $providerMultiplier = $this->providerMultiplier($selection['provider']);
            $modelTierMultiplier = $this->modelTierMultiplier($selection['model']);
            $estimatedCredits = (int) ceil($baselineCredits * $providerMultiplier * $modelTierMultiplier);

            return [
                'provider' => $selection['provider'],
                'model' => $selection['model'],
                'provider_multiplier' => $providerMultiplier,
                'model_tier_multiplier' => $modelTierMultiplier,
                'estimated_credit_cost' => max(1, $estimatedCredits),
            ];
        })->values();

        $requestedModelCount = (int) $variants->count();
        $variantCreditTotal = (int) $variants->sum('estimated_credit_cost');
        $scoringCredits = $includeScoring
            ? max(0, (int) config('credits.draft_compare.scoring_credits_per_variant', 0)) * $requestedModelCount
            : 0;

        $hybridCredits = 0;
        if ($includeHybrid) {
            $hybridMultiplier = max(0.0, (float) config('credits.draft_compare.hybrid_credit_multiplier', 1.0));
            $hybridScoringCredits = max(0, (int) config('credits.draft_compare.hybrid_scoring_credits', 0));
            $hybridCredits = max(1, (int) ceil($baselineCredits * $hybridMultiplier)) + $hybridScoringCredits;
        }

        $inputOutputRatio = max(0.0, (float) config('credits.draft_compare.estimated_input_to_output_ratio', 0.6));
        $estimatedOutputTokens = $normalizedTokens * $requestedModelCount;
        if ($includeHybrid) {
            $estimatedOutputTokens += $normalizedTokens;
        }

        return [
            'generation_type' => $generationType,
            'requested_max_output_tokens' => $normalizedTokens,
            'requested_model_count' => $requestedModelCount,
            'estimated_input_tokens' => (int) ceil($estimatedOutputTokens * $inputOutputRatio),
            'estimated_output_tokens' => $estimatedOutputTokens,
            'per_model_baseline_credits' => $baselineCredits,
            'per_draft_credits' => $baselineCredits,
            'variants' => $variants->all(),
            'scoring_credit_cost' => $scoringCredits,
            'hybrid_credit_cost' => $hybridCredits,
            'estimated_credit_cost' => $variantCreditTotal + $scoringCredits + $hybridCredits,
            'total_credits' => $variantCreditTotal + $scoringCredits + $hybridCredits,
        ];
    }

    private function generationTypeForBrief(Brief $brief): string
    {
        return match ((string) ($brief->output_type ?? 'kb_article')) {
            'kb_article', 'article' => GenerationPricing::TYPE_ARTICLE,
            default => GenerationPricing::TYPE_ARTICLE,
        };
    }

    private function providerMultiplier(string $provider): float
    {
        $provider = strtolower(trim($provider));
        if ($provider === '') {
            return 1.0;
        }

        $factor = (float) config('llm.pricing.token_factor.' . $provider, 1.0);

        return max(0.1, $factor);
    }

    private function modelTierMultiplier(string $model): float
    {
        $model = strtolower(trim($model));
        if ($model === '') {
            return 1.0;
        }

        $tiers = (array) config('credits.draft_compare.model_tier_multipliers', []);
        foreach ($tiers as $tier => $factor) {
            if ($tier !== '' && str_contains($model, strtolower((string) $tier))) {
                return max(0.1, (float) $factor);
            }
        }

        return 1.0;
    }
}
