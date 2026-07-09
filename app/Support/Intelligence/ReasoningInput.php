<?php

namespace App\Support\Intelligence;

use App\Support\MarketingMetadataRedactor;

class ReasoningInput implements HasIntelligenceStage
{
    /**
     * @var array<string, mixed>
     */
    public readonly array $payload;

    /**
     * @var array<int, IntelligenceGraphReference>
     */
    public readonly array $graphReferences;

    /**
     * @var array<string, mixed>
     */
    public readonly array $metadata;

    /**
     * @var array<string, mixed>
     */
    public readonly array $provenance;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, IntelligenceGraphReference|IntelligenceGraphNode|CanonicalEntityReference|string>  $graphReferences
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $provenance
     */
    public function __construct(
        public readonly string $key,
        ReasoningStage|IntelligenceStage|string $stage = ReasoningStage::OBSERVATION,
        public readonly string $type = 'reasoning_input',
        public readonly ?string $summary = null,
        array $payload = [],
        ?EvidenceBag $evidence = null,
        ?TimeWindow $timeWindow = null,
        array $graphReferences = [],
        ?float $confidence = null,
        int|float|null $priority = null,
        array $metadata = [],
        array $provenance = [],
    ) {
        $this->stage = ReasoningStage::normalize($stage);
        $this->payload = $payload;
        $this->evidence = $evidence ?? EvidenceBag::empty();
        $this->timeWindow = $timeWindow;
        $this->graphReferences = self::normalizeGraphReferences($graphReferences);
        $this->confidence = self::normalizeConfidence($confidence);
        $this->priority = self::normalizePriority($priority);
        $this->metadata = MarketingMetadataRedactor::redact($metadata);
        $this->provenance = MarketingMetadataRedactor::redact($provenance);
    }

    public readonly ReasoningStage $stage;

    public readonly EvidenceBag $evidence;

    public readonly ?TimeWindow $timeWindow;

    public readonly ?float $confidence;

    public readonly ?int $priority;

    public static function observation(
        string $key,
        ?string $summary = null,
        ?EvidenceBag $evidence = null,
        ?TimeWindow $timeWindow = null,
        array $graphReferences = [],
        ?float $confidence = null,
        int|float|null $priority = null,
        array $metadata = [],
        array $provenance = [],
    ): self {
        return new self(
            key: $key,
            stage: ReasoningStage::OBSERVATION,
            type: 'observation',
            summary: $summary,
            evidence: $evidence,
            timeWindow: $timeWindow,
            graphReferences: $graphReferences,
            confidence: $confidence,
            priority: $priority,
            metadata: $metadata,
            provenance: $provenance,
        );
    }

    public function intelligenceStage(): IntelligenceStage
    {
        return $this->stage->intelligenceStage();
    }

    public function toGraphReference(): IntelligenceGraphReference
    {
        return new IntelligenceGraphReference(
            type: $this->stage->graphReferenceType(),
            key: $this->key,
            label: $this->summary,
            metadata: [
                'type' => $this->type,
                'confidence' => $this->confidence,
                'priority' => $this->priority,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'intelligence_stage' => $this->intelligenceStage()->value,
            'reasoning_stage' => $this->stage->value,
            'key' => $this->key,
            'type' => $this->type,
            'summary' => $this->summary,
            'payload' => $this->payload,
            'evidence' => $this->evidence->toArray(),
            'time_window' => $this->timeWindow?->toArray(),
            'graph_reference' => $this->toGraphReference()->toArray(),
            'graph_references' => array_map(
                fn (IntelligenceGraphReference $reference): array => $reference->toArray(),
                $this->graphReferences,
            ),
            'confidence' => $this->confidence,
            'priority' => $this->priority,
            'metadata' => $this->metadata,
            'provenance' => $this->provenance,
        ];
    }

    /**
     * @param  array<int, IntelligenceGraphReference|IntelligenceGraphNode|CanonicalEntityReference|string>  $references
     * @return array<int, IntelligenceGraphReference>
     */
    protected static function normalizeGraphReferences(array $references): array
    {
        $normalized = [];

        foreach ($references as $reference) {
            if ($reference instanceof IntelligenceGraphNode) {
                $reference = $reference->reference;
            }

            if ($reference instanceof CanonicalEntityReference) {
                $reference = IntelligenceGraphReference::entity($reference);
            }

            if (is_string($reference)) {
                $reference = IntelligenceGraphReference::reference($reference);
            }

            if (! $reference instanceof IntelligenceGraphReference) {
                continue;
            }

            $normalized[$reference->graphKey()] = $reference;
        }

        return array_values($normalized);
    }

    protected static function normalizeConfidence(?float $confidence): ?float
    {
        if ($confidence === null) {
            return null;
        }

        $confidence = $confidence > 1.0 && $confidence <= 100.0
            ? $confidence / 100.0
            : $confidence;

        return round(max(0.0, min(1.0, $confidence)), 4);
    }

    protected static function normalizePriority(int|float|null $priority): ?int
    {
        if ($priority === null) {
            return null;
        }

        return (int) max(0, min(100, round($priority)));
    }
}
