<?php

namespace App\Services\CompetitorIntelligence;

use App\Models\SiteCompetitor;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;

class CompetitorIntelligenceCache
{
    public function analysisKey(Workspace $workspace, ?SiteCompetitor $competitor = null): string
    {
        return 'competitor-intelligence:' . $workspace->id . ':' . ($competitor?->id ?: 'all');
    }

    public function forget(Workspace $workspace, ?SiteCompetitor $competitor = null): void
    {
        Cache::forget($this->analysisKey($workspace, $competitor));
    }
}
