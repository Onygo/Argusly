<?php

namespace App\Services\AgenticMarketing\Intelligence;

use App\Support\Intelligence\Evidence;
use App\Support\Intelligence\EvidenceBag;
use App\Support\Intelligence\EvidenceReference;
use App\Support\Intelligence\TimeWindow;

class MarketingEvidence
{
    /**
     * @param  array<int, string>  $marketingObservationIds
     * @param  array<int, string>  $pageSnapshotIds
     * @param  array<int, string>  $pageScoreIds
     * @param  array<int, string>  $trendIds
     * @param  array<int, string>  $performanceSignalKeys
     * @param  array<string, array<int, string>>  $pageIntelligenceInputIds
     * @param  array<int, string>  $reportIds
     * @param  array<int, string>  $scheduledBriefingIds
     * @param  array<string, mixed>  $sourceMetrics
     */
    public function __construct(
        public readonly array $marketingObservationIds = [],
        public readonly array $pageSnapshotIds = [],
        public readonly array $pageScoreIds = [],
        public readonly array $trendIds = [],
        public readonly array $performanceSignalKeys = [],
        public readonly array $pageIntelligenceInputIds = [],
        public readonly array $reportIds = [],
        public readonly array $scheduledBriefingIds = [],
        public readonly array $sourceMetrics = [],
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    public static function merge(self ...$items): self
    {
        if ($items === []) {
            return new self();
        }

        return self::fromEvidence(Evidence::merge(...array_map(fn (self $item): Evidence => $item->toEvidence(), $items)));
    }

    public static function fromEvidence(Evidence $evidence): self
    {
        return new self(
            marketingObservationIds: $evidence->referenceIds('marketing_observation_ids'),
            pageSnapshotIds: $evidence->referenceIds('page_snapshot_ids'),
            pageScoreIds: $evidence->referenceIds('page_score_ids'),
            trendIds: $evidence->referenceIds('trend_ids'),
            performanceSignalKeys: $evidence->referenceIds('performance_signal_keys'),
            pageIntelligenceInputIds: $evidence->nestedReferenceIds('page_intelligence_input_ids'),
            reportIds: $evidence->referenceIds('report_ids'),
            scheduledBriefingIds: $evidence->referenceIds('scheduled_briefing_ids'),
            sourceMetrics: $evidence->sourceMetrics,
        );
    }

    public function toEvidence(): Evidence
    {
        return Evidence::fromArray($this->toArray());
    }

    public function toEvidenceBag(?TimeWindow $timeWindow = null): EvidenceBag
    {
        $references = [];

        foreach ($this->marketingObservationIds as $id) {
            $references[] = EvidenceReference::marketingObservation($id, timeWindow: $timeWindow);
        }

        foreach ($this->pageSnapshotIds as $id) {
            $references[] = EvidenceReference::pageSnapshot($id, timeWindow: $timeWindow);
        }

        foreach ($this->pageScoreIds as $id) {
            $references[] = EvidenceReference::resource(EvidenceReference::TYPE_PAGE_SCORE, $id, timeWindow: $timeWindow);
        }

        foreach ($this->trendIds as $id) {
            $references[] = EvidenceReference::resource(EvidenceReference::TYPE_TREND, $id, timeWindow: $timeWindow);
        }

        foreach ($this->performanceSignalKeys as $key) {
            $references[] = EvidenceReference::performanceSignal($key, timeWindow: $timeWindow);
        }

        foreach ($this->pageIntelligenceInputIds as $inputType => $ids) {
            foreach ((array) $ids as $id) {
                $references[] = EvidenceReference::pageIntelligenceInput((string) $inputType, $id, timeWindow: $timeWindow);
            }
        }

        foreach ($this->reportIds as $id) {
            $references[] = EvidenceReference::report($id, timeWindow: $timeWindow);
        }

        foreach ($this->scheduledBriefingIds as $id) {
            $references[] = EvidenceReference::briefing($id, timeWindow: $timeWindow);
        }

        return new EvidenceBag($references, $this->sourceMetrics);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'marketing_observation_ids' => $this->marketingObservationIds,
            'page_snapshot_ids' => $this->pageSnapshotIds,
            'page_score_ids' => $this->pageScoreIds,
            'trend_ids' => $this->trendIds,
            'performance_signal_keys' => $this->performanceSignalKeys,
            'page_intelligence_input_ids' => $this->pageIntelligenceInputIds,
            'report_ids' => $this->reportIds,
            'scheduled_briefing_ids' => $this->scheduledBriefingIds,
            'source_metrics' => $this->sourceMetrics,
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private static function unique(array $values): array
    {
        return Evidence::unique($values);
    }
}
