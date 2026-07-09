<?php

namespace App\Support\Intelligence;

use App\Support\MarketingMetadataRedactor;

class ReasoningResult
{
    /**
     * @var array<string, mixed>
     */
    public readonly array $metadata;

    /**
     * @var array<string, mixed>
     */
    public readonly array $provenance;

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $provenance
     */
    public function __construct(
        public readonly string $pipelineKey,
        public readonly ReasoningContext $context,
        public readonly ReasoningInput $input,
        public readonly ReasoningOutput $output,
        public readonly ReasoningTrace $trace,
        array $metadata = [],
        array $provenance = [],
    ) {
        $this->evidence = EvidenceBag::merge(
            $context->evidence,
            $input->evidence,
            $output->evidence,
            $trace->evidence(),
        );
        $this->confidence = $output->confidence ?? $trace->lastStep()?->confidence ?? $input->confidence;
        $this->priority = $output->priority ?? $trace->lastStep()?->priority ?? $input->priority;
        $this->metadata = MarketingMetadataRedactor::redact($metadata);
        $this->provenance = MarketingMetadataRedactor::redact($provenance);
    }

    public readonly EvidenceBag $evidence;

    public readonly ?float $confidence;

    public readonly ?int $priority;

    /**
     * @return array<int, IntelligenceGraphReference>
     */
    public function graphReferences(): array
    {
        $references = [];

        foreach ([
            ...$this->context->graphReferences,
            $this->input->toGraphReference(),
            $this->output->toGraphReference(),
            ...$this->input->graphReferences,
            ...$this->output->graphReferences,
            ...$this->trace->graphReferences(),
        ] as $reference) {
            $references[$reference->graphKey()] = $reference;
        }

        return array_values($references);
    }

    /**
     * @return array<int, IntelligenceGraphEdge>
     */
    public function graphEdges(): array
    {
        return $this->trace->graphEdges();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pipeline_key' => $this->pipelineKey,
            'context' => $this->context->toArray(),
            'input' => $this->input->toArray(),
            'output' => $this->output->toArray(),
            'trace' => $this->trace->toArray(),
            'evidence' => $this->evidence->toArray(),
            'graph_references' => array_map(
                fn (IntelligenceGraphReference $reference): array => $reference->toArray(),
                $this->graphReferences(),
            ),
            'graph_edges' => array_map(
                fn (IntelligenceGraphEdge $edge): array => $edge->toArray(),
                $this->graphEdges(),
            ),
            'confidence' => $this->confidence,
            'priority' => $this->priority,
            'metadata' => $this->metadata,
            'provenance' => $this->provenance,
        ];
    }
}
