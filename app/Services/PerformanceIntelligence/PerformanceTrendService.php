<?php

namespace App\Services\PerformanceIntelligence;

use App\Models\MarketingObservation;
use App\Support\Intelligence\TimeWindow;
use App\Support\Intelligence\TimeWindowComparison;
use App\Support\Intelligence\TimeWindowPreset;
use App\Support\Intelligence\TimeWindowResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class PerformanceTrendService
{
    public function __construct(
        private readonly PerformanceAggregationService $aggregation,
        private readonly TimeWindowResolver $timeWindows,
    )
    {
    }

    /**
     * @param  Collection<int, MarketingObservation>  $observations
     */
    public function trend(
        Collection $observations,
        string $metricKey,
        CarbonInterface $from,
        CarbonInterface $to,
        string $granularity = MarketingObservation::GRANULARITY_DAILY,
        int $rollingWindow = 3,
    ): PerformanceTrend {
        $window = $this->timeWindows->resolve(TimeWindowPreset::CUSTOM_RANGE, [
            'from' => $from,
            'to' => $to,
            'granularity' => $granularity,
        ]);
        $from = $window->start;
        $to = $window->end;
        $comparison = TimeWindowComparison::previousPeriod($window);
        $previous = $comparison->comparison;
        $previousFrom = $previous->start;
        $previousTo = $previous->end;

        $metricObservations = $this->aggregation->uniqueObservations($observations)
            ->filter(fn (MarketingObservation $observation): bool => $observation->metric_key === $metricKey)
            ->values();
        $current = $this->between($metricObservations, $from, $to);
        $previous = $this->between($metricObservations, $previousFrom, $previousTo);

        $currentValue = $this->aggregation->aggregateMetricValue($current);
        $previousValue = $this->aggregation->aggregateMetricValue($previous);
        $absoluteChange = $currentValue !== null && $previousValue !== null
            ? round($currentValue - $previousValue, 6)
            : null;
        $growthPercent = $this->growthPercent($currentValue, $previousValue);
        $expectedPeriods = $window->periodsCount();
        $periodsCount = $this->bucketCount($current, $granularity);
        $status = $current->isEmpty() || $previous->isEmpty()
            ? 'insufficient_data'
            : 'sufficient_data';
        $direction = $status === 'insufficient_data'
            ? 'insufficient_data'
            : $this->direction($growthPercent);
        $points = $this->series($metricObservations, $previousFrom, $to, $granularity);
        $rollingAverages = $this->rollingAverages($points, $rollingWindow);
        $movingAverage = $rollingAverages === []
            ? null
            : (float) $rollingAverages[array_key_last($rollingAverages)]['value'];

        return new PerformanceTrend(
            metricKey: $metricKey,
            granularity: $granularity,
            status: $status,
            direction: $direction,
            periodStart: $from,
            periodEnd: $to,
            previousPeriodStart: $previousFrom,
            previousPeriodEnd: $previousTo,
            currentValue: $currentValue,
            previousValue: $previousValue,
            absoluteChange: $absoluteChange,
            growthPercent: $growthPercent,
            confidence: $this->confidence($metricObservations, $current, $expectedPeriods, $periodsCount, $status),
            movingAverage: $movingAverage,
            points: $points,
            rollingAverages: $rollingAverages,
            sourceMetrics: [
                'current' => $this->aggregation->metricSummaries($current)[$metricKey] ?? null,
                'previous' => $this->aggregation->metricSummaries($previous)[$metricKey] ?? null,
                'calculation' => [
                    'change_formula' => 'current_value - previous_value',
                    'growth_percent_formula' => '((current_value - previous_value) / abs(previous_value)) * 100',
                    'rolling_window' => $rollingWindow,
                ],
            ],
            observationIds: $this->aggregation->observationIds($current->merge($previous)),
            periodsCount: $periodsCount,
            expectedPeriods: $expectedPeriods,
        );
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function previousPeriod(CarbonInterface $from, CarbonInterface $to, string $granularity): array
    {
        $previous = $this->timeWindows->resolveComparison(TimeWindowPreset::CUSTOM_RANGE, [
            'from' => $from,
            'to' => $to,
            'granularity' => $granularity,
            'comparison' => TimeWindowComparison::PREVIOUS_PERIOD,
        ])->comparison;

        return [$previous->start, $previous->end];
    }

    public function expectedPeriods(CarbonInterface $from, CarbonInterface $to, string $granularity): int
    {
        return $this->timeWindows->resolve(TimeWindowPreset::CUSTOM_RANGE, [
            'from' => $from,
            'to' => $to,
            'granularity' => $granularity,
        ])->periodsCount();
    }

    /**
     * @param  Collection<int, MarketingObservation>  $observations
     * @return Collection<int, MarketingObservation>
     */
    public function between(Collection $observations, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->aggregation->uniqueObservations($observations)
            ->filter(function (MarketingObservation $observation) use ($from, $to): bool {
                if (! $observation->period_start instanceof CarbonInterface) {
                    return false;
                }

                return $observation->period_start->greaterThanOrEqualTo($from)
                    && $observation->period_start->lessThanOrEqualTo($to);
            })
            ->values();
    }

    /**
     * @param  Collection<int, MarketingObservation>  $observations
     */
    private function bucketCount(Collection $observations, string $granularity): int
    {
        return $observations
            ->map(fn (MarketingObservation $observation): ?string => $observation->period_start instanceof CarbonInterface
                ? $this->bucketKey($observation->period_start, $granularity)
                : null)
            ->filter()
            ->unique()
            ->count();
    }

    /**
     * @param  Collection<int, MarketingObservation>  $observations
     * @return array<int, array<string, mixed>>
     */
    private function series(Collection $observations, CarbonInterface $from, CarbonInterface $to, string $granularity): array
    {
        return $this->between($observations, $from, $to)
            ->groupBy(fn (MarketingObservation $observation): string => $this->bucketKey($observation->period_start, $granularity))
            ->sortKeys()
            ->map(function (Collection $bucketObservations, string $bucket) use ($granularity): array {
                $bucketStart = TimeWindow::periodStart($bucket, $granularity);

                return [
                    'bucket' => $bucket,
                    'period_start' => $bucketStart->toDateTimeString(),
                    'period_end' => TimeWindow::periodEnd($bucketStart, $granularity)->toDateTimeString(),
                    'value' => $this->aggregation->aggregateMetricValue($bucketObservations),
                    'observation_ids' => $this->aggregation->observationIds($bucketObservations),
                    'observation_count' => $this->aggregation->uniqueObservations($bucketObservations)->count(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $points
     * @return array<int, array<string, mixed>>
     */
    private function rollingAverages(array $points, int $window): array
    {
        $window = max(1, $window);
        $rolling = [];

        foreach (array_values($points) as $index => $point) {
            $slice = array_slice($points, max(0, $index - $window + 1), $window);
            $values = collect($slice)
                ->pluck('value')
                ->filter(fn (mixed $value): bool => is_numeric($value))
                ->values();

            if ($values->isEmpty()) {
                continue;
            }

            $rolling[] = [
                'bucket' => $point['bucket'],
                'period_start' => $point['period_start'],
                'period_end' => $point['period_end'],
                'window' => min($window, $values->count()),
                'value' => round((float) $values->avg(), 6),
            ];
        }

        return $rolling;
    }

    private function confidence(Collection $allMetricObservations, Collection $current, int $expectedPeriods, int $periodsCount, string $status): float
    {
        $base = $this->aggregation->confidenceFor($allMetricObservations);
        $coverage = $expectedPeriods > 0 ? min(1.0, $periodsCount / $expectedPeriods) : 0.0;
        $multiplier = $status === 'insufficient_data'
            ? 0.35 * max(0.25, $coverage)
            : 0.6 + (0.4 * $coverage);

        return round(max(0.0, min(1.0, $base * $multiplier)), 4);
    }

    private function growthPercent(?float $currentValue, ?float $previousValue): ?float
    {
        if ($currentValue === null || $previousValue === null) {
            return null;
        }

        if (abs($previousValue) < 0.000001) {
            return abs($currentValue) < 0.000001 ? 0.0 : 100.0;
        }

        return round((($currentValue - $previousValue) / abs($previousValue)) * 100, 4);
    }

    private function direction(?float $growthPercent): string
    {
        if ($growthPercent === null) {
            return 'insufficient_data';
        }

        return match (true) {
            $growthPercent >= 5.0 => 'growth',
            $growthPercent <= -5.0 => 'decline',
            default => 'flat',
        };
    }

    private function bucketKey(CarbonInterface $date, string $granularity): string
    {
        return TimeWindow::bucketKeyFor($date, $granularity);
    }
}
