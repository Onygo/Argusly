<?php

namespace App\Services\DataConnectors;

use Illuminate\Support\Str;

class ConnectorDatasetCapability
{
    /**
     * @param array<int|string, mixed> $capabilities
     * @return array{keys: list<string>, definitions: array<string, array<string, mixed>>}
     */
    public static function normalizeMany(array $capabilities): array
    {
        $definitions = [];

        foreach ($capabilities as $key => $value) {
            if (is_int($key)) {
                if (is_string($value)) {
                    $capabilityKey = self::normalizeKey($value);
                    $definition = ['enabled' => true];
                } elseif (is_array($value)) {
                    $capabilityKey = self::normalizeKey((string) ($value['key'] ?? $value['name'] ?? ''));
                    $definition = $value;
                    unset($definition['key'], $definition['name']);
                } else {
                    continue;
                }
            } else {
                $capabilityKey = self::normalizeKey((string) $key);
                $definition = is_array($value) ? $value : ['enabled' => (bool) $value];
            }

            if ($capabilityKey === '') {
                continue;
            }

            $definitions[$capabilityKey] = array_merge(
                ['enabled' => true],
                $definition,
            );
        }

        $keys = array_values(array_filter(
            array_keys($definitions),
            fn (string $key): bool => (bool) ($definitions[$key]['enabled'] ?? true)
        ));

        sort($keys);
        ksort($definitions);

        return [
            'keys' => $keys,
            'definitions' => $definitions,
        ];
    }

    public static function normalizeKey(string $key): string
    {
        return Str::of($key)
            ->trim()
            ->lower()
            ->replaceMatches('/[^a-z0-9_.:-]+/', '_')
            ->trim('_')
            ->toString();
    }
}
