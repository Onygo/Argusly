<?php

namespace App\Services\PerformanceIntelligence;

class PerformancePageSummary
{
    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, PerformanceTrend>  $trends
     * @param  array<int, array<string, string|null>>  $topics
     * @param  array<int, array<string, string|null>>  $entities
     * @param  array<int, string>  $channels
     * @param  array<int, string>  $observationIds
     */
    public function __construct(
        public readonly string $pageId,
        public readonly string $url,
        public readonly ?string $title,
        public readonly array $metrics,
        public readonly array $trends,
        public readonly float $confidence,
        public readonly array $topics,
        public readonly array $entities,
        public readonly array $channels,
        public readonly array $observationIds,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'page_id' => $this->pageId,
            'url' => $this->url,
            'title' => $this->title,
            'metrics' => $this->metrics,
            'trends' => array_map(fn (PerformanceTrend $trend): array => $trend->toArray(), $this->trends),
            'confidence' => $this->confidence,
            'topics' => $this->topics,
            'entities' => $this->entities,
            'channels' => $this->channels,
            'observation_ids' => $this->observationIds,
        ];
    }
}
