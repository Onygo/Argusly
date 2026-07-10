<?php

namespace App\Services\DataConnectors\Intelligence;

use App\Contracts\Connectors\Intelligence\ConnectorIntelligenceFeed;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorSyncRun;
use App\Models\Workspace;
use App\Services\Reporting\ConnectorReportingReadService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

abstract class AbstractNormalizedConnectorIntelligenceFeed implements ConnectorIntelligenceFeed
{
    public function __construct(protected readonly ConnectorReportingReadService $reporting) {}

    public function supports(ConnectorDataset $dataset): bool
    {
        $datasetType = strtolower((string) $dataset->dataset_type);
        $provider = strtolower((string) $dataset->provider_key);

        return collect($this->supportedDatasetTypes())->contains(
            fn (string $needle): bool => str_contains($datasetType, $needle) || str_contains($provider, $needle)
        );
    }

    public function consume(ConnectorSyncRun $run): void
    {
        $this->snapshot(
            (string) $run->workspace_id,
            $run->window_start ?: now()->subDays(30),
            $run->window_end ?: now(),
        );
    }

    public function snapshot(
        Workspace|string $workspace,
        Carbon|string $periodStart,
        Carbon|string $periodEnd,
        string $attributionModel = 'last_touch',
    ): array {
        $periodStart = Carbon::parse($periodStart)->startOfDay();
        $periodEnd = Carbon::parse($periodEnd)->endOfDay();
        $days = max(1, $periodStart->diffInDays($periodEnd) + 1);
        $comparisonStart = $periodStart->copy()->subDays($days);
        $comparisonEnd = $periodStart->copy()->subDay()->endOfDay();

        $current = $this->reporting->summary($workspace, $periodStart, $periodEnd, $attributionModel);
        $previous = $this->reporting->summary($workspace, $comparisonStart, $comparisonEnd, $attributionModel);
        $coverage = $this->reporting->coverage($workspace, $periodStart, $periodEnd);

        return [
            'key' => $this->key(),
            'workspace_id' => $workspace instanceof Workspace ? (string) $workspace->id : (string) $workspace,
            'period' => $current['period'],
            'summary' => $this->summaryForFeed($current['metrics']),
            'comparison' => [
                'period' => $previous['period'],
                'metrics' => $this->summaryForFeed($previous['metrics']),
                'deltas' => $this->deltas($current['metrics'], $previous['metrics']),
            ],
            'top_movers' => $this->topMovers($current['metrics'], $previous['metrics']),
            'anomalies' => $this->anomalies($current['metrics'], $coverage),
            'freshness' => $this->reporting->freshness($workspace),
            'coverage' => $coverage,
            'source_completeness' => $this->sourceCompleteness($coverage),
            'attribution_model_used' => $attributionModel,
        ];
    }

    /**
     * @return array<int, string>
     */
    abstract protected function supportedDatasetTypes(): array;

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    protected function summaryForFeed(array $metrics): array
    {
        return collect($this->metricKeys())
            ->mapWithKeys(fn (string $key): array => [$key => $metrics[$key] ?? null])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function metricKeys(): array
    {
        return [
            'impressions',
            'clicks',
            'spend',
            'leads',
            'opportunities',
            'conversions',
            'pipeline_value',
            'revenue',
            'roas',
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @return array<string, array{current: mixed, previous: mixed, absolute: float|null, relative: float|null}>
     */
    private function deltas(array $current, array $previous): array
    {
        return collect($this->metricKeys())
            ->mapWithKeys(function (string $key) use ($current, $previous): array {
                $now = $current[$key] ?? null;
                $before = $previous[$key] ?? null;
                $absolute = is_numeric($now) && is_numeric($before) ? (float) $now - (float) $before : null;

                return [$key => [
                    'current' => $now,
                    'previous' => $before,
                    'absolute' => $absolute,
                    'relative' => $absolute !== null && (float) $before !== 0.0 ? round($absolute / (float) $before, 6) : null,
                ]];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @return array<int, array<string, mixed>>
     */
    private function topMovers(array $current, array $previous): array
    {
        return collect($this->deltas($current, $previous))
            ->filter(fn (array $delta): bool => $delta['absolute'] !== null)
            ->sortByDesc(fn (array $delta): float => abs((float) $delta['absolute']))
            ->take(5)
            ->map(fn (array $delta, string $metric): array => ['metric' => $metric] + $delta)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, int>  $coverage
     * @return array<int, array<string, mixed>>
     */
    private function anomalies(array $metrics, array $coverage): array
    {
        $signals = [];

        if (($metrics['spend'] ?? 0) > 0 && (int) ($metrics['clicks'] ?? 0) === 0) {
            $signals[] = [
                'key' => 'spend_without_clicks',
                'severity' => 'medium',
                'metric' => 'spend',
                'value' => $metrics['spend'],
            ];
        }

        if (($coverage['normalized_performance_rows'] ?? 0) === 0) {
            $signals[] = [
                'key' => 'missing_normalized_performance',
                'severity' => 'high',
                'metric' => 'normalized_performance_rows',
                'value' => 0,
            ];
        }

        if (($coverage['attribution_conversions'] ?? 0) > 0 && ($coverage['attribution_touchpoints'] ?? 0) === 0) {
            $signals[] = [
                'key' => 'conversions_without_touchpoints',
                'severity' => 'medium',
                'metric' => 'attribution_touchpoints',
                'value' => 0,
            ];
        }

        return $signals;
    }

    /**
     * @param  array<string, int>  $coverage
     * @return array<string, mixed>
     */
    private function sourceCompleteness(array $coverage): array
    {
        $checks = [
            'performance' => ($coverage['normalized_performance_rows'] ?? 0) > 0,
            'campaigns' => ($coverage['normalized_campaigns'] ?? 0) > 0,
            'crm_contacts' => ($coverage['crm_contacts'] ?? 0) > 0,
            'crm_deals' => ($coverage['crm_deals'] ?? 0) > 0,
            'attribution' => ($coverage['attribution_conversions'] ?? 0) > 0,
        ];

        $available = Collection::make($checks)->filter()->count();

        return [
            'available_sources' => $available,
            'expected_sources' => count($checks),
            'ratio' => round($available / max(1, count($checks)), 4),
            'sources' => $checks,
        ];
    }
}
