<?php

namespace App\Services\CampaignClusterEngine;

use App\Models\CompanyIntelligenceProfile;
use App\Models\CompetitorContentOpportunity;
use App\Models\Content;
use App\Models\ContentOpportunity;
use App\Models\Workspace;

class CampaignClusterInputBuilder
{
    public function build(Workspace $workspace, ?string $clientSiteId = null): array
    {
        $company = CompanyIntelligenceProfile::query()
            ->where('workspace_id', $workspace->id)
            ->latest()
            ->first();

        $opportunities = ContentOpportunity::query()
            ->where('workspace_id', $workspace->id)
            ->when($clientSiteId, fn ($query) => $query->where('client_site_id', $clientSiteId))
            ->where('status', ContentOpportunity::STATUS_OPEN)
            ->orderByDesc('priority_score')
            ->limit(80)
            ->get();

        $competitorGaps = CompetitorContentOpportunity::query()
            ->where('workspace_id', $workspace->id)
            ->when($clientSiteId, fn ($query) => $query->where('client_site_id', $clientSiteId))
            ->orderByDesc('priority_score')
            ->limit(40)
            ->get();

        $existingContent = Content::query()
            ->where('workspace_id', $workspace->id)
            ->when($clientSiteId, fn ($query) => $query->where('client_site_id', $clientSiteId))
            ->latest()
            ->limit(120)
            ->get(['id', 'title', 'type', 'primary_keyword', 'seo_h1', 'lifecycle_stage', 'ai_visibility_score', 'aeo_score', 'content_health_score', 'first_published_at']);

        return [
            'company' => $company,
            'opportunities' => $opportunities,
            'competitor_gaps' => $competitorGaps,
            'existing_content' => $existingContent,
            'fallback_topics' => collect([
                $company?->market_category,
                $workspace->display_name,
                optional($workspace->clientSites->firstWhere('id', $clientSiteId))->name,
            ])->filter()->values()->all(),
        ];
    }
}
