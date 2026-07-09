<?php

namespace App\Services\PageIntelligence\Scoring;

use Carbon\CarbonInterface;

class ScoreBreakdown
{
    /**
     * @param  array<string, float>  $weights
     * @param  array<string, ScoreComponent>  $components
     * @param  array<int, string>  $missingInputs
     */
    public function __construct(
        public readonly string $modelKey,
        public readonly string $modelVersion,
        public readonly string $scoreType,
        public readonly string $calculationMethod,
        public readonly ?string $marketPackKey,
        public readonly string $marketPackSource,
        public readonly CarbonInterface $periodStart,
        public readonly CarbonInterface $periodEnd,
        public readonly array $weights,
        public readonly array $components,
        public readonly array $missingInputs,
        public readonly float $availableWeight,
        public readonly float $missingWeightTotal,
        public readonly float $rawScore,
        public readonly float $confidence,
        public readonly float $confidenceAdjustedScore,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'model_key' => $this->modelKey,
            'model_version' => $this->modelVersion,
            'score_type' => $this->scoreType,
            'calculation_method' => $this->calculationMethod,
            'market_pack_key' => $this->marketPackKey,
            'market_pack_source' => $this->marketPackSource,
            'period_start' => $this->periodStart->toDateTimeString(),
            'period_end' => $this->periodEnd->toDateTimeString(),
            'weights' => $this->weights,
            'components' => collect($this->components)
                ->map(fn (ScoreComponent $component): array => $component->toArray())
                ->all(),
            'missing_inputs' => $this->missingInputs,
            'available_weight' => $this->availableWeight,
            'missing_weight_total' => $this->missingWeightTotal,
            'raw_score' => $this->rawScore,
            'confidence' => $this->confidence,
            'confidence_adjusted_score' => $this->confidenceAdjustedScore,
            'weighted_total' => $this->rawScore,
            'display_score' => $this->confidenceAdjustedScore,
        ];
    }
}
