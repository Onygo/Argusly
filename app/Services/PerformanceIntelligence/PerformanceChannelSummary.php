<?php

namespace App\Services\PerformanceIntelligence;

class PerformanceChannelSummary
{
    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, PerformanceTrend>  $trends
     * @param  array<int, string>  $observationIds
     */
    public function __construct(
        public readonly string $channelKey,
        public readonly string $channelName,
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
            'channel_key' => $this->channelKey,
            'channel_name' => $this->channelName,
            'metrics' => $this->metrics,
            'trends' => array_map(fn (PerformanceTrend $trend): array => $trend->toArray(), $this->trends),
            'confidence' => $this->confidence,
            'observation_ids' => $this->observationIds,
        ];
    }
}
