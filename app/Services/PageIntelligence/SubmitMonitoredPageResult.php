<?php

namespace App\Services\PageIntelligence;

use App\Models\MonitoredPage;

class SubmitMonitoredPageResult
{
    public function __construct(
        public readonly MonitoredPage $page,
        public readonly bool $created,
        public readonly PageUrlNormalizationResult $url,
    ) {
    }

    public function state(): string
    {
        return $this->created ? 'created' : 'updated';
    }
}
