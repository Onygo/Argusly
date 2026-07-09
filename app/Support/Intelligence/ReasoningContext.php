<?php

namespace App\Support\Intelligence;

use App\Support\MarketingMetadataRedactor;

class ReasoningContext
{
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
     * @param  array<int, IntelligenceGraphReference|IntelligenceGraphNode|CanonicalEntityReference|string>  $graphReferences
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $provenance
     */
    public function __construct(
        public readonly string $key,
        public readonly ?CanonicalEntityReference $subject = null,
        public readonly ?TimeWindow $timeWindow = null,
        ?EvidenceBag $evidence = null,
        array $graphReferences = [],
        array $metadata = [],
        array $provenance = [],
    ) {
        $subjectReference = $subject instanceof CanonicalEntityReference
            ? [IntelligenceGraphReference::entity($subject)]
            : [];

        $this->evidence = $evidence ?? EvidenceBag::empty();
        $this->graphReferences = self::normalizeGraphReferences([...$subjectReference, ...$graphReferences]);
        $this->metadata = MarketingMetadataRedactor::redact($metadata);
        $this->provenance = MarketingMetadataRedactor::redact($provenance);
    }

    public readonly EvidenceBag $evidence;

    public function withEvidence(EvidenceBag $evidence): self
    {
        return new self(
            key: $this->key,
            subject: $this->subject,
            timeWindow: $this->timeWindow,
            evidence: EvidenceBag::merge($this->evidence, $evidence),
            graphReferences: $this->graphReferences,
            metadata: $this->metadata,
            provenance: $this->provenance,
        );
    }

    /**
     * @return array{key:string,subject:?array<string,mixed>,time_window:?array<string,mixed>,evidence:array<string,mixed>,graph_references:array<int,array<string,mixed>>,metadata:array<string,mixed>,provenance:array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'subject' => $this->subject?->toArray(),
            'time_window' => $this->timeWindow?->toArray(),
            'evidence' => $this->evidence->toArray(),
            'graph_references' => array_map(
                fn (IntelligenceGraphReference $reference): array => $reference->toArray(),
                $this->graphReferences,
            ),
            'metadata' => $this->metadata,
            'provenance' => $this->provenance,
        ];
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
}
