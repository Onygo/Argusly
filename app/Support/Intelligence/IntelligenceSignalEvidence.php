<?php

namespace App\Support\Intelligence;

use App\Support\MarketingMetadataRedactor;

class IntelligenceSignalEvidence
{
    public readonly Evidence $evidence;

    /**
     * @var array<int, IntelligenceGraphReference>
     */
    public readonly array $graphReferences;

    /**
     * @var array<string, mixed>
     */
    public readonly array $metadata;

    /**
     * @param  array<int, IntelligenceGraphReference|IntelligenceGraphNode|CanonicalEntityReference|string>  $graphReferences
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        ?Evidence $evidence = null,
        array $graphReferences = [],
        array $metadata = [],
    ) {
        $this->evidence = $evidence ?? new Evidence();
        $this->graphReferences = self::normalizeGraphReferences($graphReferences);
        $this->metadata = MarketingMetadataRedactor::redact($metadata);
    }

    /**
     * @param  array<int, IntelligenceGraphReference|IntelligenceGraphNode|CanonicalEntityReference|string>  $graphReferences
     * @param  array<string, mixed>  $metadata
     */
    public static function fromEvidence(
        Evidence $evidence,
        array $graphReferences = [],
        array $metadata = [],
    ): self {
        return new self($evidence, $graphReferences, $metadata);
    }

    public static function merge(self ...$items): self
    {
        if ($items === []) {
            return new self();
        }

        return new self(
            Evidence::merge(...array_map(
                fn (self $item): Evidence => $item->evidence,
                $items,
            )),
            array_merge(...array_map(
                fn (self $item): array => $item->graphReferences,
                $items,
            )),
            array_replace_recursive(...array_map(
                fn (self $item): array => $item->metadata,
                $items,
            )),
        );
    }

    /**
     * @return array{references:array<string,mixed>,graph_references:array<int,array<string,mixed>>,metadata:array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'references' => $this->evidence->toArray(),
            'graph_references' => array_map(
                fn (IntelligenceGraphReference $reference): array => $reference->toArray(),
                $this->graphReferences,
            ),
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param  array<int, IntelligenceGraphReference|IntelligenceGraphNode|CanonicalEntityReference|string>  $references
     * @return array<int, IntelligenceGraphReference>
     */
    public static function normalizeGraphReferences(array $references): array
    {
        return collect($references)
            ->map(fn (mixed $reference): ?IntelligenceGraphReference => self::normalizeGraphReference($reference))
            ->filter()
            ->unique(fn (IntelligenceGraphReference $reference): string => $reference->graphKey())
            ->values()
            ->all();
    }

    private static function normalizeGraphReference(mixed $reference): ?IntelligenceGraphReference
    {
        if ($reference instanceof IntelligenceGraphNode) {
            return $reference->reference;
        }

        if ($reference instanceof IntelligenceGraphReference) {
            return $reference;
        }

        if ($reference instanceof CanonicalEntityReference) {
            return IntelligenceGraphReference::entity($reference);
        }

        if (is_scalar($reference) && trim((string) $reference) !== '') {
            return IntelligenceGraphReference::reference((string) $reference);
        }

        return null;
    }
}
