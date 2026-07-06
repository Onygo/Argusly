<?php

namespace App\Services\PageIntelligence;

use App\Models\MonitoredPage;
use App\Models\PageContentExtraction;
use App\Models\PageSnapshot;

class PageExtractionResult
{
    public function __construct(
        public readonly MonitoredPage $page,
        public readonly PageSnapshot $snapshot,
        public readonly PageContentExtraction $extraction,
        public readonly bool $created,
    ) {
    }

    public function state(): string
    {
        return $this->created ? 'created' : 'updated';
    }
}
