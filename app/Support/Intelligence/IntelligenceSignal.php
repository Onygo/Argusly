<?php

namespace App\Support\Intelligence;

use App\Support\MarketingMetadataRedactor;

class IntelligenceSignal implements HasIntelligenceStage
{
    public readonly string $key;

    public readonly string $type;

    public readonly IntelligenceGraphReference $subject;

    public readonly string $metric;

    public readonly ?float $value;

    public readonly ?float $baseline;

    public readonly ?float $delta;

    public readonly IntelligenceSignalDirection $direction;

    public readonly IntelligenceSignalStrength $strength;

    public readonly float $confidence;

    public readonly Evidence $evidence;

    public readonly ?TimeWindow $timeWindow;

    /**
     * @var array<int, IntelligenceGraphReference>
     */
    public readonly array $graphReferences;

    public readonly ?IntelligenceSignalSource $source;

    /**
     * @var array<string, mixed>
     */
    public readonly array $metadata;

    /**
     * @var array<string, mixed>
     */
    public readonly array $provenance;

    /**
     * @param  array<int, IntelligenceGraphReference|IntelligenceGraphNode|CanonicalEntityReference|string>  $graphReferences
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $provenance
     */
    public function __construct(
        IntelligenceSignalType|string $type,
        CanonicalEntityReference|IntelligenceGraphReference|IntelligenceGraphNode $subject,
        string $metric,
        float|int|null $value = null,
        float|int|null $baseline = null,
        float|int|null $delta = null,
        IntelligenceSignalDirection|string|null $direction = null,
        IntelligenceSignalStrength|string|null $strength = null,
        ?float $confidence = null,
        Evidence|IntelligenceSignalEvidence|null $evidence = null,
        ?TimeWindow $timeWindow = null,
        array $graphReferences = [],
        ?IntelligenceSignalSource $source = null,
        array $metadata = [],
        array $provenance = [],
        ?string $key = null,
    ) {
        $this->type = IntelligenceSignalType::normalize($type);
        $this->subject = self::normalizeSubject($subject);
        $this->metric = self::normalizeMetric($metric);
        $this->value = self::numberOrNull($value);
        $this->baseline = self::numberOrNull($baseline);
        $this->delta = self::numberOrNull($delta) ?? self::deltaFromValues($this->value, $this->baseline);
        $this->direction = IntelligenceSignalDirection::normalize($direction, $this->delta);
        $this->confidence = self::normalizeConfidence($confidence, $this->direction);
        $this->strength = IntelligenceSignalStrength::normalize($strength, $this->confidence, $this->direction);

        $signalEvidence = $evidence instanceof IntelligenceSignalEvidence
            ? $evidence
            : new IntelligenceSignalEvidence($evidence ?? new Evidence());

        $this->evidence = $signalEvidence->evidence;
        $this->timeWindow = $timeWindow;
        $this->graphReferences = IntelligenceSignalEvidence::normalizeGraphReferences([
            ...$signalEvidence->graphReferences,
            ...$graphReferences,
        ]);
        $this->source = $source;
        $this->metadata = MarketingMetadataRedactor::redact($metadata);
        $this->provenance = MarketingMetadataRedactor::redact($provenance);
        $this->key = $key !== null && trim($key) !== ''
            ? trim($key)
            : self::makeKey($this->type, $this->subject, $this->metric, $this->timeWindow, $this->source);
    }

    public static function insufficientData(
        IntelligenceSignalType|string $type,
        CanonicalEntityReference|IntelligenceGraphReference|IntelligenceGraphNode $subject,
        string $metric,
        Evidence|IntelligenceSignalEvidence|null $evidence = null,
        ?TimeWindow $timeWindow = null,
        ?IntelligenceSignalSource $source = null,
        ?string $key = null,
    ): self {
        return new self(
            type: $type,
            subject: $subject,
            metric: $metric,
            direction: IntelligenceSignalDirection::INSUFFICIENT_DATA,
            strength: IntelligenceSignalStrength::INSUFFICIENT,
            confidence: 0.0,
            evidence: $evidence,
            timeWindow: $timeWindow,
            source: $source,
            key: $key,
        );
    }

    public function intelligenceStage(): IntelligenceStage
    {
        return IntelligenceStage::SIGNAL;
    }

    public function withEvidence(Evidence|IntelligenceSignalEvidence $evidence): self
    {
        return new self(
            $this->type,
            $this->subject,
            $this->metric,
            $this->value,
            $this->baseline,
            $this->delta,
            $this->direction,
            $this->strength,
            $this->confidence,
            $evidence,
            $this->timeWindow,
            $this->graphReferences,
            $this->source,
            $this->metadata,
            $this->provenance,
            $this->key,
        );
    }

    public function within(TimeWindow $timeWindow): self
    {
        return new self(
            $this->type,
            $this->subject,
            $this->metric,
            $this->value,
            $this->baseline,
            $this->delta,
            $this->direction,
            $this->strength,
            $this->confidence,
            $this->evidence,
            $timeWindow,
            $this->graphReferences,
            $this->source,
            $this->metadata,
            $this->provenance,
            $this->key,
        );
    }

    public function toGraphReference(): IntelligenceGraphReference
    {
        return IntelligenceGraphReference::signal($this->key, $this->type.':'.$this->metric, [
            'metric_key' => $this->metric,
            'direction' => $this->direction->value,
            'strength' => $this->strength->value,
            'confidence' => $this->confidence,
        ]);
    }

