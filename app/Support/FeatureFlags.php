<?php

namespace App\Support;

use App\Models\FeatureFlag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class FeatureFlags
{
    public function isEnabled(string $key, ?bool $default = null): bool
    {
        $resolvedKey = trim($key);
        if ($resolvedKey === '') {
            return (bool) ($default ?? false);
        }

        if (Schema::hasTable('feature_flags')) {
            $flag = FeatureFlag::query()
                ->where('key', $resolvedKey)
                ->first(['enabled']);

            if ($flag) {
                return (bool) $flag->enabled;
            }
        }

        $configured = config('features.' . $resolvedKey);
        if ($configured !== null) {
            return (bool) $configured;
        }

        return (bool) ($default ?? false);
    }

    public function effectiveFlags(): Collection
    {
        $configFlags = collect((array) config('features', []))
            ->map(fn (mixed $enabled, string $key): array => [
                'key' => $key,
                'description' => null,
                'enabled' => (bool) $enabled,
                'source' => 'config',
            ])
            ->keyBy('key');

        if (! Schema::hasTable('feature_flags')) {
            return $configFlags->sortKeys()->values();
        }

        $dbFlags = FeatureFlag::query()
            ->orderBy('key')
            ->get(['id', 'key', 'description', 'enabled']);

        foreach ($dbFlags as $flag) {
            $configFlags->put((string) $flag->key, [
                'id' => (int) $flag->id,
                'key' => (string) $flag->key,
                'description' => $flag->description,
                'enabled' => (bool) $flag->enabled,
                'source' => 'database',
            ]);
        }

        return $configFlags->sortKeys()->values();
    }
}
