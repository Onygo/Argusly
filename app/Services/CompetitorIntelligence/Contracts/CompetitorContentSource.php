<?php

namespace App\Services\CompetitorIntelligence\Contracts;

use App\Models\SiteCompetitor;

interface CompetitorContentSource
{
    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function items(SiteCompetitor $competitor, array $options = []): iterable;
}
