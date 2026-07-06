<?php

namespace App\Services\PageIntelligence\Discovery;

use App\Models\MonitoredSource;

class KnownSourceCrawlAdapter extends ManualUrlDiscoveryAdapter
{
    protected function configuredUrls(MonitoredSource $source): array
    {
        $config = (array) ($source->discovery_config_json ?? []);
        $urls = (array) ($config['urls'] ?? $config['known_urls'] ?? []);
        $paths = (array) ($config['paths'] ?? []);
        $baseUrl = rtrim((string) ($config['base_url'] ?? $source->base_url), '/');

        foreach ($paths as $path) {
            if ($baseUrl === '') {
                continue;
            }

            $urls[] = $baseUrl.'/'.ltrim((string) $path, '/');
        }

        return $urls;
    }
}
