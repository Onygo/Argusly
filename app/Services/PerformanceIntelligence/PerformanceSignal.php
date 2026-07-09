<?php

namespace App\Services\PerformanceIntelligence;

use App\Support\Intelligence\EvidenceBag;
use App\Support\Intelligence\EvidenceReference;
use App\Support\Intelligence\HasIntelligenceStage;
use App\Support\Intelligence\IntelligenceGraphReference;
use App\Support\Intelligence\IntelligenceStage;
use App\Support\Intelligence\IntelligenceSignal;
use App\Support\Intelligence\IntelligenceSignalEvidence;
use App\Support\Intelligence\IntelligenceSignalSource;
use App\Support\Intelligence\TimeWindow;
use Carbon\CarbonInterface;

class PerformanceSignal implements HasIntelligenceStage
{
    /**
     * @param  array<string, mixed>  $sourceMetrics
     * @param  array<int, string>  $observationIds
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string $subjectType,
        public readonly string $subjectKey,
        public readonly ?string $subjectName,
        public readonly string $metricKey,
        public readonly string $direction,
        public readonly float $confidence,
        public readonly CarbonInterface $periodStart,
        public readonly CarbonInterface $periodEnd,
        public readonly array $sourceMetrics,
        public readonly array $observationIds,
        public readonly string $explanation,
        public readonly array $metadata = [],
    ) {}

    public function intelligenceStage(): IntelligenceStage
    {
        return IntelligenceStage::SIGNAL;
    }

    public function timeWindow(): TimeWindow
    {
        return TimeWindow::between($this->periodStart, $this->periodEnd);
    }

    public function evidenceBag(): EvidenceBag
    {
        $window = $this->timeWindow();
        $references = [
            EvidenceReference::performanceSignal(
                $this->key,
                $this->explanation,
                confidence: $this->confidence,
                reason: $this->explanation,
                timeWindow: $window,
                metadata: [
                    'type' => $this->type,
                    'subject_type' => $this->subjectType,
                    'subject_key' => $this->subjectKey,
                    'metric_key' => $this->metricKey,
                    'direction' => $this->direction,
                ],
            ),
        ];

        foreach ($this->observationIds as $id) {
            $references[] = EvidenceReference::marketingObservation(
                $id,
                confidence: $this->confidence,
                timeWindow: $window,
                metadata: [
                    'metric_key' => $this->metricKey,
                    'performance_signal_key' => $this->key,
                ],
            );
        }

        return new EvidenceBag($references, $this->sourceMetrics, [
            'explanation' => $this->explanation,
        ]);
    }

    public function toIntelligenceSignal(): IntelligenceSignal
    {
        $bag = $this->evidenceBag();

        return new IntelligenceSignal(
            type: $this->type,
            subject: $this->subjectGraphReference(),
            metric: $this->metricKey,
            value: $this->numberFromMetadata('current_value'),
            baseline: $this->numberFromMetadata('previous_value'),
            delta: $this->numberFromMetadata('absolute_change'),
            direction: $this->direction,
            confidence: $this->confidence,
            evidence: new IntelligenceSignalEvidence(
                $bag->toEvidence(),
                $this->observationGraphReferences(),
                $bag->metadata,
            ),
            timeWindow: $this->timeWindow(),
            source: new IntelligenceSignalSource(
                provider: 'performance_intelligence',
                dataset: 'canonical_marketing_observations',
                key: $this->key,
                label: $this->type,
                metadata: [
                    'subject_type' => $this->subjectType,
                    'subject_key' => $this->subjectKey,
                ],
            ),
            metadata: $this->metadata + [
                'legacy_signal_key' => $this->key,
                'explanation' => $this->explanation,
            ],
            provenance: [
                'projector' => 'performance_signal_read_model',
                'storage_mutated' => false,
            ],
            key: $this->key,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'intelligence_stage' => $this->intelligenceStage()->value,
            'key' => $this->key,
            'type' => $this->type,
            'subject_type' => $this->subjectType,
            'subject_key' => $this->subjectKey,
            'subject_name' => $this->subjectName,
            'metric_key' => $this->metricKey,
            'direction' => $this->direction,
            'confidence' => $this->confidence,
            'period_start' => $this->periodStart->toDateTimeString(),
            'period_end' => $this->periodEnd->toDateTimeString(),
            'source_metrics' => $this->sourceMetrics,
            'observation_ids' => $this->observationIds,
            'explanation' => $this->explanation,
            'metadata' => $this->metadata,
        ];
    }

    private function subjectGraphReference(): IntelligenceGraphReference
    {
        return match (str($this->subjectType)->lower()->trim()->slug('_')->toString()) {
            'page', 'monitored_page' => IntelligenceGraphReference::page($this->subjectKey, $this->subjectName),
            'topic' => IntelligenceGraphReference::topic($this->subjectKey, $this->subjectName),
            'workspace' => IntelligenceGraphReference::make('workspace', $this->subjectKey, $this->subjectName),
            'channel' => IntelligenceGraphReference::make('channel', $this->subjectKey, $this->subjectName),
            'market_pack' => IntelligenceGraphReference::make('market_pack', $this->subjectKey, $this->subjectName),
            'metric' => IntelligenceGraphReference::make('metric', $this->subjectKey, $this->subjectName),
            default => IntelligenceGraphReference::reference($this->subjectType.':'.$this->subjectKey, $this->subjectName),
        };
    }

    /**
     * @return array<int, IntelligenceGraphReference>
     */
    private function observationGraphReferences(): array
    {
        return array_map(
            fn (string $id): IntelligenceGraphReference => IntelligenceGraphReference::observation($id, $this->metricKey.' observation'),
            $this->observationIds,
        );
    }

    private function numberFromMetadata(string $key): ?float
    {
        $value = $this->metadata[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }
}
