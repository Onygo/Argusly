<?php

namespace App\Services\PageIntelligence\Scoring;

use App\Support\Intelligence\Evidence;
use App\Support\Intelligence\EvidenceBag;
use App\Support\Intelligence\EvidenceReference;
use App\Support\Intelligence\TimeWindow;

class ScoreEvidence
{
    /**
     * @param  array<int, string>  $marketingObservationIds
     * @param  array<int, string>  $pageSnapshotIds
     * @param  array<int, string>  $trendIds
     * @param  array<int, string>  $performanceSignalKeys
     * @param  array<string, array<int, string>>  $pageIntelligenceInputIds
     * @param  array<string, mixed>  $sourceMetrics
     */
    public function __construct(
        public readonly array $marketingObservationIds = [],
        public readonly array $pageSnapshotIds = [],
        public readonly array $trendIds = [],
        public readonly array $performanceSignalKeys = [],
        public readonly array $pageIntelligenceInputIds = [],
        public readonly array $sourceMetrics = [],
    ) {
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
            trendIds: $evidence->referenceIds('trend_ids'),
            performanceSignalKeys: $evidence->referenceIds('performance_signal_keys'),
            pageIntelligenceInputIds: $evidence->nestedReferenceIds('page_intelligence_input_ids'),
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
            'trend_ids' => $this->trendIds,
            'performance_signal_keys' => $this->performanceSignalKeys,
            'page_intelligence_input_ids' => $this->pageIntelligenceInputIds,
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
