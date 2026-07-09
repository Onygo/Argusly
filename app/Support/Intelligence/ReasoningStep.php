<?php

namespace App\Support\Intelligence;

use App\Support\MarketingMetadataRedactor;

class ReasoningStep implements HasIntelligenceStage
{
    /**
     * @var array<int, IntelligenceGraphReference>
     */
    public readonly array $graphReferences;

    /**
     * @var array<int, IntelligenceGraphEdge>
     */
    public readonly array $graphEdges;

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
     * @param  array<int, IntelligenceGraphEdge>  $graphEdges
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $provenance
     */
    public function __construct(
        public readonly ReasoningInput $input,
        public readonly ReasoningOutput $output,
        ?EvidenceBag $evidence = null,
        ?TimeWindow $timeWindow = null,
        array $graphReferences = [],
        array $graphEdges = [],
        ?float $confidence = null,
        int|float|null $priority = null,
        array $metadata = [],
        array $provenance = [],
        ?string $key = null,
    ) {
        $this->fromStage = $input->stage;
        $this->toStage = $output->stage;
        $this->key = $key ?? self::makeKey($input, $output);
        $this->evidence = EvidenceBag::merge(
            $input->evidence,
            $output->evidence,
            $evidence ?? EvidenceBag::empty(),
        );
        $this->timeWindow = $timeWindow ?? $output->timeWindow ?? $input->timeWindow;
        $this->confidence = self::normalizeConfidence($confidence ?? $output->confidence ?? $input->confidence);
        $this->priority = self::normalizePriority($priority ?? $output->priority ?? $input->priority);
        $this->metadata = MarketingMetadataRedactor::redact($metadata);
        $this->provenance = MarketingMetadataRedactor::redact($provenance);
        $this->graphReferences = self::normalizeGraphReferences([
            $input->toGraphReference(),
            $output->toGraphReference(),
            ...$input->graphReferences,
            ...$output->graphReferences,
            ...$graphReferences,
        ]);
        $this->graphEdges = self::mergeGraphEdges([
            $this->transitionEdge(),
            ...$graphEdges,
        ]);
    }

    public readonly string $key;

    public readonly ReasoningStage $fromStage;

    public readonly ReasoningStage $toStage;

    public readonly EvidenceBag $evidence;

    public readonly ?TimeWindow $timeWindow;

    public readonly ?float $confidence;

    public readonly ?int $priority;

    public function intelligenceStage(): IntelligenceStage
    {
        return $this->toStage->intelligenceStage();
    }

    public function transition(): string
    {
        return $this->fromStage->value.'_to_'.$this->toStage->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'transition' => $this->transition(),
            'from_stage' => $this->fromStage->value,
            'to_stage' => $this->toStage->value,
            'intelligence_stage' => $this->intelligenceStage()->value,
            'input_key' => $this->input->key,
            'output_key' => $this->output->key,
            'input' => $this->input->toArray(),
            'output' => $this->output->toArray(),
            'evidence' => $this->evidence->toArray(),
            'time_window' => $this->timeWindow?->toArray(),
            'graph_references' => array_map(
                fn (IntelligenceGraphReference $reference): array => $reference->toArray(),
                $this->graphReferences,
            ),
            'graph_edges' => array_map(
                fn (IntelligenceGraphEdge $edge): array => $edge->toArray(),
                $this->graphEdges,
            ),
            'confidence' => $this->confidence,
            'priority' => $this->priority,
            'metadata' => $this->metadata,
            'provenance' => $this->provenance,
        ];
    }

    private function transitionEdge(): IntelligenceGraphEdge
    {
        return new IntelligenceGraphEdge(
            $this->fromStage->edgeTypeTo($this->toStage),
            $this->input->toGraphReference(),
            $this->output->toGraphReference(),
            confidence: $this->confidence,
            evidence: $this->evidence->toEvidence(),
            timeWindow: $this->timeWindow,
            metadata: [
                'transition' => $this->transition(),
                'from_stage' => $this->fromStage->value,
                'to_stage' => $this->toStage->value,
                'priority' => $this->priority,
            ],
            provenance: $this->provenance,
            stage: $this->toStage->intelligenceStage(),
        );
    }

    private static function makeKey(ReasoningInput $input, ReasoningOutput $output): string
    {
        return 'reasoning-step:'.hash('sha1', implode('|', [
            $input->stage->value,
            $input->key,
            $output->stage->value,
            $output->key,
        ]));
    }

    /**
     * @param  array<int, IntelligenceGraphReference|IntelligenceGraphNode|CanonicalEntityReference|string>  $references
     * @return array<int, IntelligenceGraphReference>
     */
    private static function normalizeGraphReferences(array $references): array
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

    /**
     * @param  array<int, IntelligenceGraphEdge>  $edges
     * @return array<int, IntelligenceGraphEdge>
     */
    private static function mergeGraphEdges(array $edges): array
    {
        $merged = [];

        foreach ($edges as $edge) {
            if (! $edge instanceof IntelligenceGraphEdge) {
                continue;
            }

            $merged[$edge->key()] = $edge;
        }

        return array_values($merged);
    }

    private static function normalizeConfidence(?float $confidence): ?float
    {
        if ($confidence === null) {
            return null;
        }

        $confidence = $confidence > 1.0 && $confidence <= 100.0
            ? $confidence / 100.0
            : $confidence;

        return round(max(0.0, min(1.0, $confidence)), 4);
    }

    private static function normalizePriority(int|float|null $priority): ?int
    {
        if ($priority === null) {
            return null;
        }

        return (int) max(0, min(100, round($priority)));
    }
}
