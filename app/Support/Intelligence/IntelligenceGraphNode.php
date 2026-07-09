<?php

namespace App\Support\Intelligence;

use App\Support\MarketingMetadataRedactor;

class IntelligenceGraphNode
{
    public readonly IntelligenceGraphReference $reference;

    public readonly ?IntelligenceStage $stage;

    /**
     * @var array<string, mixed>
     */
    public readonly array $metadata;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        IntelligenceGraphReference $reference,
        ?IntelligenceStage $stage = null,
        array $metadata = [],
    ) {
        $this->reference = $reference;
        $this->stage = $stage;
        $this->metadata = MarketingMetadataRedactor::redact($metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function entity(
        CanonicalEntityReference|array|string $entity,
        CanonicalEntityType|string $type = CanonicalEntityType::ORGANIZATION,
        ?IntelligenceStage $stage = null,
        array $metadata = [],
        array $context = [],
        ?EntityReferenceMapper $mapper = null,
    ): self {
        return new self(
            IntelligenceGraphReference::entity($entity, $type, $context, $mapper),
            $stage,
            $metadata,
        );
    }

    public function key(): string
    {
        return $this->reference->graphKey();
    }

    /**
     * @return array{key:string,type:string,label:?string,reference:array<string,mixed>,intelligence_stage:?string,metadata:array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'type' => $this->reference->type,
            'label' => $this->reference->label,
            'reference' => $this->reference->toArray(),
            'intelligence_stage' => $this->stage?->value,
            'metadata' => $this->metadata,
        ];
    }
}
