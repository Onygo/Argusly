<?php

namespace App\Services\PageIntelligence;

use App\Models\MonitoredPage;
use App\Models\PageSnapshot;

class PageFetchResult
{
    public function __construct(
        public readonly MonitoredPage $page,
        public readonly PageSnapshot $snapshot,
        public readonly bool $successful,
    ) {
    }

    public function state(): string
    {
        if (! $this->successful) {
            return 'failed';
        }

        return $this->snapshot->content_changed ? 'changed' : 'unchanged';
    }
}
