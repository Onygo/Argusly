<?php

namespace App\Services\AgenticMarketing\Intelligence;

use App\Support\Intelligence\EvidenceBag;
use App\Support\Intelligence\IntelligenceGraphEdge;
use App\Support\Intelligence\ReasoningResult;
use App\Support\Intelligence\TimeWindow;
use Carbon\CarbonInterface;

class ReasoningSnapshot
{
    /**
     * @param  array<int, MarketingInsight>  $insights
     * @param  array<int, MarketingRecommendation>  $recommendations
     * @param  array<string, mixed>  $marketPackContext
     * @param  array<int, string>  $missingData
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $workspaceId,
        public readonly ?string $clientSiteId,
        public readonly CarbonInterface $periodStart,
        public readonly CarbonInterface $periodEnd,
        public readonly string $granularity,
        public readonly CarbonInterface $generatedAt,
        public readonly string $modelKey,
        public readonly string $modelVersion,
        public readonly array $insights,
        public readonly array $recommendations,
        public readonly MarketingEvidence $evidence,
        public readonly array $marketPackContext = [],
        public readonly array $missingData = [],
        public readonly array $metadata = [],
    ) {
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'workspace_id' => $this->workspaceId,
            'client_site_id' => $this->clientSiteId,
            'period_start' => $this->periodStart->toDateTimeString(),
            'period_end' => $this->periodEnd->toDateTimeString(),
            'granularity' => $this->granularity,
            'model_key' => $this->modelKey,
            'model_version' => $this->modelVersion,
            'recommendation_keys' => collect($this->recommendations)->pluck('key')->values()->all(),
            'evidence' => [
                'marketing_observation_ids' => $this->evidence->marketingObservationIds,
                'page_snapshot_ids' => $this->evidence->pageSnapshotIds,
                'page_score_ids' => $this->evidence->pageScoreIds,
                'trend_ids' => $this->evidence->trendIds,
                'performance_signal_keys' => $this->evidence->performanceSignalKeys,
                'report_ids' => $this->evidence->reportIds,
                'scheduled_briefing_ids' => $this->evidence->scheduledBriefingIds,
            ],
        ], JSON_THROW_ON_ERROR));
    }

    public function timeWindow(): TimeWindow
    {
        return TimeWindow::between($this->periodStart, $this->periodEnd, $this->granularity);
    }

    public function evidenceBag(): EvidenceBag
    {
        return EvidenceBag::merge(
            $this->evidence->toEvidenceBag($this->timeWindow()),
            ...array_map(fn (MarketingInsight $insight): EvidenceBag => $insight->evidenceBag($this->timeWindow()), $this->insights),
            ...array_map(fn (MarketingRecommendation $recommendation): EvidenceBag => $recommendation->evidenceBag($this->timeWindow()), $this->recommendations),
        );
    }

    /**
     * @return array<int, ReasoningResult>
     */
    public function reasoningResults(): array
    {
        $window = $this->timeWindow();

        return [
            ...array_map(fn (MarketingInsight $insight): ReasoningResult => $insight->toReasoningResult($window), $this->insights),
            ...array_map(fn (MarketingRecommendation $recommendation): ReasoningResult => $recommendation->toReasoningResult($window), $this->recommendations),
        ];
    }

    /**
     * @return array<int, IntelligenceGraphEdge>
     */
    public function graphEdges(): array
    {
        $edges = [];

        foreach ($this->reasoningResults() as $result) {
            foreach ($result->graphEdges() as $edge) {
                $edges[$edge->key()] = $edge;
            }
        }

        return array_values($edges);
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
            'model_key' => $this->modelKey,
            'model_version' => $this->modelVersion,
            'fingerprint' => $this->fingerprint(),
            'insights' => array_map(fn (MarketingInsight $insight): array => $insight->toArray(), $this->insights),
            'recommendations' => array_map(fn (MarketingRecommendation $recommendation): array => $recommendation->toArray(), $this->recommendations),
            'evidence' => $this->evidence->toArray(),
            'market_pack_context' => $this->marketPackContext,
            'missing_data' => $this->missingData,
            'metadata' => $this->metadata,
        ];
    }
}
