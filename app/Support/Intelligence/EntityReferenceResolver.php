<?php

namespace App\Support\Intelligence;

class EntityReferenceResolver
{
    public function __construct(
        private readonly EntityReferenceNormalizer $normalizer = new EntityReferenceNormalizer(),
        private readonly ?EntityReferenceMapper $mapper = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function resolve(
        CanonicalEntityType|string $type,
        CanonicalEntityReference|array|string $value,
        array $context = [],
    ): ?CanonicalEntityReference {
        $reference = $this->normalizer->normalize(
            type: $type,
            value: $value,
            metadata: (array) ($context['metadata'] ?? []),
            aliases: (array) ($context['aliases'] ?? []),
        );

        return $reference === null ? null : $this->map($reference, $context);
    }

    /**
     * @param  iterable<mixed>  $values
     * @param  array<string, mixed>  $context
     * @return array<int, CanonicalEntityReference>
     */
    public function resolveMany(CanonicalEntityType|string $type, iterable $values, array $context = []): array
    {
        $references = [];

        foreach ($this->normalizer->normalizeMany($type, $values, (array) ($context['metadata'] ?? [])) as $reference) {
            $references[] = $this->map($reference, $context);
        }

        return $this->normalizer->uniqueReferences($references);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, CanonicalEntityType|string>  $fieldMap
     * @param  array<string, mixed>  $context
     * @return array<int, CanonicalEntityReference>
     */
    public function resolvePayload(array $payload, array $fieldMap, array $context = []): array
    {
        $references = [];

        foreach ($this->normalizer->fromPayload($payload, $fieldMap, (array) ($context['metadata'] ?? [])) as $reference) {
            $references[] = $this->map($reference, $context);
        }

        return $this->normalizer->uniqueReferences($references);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function map(CanonicalEntityReference $reference, array $context): CanonicalEntityReference
    {
        return $this->mapper?->map($reference, $context) ?? $reference;
    }
}
