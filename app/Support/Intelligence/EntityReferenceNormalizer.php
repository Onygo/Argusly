<?php

namespace App\Support\Intelligence;

use Illuminate\Support\Str;

class EntityReferenceNormalizer
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<int, mixed>  $aliases
     */
    public function normalize(
        CanonicalEntityType|string $type,
        CanonicalEntityReference|array|string $value,
        array $metadata = [],
        array $aliases = [],
    ): ?CanonicalEntityReference {
        $typeValue = $this->normalizeType($value instanceof CanonicalEntityReference ? $value->type : $type);
        $payload = is_array($value) ? $value : [];
        $name = $value instanceof CanonicalEntityReference
            ? $value->name
            : (is_array($value) ? $this->nameFromPayload($value) : $value);
        $name = $this->normalizeName((string) $name);

        if ($name === '') {
            return null;
        }

        $key = $this->normalizeKey(
            type: $typeValue,
            name: $name,
            key: $value instanceof CanonicalEntityReference
                ? $value->key
                : ($payload['key'] ?? $payload['entity_key'] ?? $payload['topic_key'] ?? $payload['brand_key'] ?? null)
        );
        $metadata = array_replace(
            $value instanceof CanonicalEntityReference ? $value->metadata : (array) ($payload['metadata'] ?? []),
            $metadata
        );
        $aliases = $this->normalizeAliases([
            ...($value instanceof CanonicalEntityReference ? $value->aliases : (array) ($payload['aliases'] ?? [])),
            ...$aliases,
        ], $name);

        return new CanonicalEntityReference(
            type: $typeValue,
            name: $name,
            key: $key,
            aliases: $aliases,
            metadata: $metadata,
        );
    }

    /**
     * @param  iterable<mixed>  $values
     * @param  array<string, mixed>  $metadata
     * @return array<int, CanonicalEntityReference>
     */
    public function normalizeMany(CanonicalEntityType|string $type, iterable $values, array $metadata = []): array
    {
        return $this->uniqueReferences(collect($values)
            ->flatMap(fn (mixed $value): array => $this->referenceValues($value))
            ->map(fn (mixed $value): ?CanonicalEntityReference => $this->normalize($type, $value, $metadata))
            ->filter()
            ->values()
            ->all());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, CanonicalEntityType|string>  $fieldMap
     * @param  array<string, mixed>  $metadata
     * @return array<int, CanonicalEntityReference>
     */
    public function fromPayload(array $payload, array $fieldMap, array $metadata = []): array
    {
        $references = [];

        foreach ($fieldMap as $field => $type) {
            foreach ($this->referenceValues(data_get($payload, $field)) as $value) {
                $reference = $this->normalize($type, $value, array_replace($metadata, [
                    'source_field' => $field,
                ]));

                if ($reference !== null) {
                    $references[] = $reference;
                }
            }
        }

        return $this->uniqueReferences($references);
    }

    public function normalizeType(CanonicalEntityType|string $type): string
    {
        if ($type instanceof CanonicalEntityType) {
            return $type->value;
        }

        $normalized = Str::slug(Str::lower(trim($type)), '_');

        return $normalized !== '' ? $normalized : CanonicalEntityType::ORGANIZATION->value;
    }

    public function normalizeName(string $name): string
    {
        return Str::squish(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function normalizeKey(CanonicalEntityType|string $type, string $name, mixed $key = null): string
    {
        $typeValue = $this->normalizeType($type);
        $explicit = $this->normalizeName((string) ($key ?? ''));

        if ($explicit !== '') {
            return $typeValue === CanonicalEntityType::DOMAIN->value
                ? $this->domainKey($explicit)
                : CanonicalEntityReference::keyForName($explicit);
        }

        if ($typeValue === CanonicalEntityType::DOMAIN->value) {
            return $this->domainKey($name);
        }

        return CanonicalEntityReference::keyForName($name);
    }

    /**
     * @param  array<int, mixed>  $aliases
     * @return array<int, string>
     */
    public function normalizeAliases(array $aliases, string $name = ''): array
    {
        $nameKey = CanonicalEntityReference::keyForName($name);
        $seen = [];

        return collect($aliases)
            ->flatMap(fn (mixed $alias): array => is_array($alias) ? $alias : [$alias])
            ->map(fn (mixed $alias): string => $this->normalizeName((string) $alias))
            ->filter(function (string $alias) use ($nameKey, &$seen): bool {
                if ($alias === '') {
                    return false;
                }

                $key = CanonicalEntityReference::keyForName($alias);

                if ($key === $nameKey || isset($seen[$key])) {
                    return false;
                }

                $seen[$key] = true;

                return true;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, CanonicalEntityReference>  $references
     * @return array<int, CanonicalEntityReference>
     */
    public function uniqueReferences(array $references): array
    {
        return collect($references)
            ->filter(fn (mixed $reference): bool => $reference instanceof CanonicalEntityReference)
            ->unique(fn (CanonicalEntityReference $reference): string => $reference->type.'|'.$reference->key)
            ->values()
            ->all();
    }

    /**
     * @return array<int, mixed>
     */
    private function referenceValues(mixed $value): array
    {
        if ($value instanceof CanonicalEntityReference || is_scalar($value)) {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        if ($this->looksLikeReferencePayload($value)) {
            return [$value];
        }

        return collect($value)
            ->flatMap(fn (mixed $nested): array => $this->referenceValues($nested))
            ->values()
            ->all();
    }

    /**
     * @param  array<string|int, mixed>  $payload
     */
    private function looksLikeReferencePayload(array $payload): bool
    {
        foreach (['name', 'entity_name', 'topic_name', 'brand_name', 'company_name', 'domain', 'url', 'key'] as $key) {
            if (array_key_exists($key, $payload)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string|int, mixed>  $payload
     */
    private function nameFromPayload(array $payload): string
    {
        foreach (['name', 'entity_name', 'topic_name', 'brand_name', 'company_name', 'domain', 'url', 'key'] as $key) {
            if (array_key_exists($key, $payload) && is_scalar($payload[$key])) {
                return (string) $payload[$key];
            }
        }

        return '';
    }

    private function domainKey(string $value): string
    {
        $value = Str::lower(trim($value));
        $host = parse_url(str_contains($value, '://') ? $value : 'https://'.$value, PHP_URL_HOST);
        $host = is_string($host) ? $host : $value;
        $host = preg_replace('/^www\./', '', trim($host, " \t\n\r\0\x0B./")) ?: $host;

        return $host !== '' ? $host : CanonicalEntityReference::keyForName($value);
    }
}
