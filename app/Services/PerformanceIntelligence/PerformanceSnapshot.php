<?php

namespace App\Services\PerformanceIntelligence;

use App\Support\Intelligence\EvidenceBag;
use App\Support\Intelligence\EvidenceReference;
use App\Support\Intelligence\IntelligenceSignal;
use App\Support\Intelligence\TimeWindow;
use Carbon\CarbonInterface;

class PerformanceSnapshot
{
    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<int, PerformancePageSummary>  $pages
     * @param  array<int, PerformanceTopicSummary>  $topics
     * @param  array<int, PerformanceChannelSummary>  $channels
     * @param  array<int, PerformanceMarketPackSummary>  $marketPacks
     * @param  array<int, PerformanceSignal>  $signals
     * @param  array<int, string>  $observationIds
     */
    public function __construct(
        public readonly string $workspaceId,
        public readonly ?string $clientSiteId,
        public readonly CarbonInterface $periodStart,
        public readonly CarbonInterface $periodEnd,
        public readonly string $granularity,
        public readonly CarbonInterface $generatedAt,
        public readonly array $metrics,
        public readonly array $pages,
        public readonly array $topics,
        public readonly array $channels,
        public readonly array $marketPacks,
        public readonly array $signals,
        public readonly int $observationsCount,
        public readonly array $observationIds,
    ) {}

    public function timeWindow(): TimeWindow
    {
        return TimeWindow::between($this->periodStart, $this->periodEnd, $this->granularity);
    }

    public function evidenceBag(): EvidenceBag
    {
        $window = $this->timeWindow();
        $base = new EvidenceBag(array_map(
            fn (string $id): EvidenceReference => EvidenceReference::marketingObservation($id, timeWindow: $window),
            $this->observationIds,
        ), [
            'metrics' => $this->metrics,
        ], [
            'workspace_id' => $this->workspaceId,
            'client_site_id' => $this->clientSiteId,
            'granularity' => $this->granularity,
            'observations_count' => $this->observationsCount,
        ]);

        return EvidenceBag::merge(
            $base,
            ...array_map(fn (PerformanceSignal $signal): EvidenceBag => $signal->evidenceBag(), $this->signals),
        );
    }

    /**
     * @return array<int, IntelligenceSignal>
     */
    public function intelligenceSignals(): array
    {
        return array_map(
            fn (PerformanceSignal $signal): IntelligenceSignal => $signal->toIntelligenceSignal(),
            $this->signals,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
            'client_site_id' => $this->clientSiteId,
            'period_start' => $this->periodStart->toDateTimeString(),
            'period_end' => $this->periodEnd->toDateTimeString(),
            'granularity' => $this->granularity,
            'generated_at' => $this->generatedAt->toDateTimeString(),
            'metrics' => $this->metrics,
            'pages' => array_map(fn (PerformancePageSummary $summary): array => $summary->toArray(), $this->pages),
            'topics' => array_map(fn (PerformanceTopicSummary $summary): array => $summary->toArray(), $this->topics),
            'channels' => array_map(fn (PerformanceChannelSummary $summary): array => $summary->toArray(), $this->channels),
            'market_packs' => array_map(fn (PerformanceMarketPackSummary $summary): array => $summary->toArray(), $this->marketPacks),
            'signals' => array_map(fn (PerformanceSignal $signal): array => $signal->toArray(), $this->signals),
            'observations_count' => $this->observationsCount,
            'observation_ids' => $this->observationIds,
        ];
    }
}
