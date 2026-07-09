<?php

namespace App\Support\Intelligence;

use App\Support\MarketingMetadataRedactor;

class EvidenceBag
{
    /**
     * @var array<int, EvidenceReference>
     */
    public readonly array $references;

    /**
     * @var array<string, mixed>
     */
    public readonly array $sourceMetrics;

    /**
     * @var array<string, mixed>
     */
    public readonly array $metadata;

    /**
     * @param  iterable<int, EvidenceReference>  $references
     * @param  array<string, mixed>  $sourceMetrics
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        iterable $references = [],
        array $sourceMetrics = [],
        array $metadata = [],
    ) {
        $this->references = self::mergeReferences($references);
        $this->sourceMetrics = $sourceMetrics;
        $this->metadata = MarketingMetadataRedactor::redact($metadata);
    }

    public static function empty(): self
    {
        return new self();
    }

    public static function merge(self ...$bags): self
    {
        if ($bags === []) {
            return self::empty();
        }

        $references = [];
        $sourceMetrics = [];
        $metadata = [];

        foreach ($bags as $bag) {
            $references = array_merge($references, $bag->references);
            $sourceMetrics = array_replace_recursive($sourceMetrics, $bag->sourceMetrics);
            $metadata = array_replace_recursive($metadata, $bag->metadata);
        }

        return new self($references, $sourceMetrics, $metadata);
    }

    public function withReference(EvidenceReference $reference): self
    {
        return self::merge($this, new self([$reference]));
    }

    /**
     * @return array<int, EvidenceReference>
     */
    public function referencesFor(string $type): array
    {
        $type = str($type)->lower()->trim()->slug('_')->toString();

        return collect($this->references)
            ->filter(fn (EvidenceReference $reference): bool => $reference->type === $type)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function referenceKeys(string $type): array
    {
        return collect($this->referencesFor($type))
            ->map(fn (EvidenceReference $reference): string => $reference->key)
            ->unique()
            ->values()
            ->all();
    }

    public function isEmpty(): bool
    {
        return $this->references === [] && $this->sourceMetrics === [];
    }

    public function toEvidence(): Evidence
    {
        $references = [];

        foreach ($this->references as $reference) {
            if ($reference->type === EvidenceReference::TYPE_PAGE_INTELLIGENCE_INPUT) {
                $inputType = (string) ($reference->metadata['input_type'] ?? 'resource');
                $references['page_intelligence_input_ids'][$inputType][] = $reference->key;

                continue;
            }

            $legacyKey = $reference->legacyKey();

            if ($legacyKey !== null) {
                $references[$legacyKey][] = $reference->key;

                continue;
            }

            $references['resource_references'][$reference->type][] = $reference->key;
        }

        return new Evidence(self::uniqueNested($references), $this->sourceMetrics);
    }

    public function toSignalEvidence(): IntelligenceSignalEvidence
    {
        return new IntelligenceSignalEvidence(
            $this->toEvidence(),
            array_map(
                fn (EvidenceReference $reference): IntelligenceGraphReference => $reference->toGraphReference(),
                $this->references,
            ),
            $this->metadata,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'references' => array_map(
                fn (EvidenceReference $reference): array => $reference->toArray(),
                $this->references,
            ),
            'legacy_evidence' => $this->toEvidence()->toArray(),
            'source_metrics' => $this->sourceMetrics,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param  iterable<int, EvidenceReference>  $references
     * @return array<int, EvidenceReference>
     */
    private static function mergeReferences(iterable $references): array
    {
        $merged = [];

        foreach ($references as $reference) {
            if (! $reference instanceof EvidenceReference) {
                continue;
            }

            $identity = $reference->identityKey();
            $merged[$identity] = isset($merged[$identity])
                ? $merged[$identity]->merge($reference)
                : $reference;
        }

        return array_values($merged);
    }

    /**
     * @param  array<string, mixed>  $references
     * @return array<string, mixed>
     */
    private static function uniqueNested(array $references): array
    {
        foreach ($references as $key => $value) {
            if (is_array($value) && collect(array_keys($value))->contains(fn (mixed $nestedKey): bool => is_string($nestedKey))) {
                $references[$key] = collect($value)
                    ->map(fn (mixed $ids): array => Evidence::unique((array) $ids))
                    ->all();

                continue;
            }

            $references[$key] = Evidence::unique((array) $value);
        }

        return $references;
    }
}
