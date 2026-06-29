<?php

namespace App\Services\Mos\Opportunity\Support;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;

trait MapsCanonicalOpportunityFields
{
    protected function stringValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function floatValue(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * @return array<int, mixed>
     */
    protected function listValue(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        return is_array($value) ? array_values($value) : [$value];
    }

    /**
     * @return array<string, mixed>
     */
    protected function context(Model $source): array
    {
        return array_filter([
            'organization_id' => $source->getAttribute('organization_id'),
            'workspace_id' => $source->getAttribute('workspace_id'),
            'client_site_id' => $source->getAttribute('client_site_id'),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $references
     * @return array<int, array<string, mixed>>
     */
    protected function references(array $references): array
    {
        return collect($references)
            ->filter(static fn (mixed $value): bool => $value !== null && $value !== [])
            ->map(static fn (mixed $value, string $key): array => [
                'type' => $key,
                'value' => $value,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $fieldMap
     * @return array<int, string>
     */
    protected function missingFromMap(array $fieldMap): array
    {
        return collect($fieldMap)
            ->filter(static fn (mixed $value): bool => $value === null || $value === '' || $value === [])
            ->keys()
            ->values()
            ->all();
    }
}
