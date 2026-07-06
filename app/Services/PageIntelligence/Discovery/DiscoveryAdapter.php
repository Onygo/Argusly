<?php

namespace App\Services\PageIntelligence\Discovery;

use App\Models\MonitoredSource;

interface DiscoveryAdapter
{
    /**
     * @return iterable<int, DiscoveredUrl>
     */
    public function discover(MonitoredSource $source): iterable;
}
