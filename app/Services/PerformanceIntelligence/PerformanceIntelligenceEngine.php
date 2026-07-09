<?php

namespace App\Services\PerformanceIntelligence;

use App\Models\ClientSite;
use App\Models\MarketingObservation;
use App\Models\MonitoredPage;
use App\Models\Workspace;
use App\Support\Intelligence\TimeWindowPreset;
use App\Support\Intelligence\TimeWindowResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class PerformanceIntelligenceEngine
{
    public function __construct(
        private readonly PerformanceAggregationService $aggregation,
        private readonly PerformanceTrendService $trends,
        private readonly PerformanceInsightService $insights,
        private readonly TimeWindowResolver $timeWindows,
    ) {
    }

    public function snapshot(
        Workspace $workspace,
        ?ClientSite $clientSite = null,
        CarbonInterface|string|null $from = null,
        CarbonInterface|string|null $to = null,
        string $granularity = MarketingObservation::GRANULARITY_DAILY,
    ): PerformanceSnapshot {
        $window = $this->timeWindows->resolve(TimeWindowPreset::CUSTOM_RANGE, [
            'from' => $from ?: now()->subDays(30),
            'to' => $to ?: now(),
            'granularity' => $granularity,
        ], $workspace, $clientSite);
        $periodStart = $window->start;
        $periodEnd = $window->end;
        $previousStart = $window->previous()->start;

        $observations = $this->aggregation->observations($workspace, $clientSite, $previousStart, $periodEnd);
        $classified = $this->aggregation->classify($observations, $workspace, $clientSite);
        $current = $this->recordsBetween($classified, $periodStart, $periodEnd);
        $currentObservations = $this->aggregation->observationsFromRecords($current);
        $metricTrends = $this->trendsForRecords($classified, array_keys($this->aggregation->metricSummaries($currentObservations)), $periodStart, $periodEnd, $granularity);

        $pages = $this->pageSummaries($classified, $current, $periodStart, $periodEnd, $granularity);
        $topics = $this->topicSummaries($classified, $current, $periodStart, $periodEnd, $granularity);
        $channels = $this->channelSummaries($classified, $current, $periodStart, $periodEnd, $granularity);
        $marketPacks = $this->marketPackSummaries($classified, $current, $periodStart, $periodEnd, $granularity);
        $signals = $this->insights->generate($metricTrends, $pages, $topics, $channels, $marketPacks, $periodStart, $periodEnd);

        return new PerformanceSnapshot(
            workspaceId: (string) $workspace->id,
            clientSiteId: $clientSite?->id ? (string) $clientSite->id : null,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            granularity: $granularity,
            generatedAt: CarbonImmutable::now(),
            metrics: $this->aggregation->metricSummaries($currentObservations),
            pages: $pages,
            topics: $topics,
            channels: $channels,
            marketPacks: $marketPacks,
            signals: $signals,
            observationsCount: $currentObservations->count(),
            observationIds: $this->aggregation->observationIds($currentObservations),
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $all
     * @param  Collection<int, array<string, mixed>>  $current
     * @return array<int, PerformancePageSummary>
     */
    private function pageSummaries(Collection $all, Collection $current, CarbonInterface $from, CarbonInterface $to, string $granularity): array
    {
        $allGroups = $this->aggregation->pageGroups($all);

        return $this->aggregation->pageGroups($current)
            ->map(function (array $group, string $pageId) use ($allGroups, $from, $to, $granularity): PerformancePageSummary {
                /** @var MonitoredPage $page */
                $page = $group['item'];
                $records = $group['records'];
                $observations = $this->aggregation->observationsFromRecords($records);
                $allRecords = ($allGroups->get($pageId)['records'] ?? $records);
                $allObservations = $this->aggregation->observationsFromRecords($allRecords);
                $trends = $this->trendsForRecords($allRecords, array_keys($this->aggregation->metricSummaries($observations)), $from, $to, $granularity);

                return new PerformancePageSummary(
                    pageId: (string) $page->id,
                    url: (string) ($page->canonical_url ?: $page->final_url),
                    title: $page->title_current,
                    metrics: $this->aggregation->metricSummaries($observations),
                    trends: $trends,
                    confidence: $this->aggregation->confidenceFor($observations),
                    topics: $this->uniqueItems($records, 'topics'),
                    entities: $this->uniqueItems($records, 'entities'),
                    channels: $records
                        ->pluck('channel.name')
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                    observationIds: $this->aggregation->observationIds($observations),
                );
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $all
     * @param  Collection<int, array<string, mixed>>  $current
     * @return array<int, PerformanceTopicSummary>
     */
    private function topicSummaries(Collection $all, Collection $current, CarbonInterface $from, CarbonInterface $to, string $granularity): array
    {
        $allGroups = $this->aggregation->topicGroups($all);

        return $this->aggregation->topicGroups($current)
            ->map(function (array $group, string $topicKey) use ($allGroups, $from, $to, $granularity): PerformanceTopicSummary {
                $records = $group['records'];
                $observations = $this->aggregation->observationsFromRecords($records);
                $allRecords = ($allGroups->get($topicKey)['records'] ?? $records);

                return new PerformanceTopicSummary(
                    topicKey: $topicKey,
                    topicName: (string) ($group['item']['name'] ?? $topicKey),
                    pageIds: $this->aggregation->pageIdsFromRecords($records),
                    metrics: $this->aggregation->metricSummaries($observations),
                    trends: $this->trendsForRecords($allRecords, array_keys($this->aggregation->metricSummaries($observations)), $from, $to, $granularity),
                    confidence: $this->aggregation->confidenceFor($observations),
                    observationIds: $this->aggregation->observationIds($observations),
                );
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $all
     * @param  Collection<int, array<string, mixed>>  $current
     * @return array<int, PerformanceChannelSummary>
     */
    private function channelSummaries(Collection $all, Collection $current, CarbonInterface $from, CarbonInterface $to, string $granularity): array
    {
        $allGroups = $this->aggregation->channelGroups($all);

        return $this->aggregation->channelGroups($current)
            ->map(function (array $group, string $channelKey) use ($allGroups, $from, $to, $granularity): PerformanceChannelSummary {
                $records = $group['records'];
                $observations = $this->aggregation->observationsFromRecords($records);
                $allRecords = ($allGroups->get($channelKey)['records'] ?? $records);

                return new PerformanceChannelSummary(
                    channelKey: $channelKey,
                    channelName: (string) ($group['item']['name'] ?? $channelKey),
                    metrics: $this->aggregation->metricSummaries($observations),
                    trends: $this->trendsForRecords($allRecords, array_keys($this->aggregation->metricSummaries($observations)), $from, $to, $granularity),
                    confidence: $this->aggregation->confidenceFor($observations),
                    observationIds: $this->aggregation->observationIds($observations),
                );
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $all
     * @param  Collection<int, array<string, mixed>>  $current
     * @return array<int, PerformanceMarketPackSummary>
     */
    private function marketPackSummaries(Collection $all, Collection $current, CarbonInterface $from, CarbonInterface $to, string $granularity): array
    {
        $allGroups = $this->aggregation->marketPackGroups($all);

        return $this->aggregation->marketPackGroups($current)
            ->map(function (array $group, string $marketPackKey) use ($allGroups, $from, $to, $granularity): PerformanceMarketPackSummary {
                $records = $group['records'];
                $observations = $this->aggregation->observationsFromRecords($records);
                $allRecords = ($allGroups->get($marketPackKey)['records'] ?? $records);

                return new PerformanceMarketPackSummary(
                    marketPackKey: $marketPackKey,
                    marketPackName: (string) ($group['item']['name'] ?? $marketPackKey),
                    pageIds: $this->aggregation->pageIdsFromRecords($records),
                    metrics: $this->aggregation->metricSummaries($observations),
                    trends: $this->trendsForRecords($allRecords, array_keys($this->aggregation->metricSummaries($observations)), $from, $to, $granularity),
                    confidence: $this->aggregation->confidenceFor($observations),
                    observationIds: $this->aggregation->observationIds($observations),
                );
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $records
     * @param  array<int, string>  $metricKeys
     * @return array<string, PerformanceTrend>
     */
    private function trendsForRecords(Collection $records, array $metricKeys, CarbonInterface $from, CarbonInterface $to, string $granularity): array
    {
        $observations = $this->aggregation->observationsFromRecords($records);

        return collect($metricKeys)
            ->unique()
            ->mapWithKeys(fn (string $metricKey): array => [
                $metricKey => $this->trends->trend($observations, $metricKey, $from, $to, $granularity),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $records
     * @return Collection<int, array<string, mixed>>
     */
    private function recordsBetween(Collection $records, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $records
            ->filter(function (array $record) use ($from, $to): bool {
                $observation = $record['observation'] ?? null;

                return $observation instanceof MarketingObservation
                    && $observation->period_start instanceof CarbonInterface
                    && $observation->period_start->greaterThanOrEqualTo($from)
                    && $observation->period_start->lessThanOrEqualTo($to);
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $records
     * @return array<int, array<string, string|null>>
     */
    private function uniqueItems(Collection $records, string $field): array
    {
        return $records
            ->flatMap(fn (array $record): array => (array) ($record[$field] ?? []))
            ->filter(fn (array $item): bool => (string) ($item['key'] ?? '') !== '')
            ->unique(fn (array $item): string => (string) $item['key'])
            ->values()
            ->all();
    }

}
