<?php

namespace App\Support;

use App\Models\FeatureFlag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class FeatureFlags
{
    private ?bool $hasFeatureFlagsTable = null;

    /**
     * @var array<string,bool|null>
     */
    private array $enabledByKey = [];

    public function isEnabled(string $key, ?bool $default = null): bool
    {
        $resolvedKey = trim($key);
        if ($resolvedKey === '') {
            return (bool) ($default ?? false);
        }

        if ($this->usesRequestCache() && array_key_exists($resolvedKey, $this->enabledByKey)) {
            $enabled = $this->enabledByKey[$resolvedKey];

            return $enabled ?? (bool) ($default ?? false);
        }

        $requestKey = 'feature_flags.enabled.'.$resolvedKey;
        if ($this->usesRequestCache() && request()->attributes->has($requestKey)) {
            $enabled = request()->attributes->get($requestKey);
            $this->rememberEnabled($resolvedKey, $enabled);

            return $enabled ?? (bool) ($default ?? false);
        }

        if ($this->hasFeatureFlagsTable()) {
            $flag = FeatureFlag::query()
                ->where('key', $resolvedKey)
                ->first(['enabled']);

            if ($flag) {
                $enabled = (bool) $flag->enabled;
                if ($this->usesRequestCache()) {
                    request()->attributes->set($requestKey, $enabled);
                }

                $this->rememberEnabled($resolvedKey, $enabled);

                return $enabled;
            }
        }

        $configured = config('features.' . $resolvedKey);
        if ($configured !== null) {
            $enabled = (bool) $configured;
            if ($this->usesRequestCache()) {
                request()->attributes->set($requestKey, $enabled);
            }

            $this->rememberEnabled($resolvedKey, $enabled);

            return $enabled;
        }

        $this->rememberEnabled($resolvedKey, null);
        if ($this->usesRequestCache()) {
            request()->attributes->set($requestKey, null);
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

        if (! $this->hasFeatureFlagsTable()) {
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

    private function hasFeatureFlagsTable(): bool
    {
        if ($this->usesRequestCache() && $this->hasFeatureFlagsTable !== null) {
            return $this->hasFeatureFlagsTable;
        }

        if ($this->usesRequestCache() && request()->attributes->has('feature_flags.has_table')) {
            return $this->hasFeatureFlagsTable = (bool) request()->attributes->get('feature_flags.has_table');
        }

        $exists = Schema::hasTable('feature_flags');
        if ($this->usesRequestCache()) {
            request()->attributes->set('feature_flags.has_table', $exists);
        }

        if ($this->usesRequestCache()) {
            $this->hasFeatureFlagsTable = $exists;
        }

        return $exists;
    }

    private function rememberEnabled(string $key, ?bool $enabled): void
    {
        if ($this->usesRequestCache()) {
            $this->enabledByKey[$key] = $enabled;
        }
    }

    private function usesRequestCache(): bool
    {
        return request()->route() !== null;
    }
}
