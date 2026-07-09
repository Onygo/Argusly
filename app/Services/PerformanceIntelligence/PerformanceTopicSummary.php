<?php

namespace App\Services\PerformanceIntelligence;

class PerformanceTopicSummary
{
    /**
     * @param  array<int, string>  $pageIds
     * @param  array<string, mixed>  $metrics
     * @param  array<string, PerformanceTrend>  $trends
     * @param  array<int, string>  $observationIds
     */
    public function __construct(
        public readonly string $topicKey,
        public readonly string $topicName,
        public readonly array $pageIds,
        public readonly array $metrics,
        public readonly array $trends,
        public readonly float $confidence,
        public readonly array $observationIds,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'topic_key' => $this->topicKey,
            'topic_name' => $this->topicName,
            'page_ids' => $this->pageIds,
            'metrics' => $this->metrics,
            'trends' => array_map(fn (PerformanceTrend $trend): array => $trend->toArray(), $this->trends),
            'confidence' => $this->confidence,
            'observation_ids' => $this->observationIds,
        ];
    }
}
