<?php

namespace App\Support\Intelligence;

use App\Support\MarketingMetadataRedactor;

class IntelligenceGraphEdge
{
    public readonly string $type;

    public readonly IntelligenceGraphReference $source;

    public readonly IntelligenceGraphReference $target;

    public readonly ?float $confidence;

    public readonly Evidence $evidence;

    public readonly ?TimeWindow $timeWindow;

    /**
     * @var array<string, mixed>
     */
    public readonly array $metadata;

    /**
     * @var array<string, mixed>
     */
    public readonly array $provenance;

    public readonly ?IntelligenceStage $stage;

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $provenance
     */
    public function __construct(
        IntelligenceGraphEdgeType|string $type,
        IntelligenceGraphReference|IntelligenceGraphNode $source,
        IntelligenceGraphReference|IntelligenceGraphNode $target,
        ?float $confidence = null,
        ?Evidence $evidence = null,
        ?TimeWindow $timeWindow = null,
        array $metadata = [],
        array $provenance = [],
        ?IntelligenceStage $stage = null,
    ) {
        $this->type = $type instanceof IntelligenceGraphEdgeType ? $type->value : str($type)->lower()->trim()->slug('_')->toString();
        $this->source = $source instanceof IntelligenceGraphNode ? $source->reference : $source;
        $this->target = $target instanceof IntelligenceGraphNode ? $target->reference : $target;
        $this->confidence = $confidence;
        $this->evidence = $evidence ?? new Evidence();
        $this->timeWindow = $timeWindow;
        $this->metadata = MarketingMetadataRedactor::redact($metadata);
        $this->provenance = MarketingMetadataRedactor::redact($provenance);
        $this->stage = $stage;
    }

    public function key(): string
    {
        return hash('sha256', implode('|', [
            $this->type,
            $this->source->graphKey(),
            $this->target->graphKey(),
        ]));
    }

    public function withEvidence(Evidence $evidence): self
    {
        return new self(
            $this->type,
            $this->source,
            $this->target,
            $this->confidence,
            $evidence,
            $this->timeWindow,
            $this->metadata,
            $this->provenance,
            $this->stage,
        );
    }

    public function within(TimeWindow $timeWindow): self
    {
        return new self(
            $this->type,
            $this->source,
            $this->target,
            $this->confidence,
            $this->evidence,
            $timeWindow,
            $this->metadata,
            $this->provenance,
            $this->stage,
        );
    }

    /**
     * @return array{key:string,type:string,source_key:string,target_key:string,source:array<string,mixed>,target:array<string,mixed>,confidence:?float,evidence:array<string,mixed>,time_window:?array<string,mixed>,metadata:array<string,mixed>,provenance:array<string,mixed>,intelligence_stage:?string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'type' => $this->type,
            'source_key' => $this->source->graphKey(),
            'target_key' => $this->target->graphKey(),
            'source' => $this->source->toArray(),
            'target' => $this->target->toArray(),
            'confidence' => $this->confidence,
            'evidence' => $this->evidence->toArray(),
            'time_window' => $this->timeWindow?->toArray(),
            'metadata' => $this->metadata,
            'provenance' => $this->provenance,
            'intelligence_stage' => $this->stage?->value,
        ];
    }
}
