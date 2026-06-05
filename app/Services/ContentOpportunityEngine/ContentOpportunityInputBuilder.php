<?php

namespace App\Services\ContentOpportunityEngine;

use App\Models\CompetitorContentOpportunity;
use App\Models\CompetitorTopicSignal;
use App\Models\Content;
use App\Models\ContentCluster;
use App\Models\ClientSite;
use App\Models\QueryIntentClassification;
use App\Models\Workspace;
use App\Services\CompanyIntelligence\CompanyIntelligenceContextService;

class ContentOpportunityInputBuilder
{
    public function __construct(private readonly CompanyIntelligenceContextService $companyContext) {}

    /**
     * @return array<string,mixed>
     */
    public function build(Workspace $workspace, ?string $clientSiteId = null): array
    {
        $site = $clientSiteId
            ? ClientSite::query()->where('workspace_id', $workspace->id)->find($clientSiteId)
            : null;

        return [
            'workspace_context' => [
                'name' => $workspace->display_name,
                'site_name' => $site?->name,
                'site_url' => $site?->site_url ?: $site?->base_url,
                'default_locale' => (string) ($workspace->default_content_language?->value ?? $workspace->default_content_language ?? 'en'),
                'enabled_locales' => (array) ($workspace->enabled_content_languages ?? []),
            ],
            'company' => $this->companyContext->promptContext($workspace),
            'competitor_opportunities' => CompetitorContentOpportunity::query()
                ->where('workspace_id', $workspace->id)
                ->when($clientSiteId, fn ($query) => $query->where('client_site_id', $clientSiteId))
                ->where('status', 'open')
                ->orderByDesc('priority_score')
                ->limit(30)
                ->get()
                ->toArray(),
            'competitor_topics' => CompetitorTopicSignal::query()
                ->where('workspace_id', $workspace->id)
                ->when($clientSiteId, fn ($query) => $query->where('client_site_id', $clientSiteId))
                ->orderByDesc('opportunity_score')
                ->limit(40)
                ->get()
                ->toArray(),
            'content_inventory' => Content::query()
                ->where('workspace_id', $workspace->id)
                ->when($clientSiteId, fn ($query) => $query->where('client_site_id', $clientSiteId))
                ->orderByDesc('updated_at')
                ->limit(120)
                ->get(['id', 'title', 'primary_keyword', 'published_url', 'content_health_score', 'aeo_score', 'ai_visibility_score', 'intent_keys', 'language', 'updated_at'])
                ->toArray(),
            'content_clusters' => ContentCluster::query()
                ->where('workspace_id', $workspace->id)
                ->orderByDesc('cluster_score')
                ->limit(40)
                ->get()
                ->toArray(),
            'query_intelligence' => QueryIntentClassification::query()
                ->where('workspace_id', $workspace->id)
                ->orderByDesc('priority_score')
                ->limit(50)
                ->get()
                ->toArray(),
        ];
    }
}
