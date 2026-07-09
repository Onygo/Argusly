<?php

namespace App\Support\Intelligence;

class Evidence
{
    public const SOURCE_METRICS_KEY = 'source_metrics';

    /**
     * @param  array<string, mixed>  $references
     * @param  array<string, mixed>  $sourceMetrics
     */
    public function __construct(
        public readonly array $references = [],
        public readonly array $sourceMetrics = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $sourceMetrics = (array) ($payload[self::SOURCE_METRICS_KEY] ?? []);
        unset($payload[self::SOURCE_METRICS_KEY]);

        return new self($payload, $sourceMetrics);
    }

    public static function merge(self ...$items): self
    {
        if ($items === []) {
            return new self();
        }

        $references = [];
        $sourceMetrics = [];

        foreach ($items as $item) {
            foreach ($item->references as $key => $value) {
                $references[$key] = self::mergeReferenceValue($references[$key] ?? null, $value);
            }

            $sourceMetrics = array_replace_recursive($sourceMetrics, $item->sourceMetrics);
        }

        return new self($references, $sourceMetrics);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->references + [
            self::SOURCE_METRICS_KEY => $this->sourceMetrics,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function referenceIds(string $key): array
    {
        return self::unique((array) ($this->references[$key] ?? []));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function nestedReferenceIds(string $key): array
    {
        return collect((array) ($this->references[$key] ?? []))
            ->mapWithKeys(fn (mixed $ids, string $type): array => [$type => self::unique((array) $ids)])
            ->all();
    }

    private static function mergeReferenceValue(mixed $existing, mixed $incoming): mixed
    {
        $existingIsNested = is_array($existing) && self::isNestedReferenceMap($existing);
        $incomingIsNested = is_array($incoming) && self::isNestedReferenceMap($incoming);

        if ($existingIsNested || $incomingIsNested) {
            $merged = $existingIsNested ? $existing : [];

            if ($incomingIsNested) {
                foreach ($incoming as $key => $ids) {
                    $merged[$key] = self::unique(array_merge((array) ($merged[$key] ?? []), (array) $ids));
                }
            }

            return $merged;
        }

        return self::unique(array_merge((array) $existing, (array) $incoming));
    }

    /**
     * @param  array<mixed>  $value
     */
    private static function isNestedReferenceMap(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return collect(array_keys($value))->contains(fn (mixed $key): bool => is_string($key));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    public static function unique(array $values): array
    {
        return collect($values)
            ->filter(fn (mixed $value): bool => $value !== null && $value !== '')
            ->map(fn (mixed $value): string => (string) $value)
            ->unique()
            ->values()
            ->all();
    }
}