    public function toGraphNode(): IntelligenceGraphNode
    {
        return new IntelligenceGraphNode(
            reference: $this->toGraphReference(),
            stage: $this->intelligenceStage(),
            metadata: [
                'type' => $this->type,
                'metric_key' => $this->metric,
                'direction' => $this->direction->value,
                'strength' => $this->strength->value,
                'confidence' => $this->confidence,
            ],
        );
    }

    /**
     * @return array<int, IntelligenceGraphEdge>
     */
    public function toGraphEdges(): array
    {
        $signalReference = $this->toGraphReference();
        $edgeMetadata = [
            'signal_type' => $this->type,
            'metric_key' => $this->metric,
            'direction' => $this->direction->value,
            'strength' => $this->strength->value,
        ];

        $edges = [
            new IntelligenceGraphEdge(
                IntelligenceGraphEdgeType::MEASURES,
                $this->subject,
                $signalReference,
                confidence: $this->confidence,
                evidence: $this->evidence,
                timeWindow: $this->timeWindow,
                metadata: $edgeMetadata,
                provenance: $this->provenance,
                stage: $this->intelligenceStage(),
            ),
        ];

        foreach ($this->graphReferences as $reference) {
            $edges[] = new IntelligenceGraphEdge(
                $this->edgeTypeForReference($reference),
                $reference,
                $signalReference,
                confidence: $this->confidence,
                evidence: $this->evidence,
                timeWindow: $this->timeWindow,
                metadata: $edgeMetadata,
                provenance: $this->provenance,
                stage: $this->intelligenceStage(),
            );
        }

        return $edges;
    }

    /**
     * @return array{intelligence_stage:string,key:string,type:string,subject_key:string,subject:array<string,mixed>,metric:string,metric_key:string,value:?float,baseline:?float,delta:?float,direction:string,strength:string,confidence:float,evidence:array<string,mixed>,time_window:?array<string,mixed>,graph_references:array<int,array<string,mixed>>,source:?array<string,mixed>,metadata:array<string,mixed>,provenance:array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'intelligence_stage' => $this->intelligenceStage()->value,
            'key' => $this->key,
            'type' => $this->type,
            'subject_key' => $this->subject->graphKey(),
            'subject' => $this->subject->toArray(),
            'metric' => $this->metric,
            'metric_key' => $this->metric,
            'value' => $this->value,
            'baseline' => $this->baseline,
            'delta' => $this->delta,
            'direction' => $this->direction->value,
            'strength' => $this->strength->value,
            'confidence' => $this->confidence,
            'evidence' => $this->evidence->toArray(),
            'time_window' => $this->timeWindow?->toArray(),
            'graph_references' => array_map(
                fn (IntelligenceGraphReference $reference): array => $reference->toArray(),
                $this->graphReferences,
            ),
            'source' => $this->source?->toArray(),
            'metadata' => $this->metadata,
            'provenance' => $this->provenance,
        ];
    }

    private function edgeTypeForReference(IntelligenceGraphReference $reference): IntelligenceGraphEdgeType
    {
        return match ($reference->type) {
            IntelligenceGraphReference::TYPE_OBSERVATION => IntelligenceGraphEdgeType::EVIDENCES,
            IntelligenceGraphReference::TYPE_REFERENCE => IntelligenceGraphEdgeType::DERIVES_FROM,
            default => IntelligenceGraphEdgeType::REFERENCES,
        };
    }

    private static function normalizeSubject(
        CanonicalEntityReference|IntelligenceGraphReference|IntelligenceGraphNode $subject,
    ): IntelligenceGraphReference {
        if ($subject instanceof IntelligenceGraphNode) {
            return $subject->reference;
        }

        if ($subject instanceof IntelligenceGraphReference) {
            return $subject;
        }

        return IntelligenceGraphReference::entity($subject);
    }

    private static function normalizeMetric(string $metric): string
    {
        $metric = trim($metric);

        return $metric !== '' ? $metric : 'metric';
    }

    private static function numberOrNull(float|int|null $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private static function deltaFromValues(?float $value, ?float $baseline): ?float
    {
        if ($value === null || $baseline === null) {
            return null;
        }

        return round($value - $baseline, 6);
    }

    private static function normalizeConfidence(?float $confidence, IntelligenceSignalDirection $direction): float
    {
        if ($confidence === null) {
            return $direction === IntelligenceSignalDirection::INSUFFICIENT_DATA ? 0.0 : 0.5;
        }

        $confidence = $confidence > 1.0 && $confidence <= 100.0
            ? $confidence / 100.0
            : $confidence;

        return round(max(0.0, min(1.0, $confidence)), 4);
    }

    private static function makeKey(
        string $type,
        IntelligenceGraphReference $subject,
        string $metric,
        ?TimeWindow $timeWindow,
        ?IntelligenceSignalSource $source,
    ): string {
        return 'intelligence-signal:'.hash('sha1', implode('|', [
            $type,
            $subject->graphKey(),
            $metric,
            $timeWindow?->start->toDateTimeString() ?? '',
            $timeWindow?->end->toDateTimeString() ?? '',
            $source?->signature() ?? '',
        ]));
    }
}
