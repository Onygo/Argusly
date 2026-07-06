<?php

namespace App\Services\PageIntelligence\Discovery;

use App\Models\MonitoredSource;

class ManualUrlDiscoveryAdapter implements DiscoveryAdapter
{
    public function discover(MonitoredSource $source): iterable
    {
        foreach ($this->configuredUrls($source) as $entry) {
            $discovered = DiscoveredUrl::fromArray($entry);

            if (trim($discovered->url) !== '') {
                yield $discovered;
            }
        }
    }

    protected function configuredUrls(MonitoredSource $source): array
    {
        $config = (array) ($source->discovery_config_json ?? []);

        return (array) ($config['urls'] ?? $config['manual_urls'] ?? []);
    }
}
