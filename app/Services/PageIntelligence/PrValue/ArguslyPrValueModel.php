<?php

namespace App\Services\PageIntelligence\PrValue;

use App\Models\PageSnapshot;
use App\Services\PageIntelligence\PrValue\Concerns\CalculatesPrValueFactors;

class ArguslyPrValueModel implements PrValueModel
{
    use CalculatesPrValueFactors;

    public function key(): string
    {
        return 'argusly_pr_value';
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
        $factors = [
            'source_authority' => ['score' => $this->sourceAuthority($snapshot), 'weight' => 0.14],
            'estimated_reach' => ['score' => $this->reachScore($reach), 'weight' => 0.10, 'raw' => $reach],
            'page_visibility' => ['score' => $this->pageVisibility($snapshot), 'weight' => 0.10],
            'sentiment' => ['score' => $sentiment['score'], 'weight' => 0.12, 'compound_score' => $sentiment['compound_score'], 'label' => $sentiment['label']],
            'brand_prominence' => ['score' => $this->brandProminence($snapshot), 'weight' => 0.14],
            'topic_relevance' => ['score' => $this->topicRelevance($snapshot), 'weight' => 0.10],
            'campaign_relevance' => ['score' => 50, 'weight' => 0.04, 'placeholder' => true],
            'content_depth' => ['score' => $this->contentDepth($snapshot), 'weight' => 0.08],
            'industry_relevance' => ['score' => $this->industryRelevance($snapshot), 'weight' => 0.08],
            'competitor_context' => ['score' => $this->competitorContext($snapshot), 'weight' => 0.05],
            'recency' => ['score' => $this->recency($snapshot), 'weight' => 0.05],
        ];
        $score = $this->weightedScore($factors);

        return [
            'score' => $score,
            'estimated_value_amount' => $this->estimatedValue($score, $reach, 0.14),
            'currency' => 'USD',
            'confidence' => $this->confidence($snapshot, $reach, 6),
            'breakdown' => [
                'formula' => 'Argusly explainable PR Value v1 weighted model.',
                'policy' => 'update_by_snapshot_model_version',
                'factors' => $factors,
                'placeholders' => [
                    'serp' => null,
                    'geo' => null,
                    'campaign_relevance' => 'placeholder_until_campaign_matching',
                ],
            ],
        ];
    }
}
