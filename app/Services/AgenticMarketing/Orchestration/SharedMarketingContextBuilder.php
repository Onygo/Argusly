<?php

namespace App\Services\AgenticMarketing\Orchestration;

use App\Models\AgenticMarketingAgentMemory;
use App\Models\AgenticMarketingObjective;
use App\Models\CampaignCluster;
use App\Models\CompanyIntelligenceProfile;
use App\Models\CompetitorContentOpportunity;
use App\Models\Content;
use App\Models\ContentOpportunity;
use App\Models\Workspace;

class SharedMarketingContextBuilder
{
    public function build(Workspace $workspace, ?string $clientSiteId = null, ?AgenticMarketingObjective $objective = null, array $input = []): array
    {
        $company = CompanyIntelligenceProfile::query()
            ->where('workspace_id', $workspace->id)
            ->latest()
            ->first();

        $opportunities = ContentOpportunity::query()
            ->where('workspace_id', $workspace->id)
            ->when($clientSiteId, fn ($query) => $query->where('client_site_id', $clientSiteId))
            ->orderByDesc('priority_score')
            ->limit(20)
            ->get();

        $competitorGaps = CompetitorContentOpportunity::query()
            ->where('workspace_id', $workspace->id)
            ->when($clientSiteId, fn ($query) => $query->where('client_site_id', $clientSiteId))
            ->orderByDesc('priority_score')
            ->limit(12)
            ->get();

        $clusters = CampaignCluster::query()
            ->where('workspace_id', $workspace->id)
            ->when($clientSiteId, fn ($query) => $query->where('client_site_id', $clientSiteId))
            ->orderByDesc('completeness_score')
            ->limit(10)
            ->get();

        $content = Content::query()
            ->where('workspace_id', $workspace->id)
            ->when($clientSiteId, fn ($query) => $query->where('client_site_id', $clientSiteId))
            ->latest()
            ->limit(25)
            ->get(['id', 'title', 'type', 'status', 'lifecycle_stage', 'ai_visibility_score', 'aeo_score', 'content_health_score']);

        $memories = AgenticMarketingAgentMemory::query()
            ->where('workspace_id', $workspace->id)
            ->when($clientSiteId, fn ($query) => $query->where(function ($query) use ($clientSiteId): void {
                $query->whereNull('client_site_id')->orWhere('client_site_id', $clientSiteId);
            }))
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('last_used_at')
            ->limit(40)
            ->get();

        $focusTopic = $input['focus_topic'] ?? $objective?->name ?? data_get($company?->primary_topics, 0);

        return [
            'schema' => 'agentic_marketing.shared_context.v1',
            'workspace' => [
                'id' => (string) $workspace->id,
                'organization_id' => $workspace->organization_id,
                'name' => $workspace->display_name ?? $workspace->name,
                'locales' => (array) ($workspace->enabled_content_languages ?? []),
            ],
            'site' => ['id' => $clientSiteId],
            'objective' => $objective ? [
                'id' => (string) $objective->id,
                'name' => $objective->name,
                'goal' => $objective->goal,
                'locale' => $objective->locale,
            ] : null,
            'focus' => [
                'topic' => $focusTopic,
                'input' => $input,
            ],
            'company' => $company ? [
                'company_name' => $company->company_name,
                'market_category' => $company->market_category,
                'positioning' => $company->positioning,
                'uvp' => $company->uvp,
                'primary_topics' => (array) $company->primary_topics,
                'authority_areas' => (array) $company->authority_areas,
                'target_entities' => (array) $company->target_entities,
                'buyer_roles' => (array) $company->buyer_roles,
                'locales' => (array) $company->locales,
            ] : null,
            'opportunities' => $opportunities->map(fn (ContentOpportunity $opportunity): array => [
                'id' => (string) $opportunity->id,
                'type' => $opportunity->type,
                'title' => $opportunity->title,
                'topic' => data_get($opportunity->normalized_payload, 'candidate.topic', $opportunity->title),
                'funnel_stage' => $opportunity->funnel_stage,
                'intent' => $opportunity->primary_search_intent,
                'priority_score' => $opportunity->priority_score,
                'related_entities' => (array) $opportunity->related_entities,
            ])->all(),
            'competitor_gaps' => $competitorGaps->map(fn (CompetitorContentOpportunity $gap): array => [
                'id' => (string) $gap->id,
                'type' => $gap->type,
                'title' => $gap->title,
                'topic' => $gap->topic,
                'priority_score' => $gap->priority_score,
                'attackable_angle' => $gap->attackable_angle,
            ])->all(),
            'campaign_clusters' => $clusters->map(fn (CampaignCluster $cluster): array => [
                'id' => (string) $cluster->id,
                'name' => $cluster->name,
                'primary_topic' => $cluster->primary_topic,
                'completeness_score' => $cluster->completeness_score,
                'authority_score' => $cluster->authority_score,
            ])->all(),
            'existing_content' => $content->map(fn (Content $content): array => [
                'id' => (string) $content->id,
                'title' => $content->title,
                'status' => $content->status,
                'lifecycle_stage' => $content->lifecycle_stage,
                'ai_visibility_score' => $content->ai_visibility_score,
                'aeo_score' => $content->aeo_score,
                'content_health_score' => $content->content_health_score,
            ])->all(),
            'memories' => $memories->map(fn (AgenticMarketingAgentMemory $memory): array => [
                'agent_key' => $memory->agent_key,
                'memory_type' => $memory->memory_type,
                'memory_key' => $memory->memory_key,
                'confidence_score' => $memory->confidence_score,
                'payload' => $memory->payload,
            ])->all(),
            'ai_ready' => true,
            'mcp_ready' => true,
            'built_at' => now()->toIso8601String(),
        ];
    }
}
