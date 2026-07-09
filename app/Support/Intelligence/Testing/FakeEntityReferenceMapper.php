<?php

namespace App\Support\Intelligence\Testing;

use App\Support\Intelligence\CanonicalEntityReference;
use App\Support\Intelligence\CanonicalEntityType;
use App\Support\Intelligence\EntityReferenceMapper;
use App\Support\Intelligence\EntityReferenceNormalizer;

class FakeEntityReferenceMapper implements EntityReferenceMapper
{
    /**
     * @var array<string, CanonicalEntityReference>
     */
    private array $mappings = [];

    public function __construct(private readonly EntityReferenceNormalizer $normalizer = new EntityReferenceNormalizer())
    {
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function map(CanonicalEntityReference $reference, array $context = []): CanonicalEntityReference
    {
        foreach ($this->lookupKeys($reference) as $key) {
            if (isset($this->mappings[$key])) {
                return $this->mappings[$key];
            }
        }

        return $reference;
    }

    public function mapReference(CanonicalEntityReference $source, CanonicalEntityReference $target): self
    {
        foreach ($this->lookupKeys($source) as $key) {
            $this->mappings[$key] = $target;
        }

        return $this;
    }

    public function mapName(
        CanonicalEntityType|string $sourceType,
        string $sourceName,
        CanonicalEntityReference|string $target,
        CanonicalEntityType|string|null $targetType = null,
    ): self {
        $source = $this->normalizer->normalize($sourceType, $sourceName);
        $targetReference = $target instanceof CanonicalEntityReference
            ? $target
            : $this->normalizer->normalize($targetType ?: $sourceType, $target);

        if ($source !== null && $targetReference !== null) {
            $this->mapReference($source, $targetReference);
        }

        return $this;
    }

    /**
     * @return array<int, string>
     */
    private function lookupKeys(CanonicalEntityReference $reference): array
    {
        $keys = [$reference->type.'|'.$reference->key];

        foreach ($reference->aliases as $alias) {
            $keys[] = $reference->type.'|'.CanonicalEntityReference::keyForName($alias);
        }

        return array_values(array_unique($keys));
    }
}
