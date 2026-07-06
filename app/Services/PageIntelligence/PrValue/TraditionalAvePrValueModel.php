<?php

namespace App\Services\PageIntelligence\PrValue;

use App\Models\PageSnapshot;
use App\Services\PageIntelligence\PrValue\Concerns\CalculatesPrValueFactors;

class TraditionalAvePrValueModel implements PrValueModel
{
    use CalculatesPrValueFactors;

    public function key(): string
    {
        return 'traditional_ave';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function calculate(PageSnapshot $snapshot): array
    {
        $snapshot = $this->prepared($snapshot);
        $reach = $this->estimatedReach($snapshot);
        $authority = $this->sourceAuthority($snapshot);
        $visibility = $this->pageVisibility($snapshot);
        $sentiment = $this->sentimentFactor($snapshot);
        $score = $this->weightedScore([
            'reach' => ['score' => $this->reachScore($reach), 'weight' => 0.35],
            'source_authority' => ['score' => $authority, 'weight' => 0.30],
            'page_visibility' => ['score' => $visibility, 'weight' => 0.20],
            'sentiment' => ['score' => $sentiment['score'], 'weight' => 0.15],
        ]);

        return [
            'score' => $score,
            'estimated_value_amount' => $this->estimatedValue($score, $reach, 0.12),
            'currency' => 'USD',
            'confidence' => $this->confidence($snapshot, $reach),
            'breakdown' => [
                'formula' => 'Estimated reach x AVE base rate x score multiplier',
                'policy' => 'update_by_snapshot_model_version',
                'factors' => [
                    'reach' => ['raw' => $reach, 'score' => $this->reachScore($reach), 'weight' => 0.35],
                    'source_authority' => ['score' => $authority, 'weight' => 0.30],
                    'page_visibility' => ['score' => $visibility, 'weight' => 0.20],
                    'sentiment' => ['score' => $sentiment['score'], 'label' => $sentiment['label'], 'weight' => 0.15],
                ],
            ],
        ];
    }
}
