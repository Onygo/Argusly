<?php

namespace App\Services\PerformanceIntelligence;

use Carbon\CarbonInterface;

class PerformanceTrend
{
    /**
     * @param  array<int, array<string, mixed>>  $points
     * @param  array<int, array<string, mixed>>  $rollingAverages
     * @param  array<string, mixed>  $sourceMetrics
     * @param  array<int, string>  $observationIds
     */
    public function __construct(
        public readonly string $metricKey,
        public readonly string $granularity,
        public readonly string $status,
        public readonly string $direction,
        public readonly CarbonInterface $periodStart,
        public readonly CarbonInterface $periodEnd,
        public readonly CarbonInterface $previousPeriodStart,
        public readonly CarbonInterface $previousPeriodEnd,
        public readonly ?float $currentValue,
        public readonly ?float $previousValue,
        public readonly ?float $absoluteChange,
        public readonly ?float $growthPercent,
        public readonly float $confidence,
        public readonly ?float $movingAverage,
        public readonly array $points,
        public readonly array $rollingAverages,
        public readonly array $sourceMetrics,
        public readonly array $observationIds,
        public readonly int $periodsCount,
        public readonly int $expectedPeriods,
    ) {}

    public function isInsufficient(): bool
    {
        return $this->status === 'insufficient_data';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'metric_key' => $this->metricKey,
            'granularity' => $this->granularity,
            'status' => $this->status,
            'direction' => $this->direction,
            'period_start' => $this->periodStart->toDateTimeString(),
            'period_end' => $this->periodEnd->toDateTimeString(),
            'previous_period_start' => $this->previousPeriodStart->toDateTimeString(),
            'previous_period_end' => $this->previousPeriodEnd->toDateTimeString(),
            'current_value' => $this->currentValue,
            'previous_value' => $this->previousValue,
            'absolute_change' => $this->absoluteChange,
            'growth_percent' => $this->growthPercent,
            'confidence' => $this->confidence,
            'moving_average' => $this->movingAverage,
            'points' => $this->points,
            'rolling_averages' => $this->rollingAverages,
            'source_metrics' => $this->sourceMetrics,
            'observation_ids' => $this->observationIds,
            'periods_count' => $this->periodsCount,
            'expected_periods' => $this->expectedPeriods,
        ];
    }
}
