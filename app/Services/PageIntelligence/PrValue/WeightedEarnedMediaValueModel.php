<?php

namespace App\Services\PageIntelligence\PrValue;

use App\Models\PageSnapshot;
use App\Services\PageIntelligence\PrValue\Concerns\CalculatesPrValueFactors;

class WeightedEarnedMediaValueModel implements PrValueModel
{
    use CalculatesPrValueFactors;

    public function key(): string
    {
        return 'weighted_earned_media_value';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function calculate(PageSnapshot $snapshot): array
    {
        $snapshot = $this->prepared($snapshot);
        $reach = $this->estimatedReach($snapshot);
        $sentiment = $this->sentimentFactor($snapshot);
        $prominence = $this->brandProminence($snapshot);
        $topic = $this->topicRelevance($snapshot);
        $depth = $this->contentDepth($snapshot);
        $score = $this->weightedScore([
            'reach' => ['score' => $this->reachScore($reach), 'weight' => 0.25],
            'source_authority' => ['score' => $this->sourceAuthority($snapshot), 'weight' => 0.20],
            'brand_prominence' => ['score' => $prominence, 'weight' => 0.20],
            'topic_relevance' => ['score' => $topic, 'weight' => 0.15],
            'sentiment' => ['score' => $sentiment['score'], 'weight' => 0.10],
            'content_depth' => ['score' => $depth, 'weight' => 0.10],
        ]);

        return [
            'score' => $score,
            'estimated_value_amount' => $this->estimatedValue($score, $reach, 0.10),
            'currency' => 'USD',
            'confidence' => $this->confidence($snapshot, $reach),
            'breakdown' => [
                'formula' => 'Weighted earned media value from authority, reach, prominence, relevance and sentiment.',
                'policy' => 'update_by_snapshot_model_version',
                'factors' => [
                    'reach' => ['raw' => $reach, 'score' => $this->reachScore($reach), 'weight' => 0.25],
                    'source_authority' => ['score' => $this->sourceAuthority($snapshot), 'weight' => 0.20],
                    'brand_prominence' => ['score' => $prominence, 'weight' => 0.20],
                    'topic_relevance' => ['score' => $topic, 'weight' => 0.15],
                    'sentiment' => ['score' => $sentiment['score'], 'label' => $sentiment['label'], 'weight' => 0.10],
                    'content_depth' => ['score' => $depth, 'weight' => 0.10],
                ],
            ],
        ];
    }
}
