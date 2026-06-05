<?php

namespace App\Services\PluginUpdates;

use App\Models\PluginRelease;
use Illuminate\Support\Collection;

class PluginReleaseService
{
    public function latestRelease(): ?PluginRelease
    {
        return $this->sortedReleases()->first();
    }

    public function latestCompatibleRelease(string $pluginVersion, string $wpVersion): ?PluginRelease
    {
        $sorted = $this->sortedReleases();
        if ($sorted->isEmpty()) {
            return null;
        }

        foreach ($sorted as $release) {
            if (! $this->isWpCompatible($release, $wpVersion)) {
                continue;
            }

            if (version_compare($release->version, $pluginVersion, '>')) {
                return $release;
            }
        }

        return null;
    }

    public function isWpCompatible(PluginRelease $release, string $wpVersion): bool
    {
        $wpVersion = trim($wpVersion);
        if ($wpVersion === '') {
            return true;
        }

        $minWpVersion = trim((string) $release->min_wp_version);
        if ($minWpVersion !== '' && version_compare($wpVersion, $minWpVersion, '<')) {
            return false;
        }

        return true;
    }

    /**
     * @return Collection<int, PluginRelease>
     */
    private function sortedReleases(): Collection
    {
        /** @var Collection<int, PluginRelease> $releases */
        $releases = PluginRelease::query()->get();

        return $releases->sort(function (PluginRelease $a, PluginRelease $b): int {
            return version_compare($b->version, $a->version);
        })->values();
    }
}
