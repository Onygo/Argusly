<?php

namespace App\Services\CompetitorIntelligence;

use App\Models\SiteCompetitor;
use App\Services\CompetitorIntelligence\Contracts\CompetitorContentSource;

class ManualCompetitorContentSource implements CompetitorContentSource
{
    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function items(SiteCompetitor $competitor, array $options = []): iterable
    {
        foreach ((array) ($options['items'] ?? []) as $item) {
            if (is_array($item)) {
                yield $item;
            }
        }
    }
}
