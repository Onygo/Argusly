<?php

namespace App\Services\PageIntelligence\Discovery;

use App\Models\MonitoredSource;

final class MonitoredSourceDiscoveryResult
{
    public function __construct(
        public readonly MonitoredSource $source,
        public readonly int $discovered,
        public readonly int $created,
        public readonly int $updated,
        public readonly int $fetchJobsQueued,
        public readonly bool $successful,
        public readonly bool $skipped = false,
        public readonly ?string $message = null,
        public readonly int $failedUrls = 0,
    ) {
    }
}
