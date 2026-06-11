<?php

namespace App\Services\Onboarding\Providers;

use App\Models\BrandContext;
use App\Models\ClientSite;
use App\Models\CompanyIntelligenceProfile;
use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQueryRun;
use App\Models\SiteCompetitor;
use App\Models\Workspace;
use App\Services\Onboarding\ModuleReadinessResult;
use App\Services\Onboarding\ReadinessRequirement;

class AiVisibilityReadinessProvider extends BaseReadinessProvider
{
    public function key(): string { return 'ai_visibility'; }

    public function label(): string { return 'AI Visibility'; }

    public function description(): string { return 'Tracks brand visibility in LLM answers and competitor mentions.'; }

    public function evaluate(Workspace $workspace): ModuleReadinessResult
    {
        $brand = BrandContext::query()->where('workspace_id', $workspace->id)->exists();
        $site = ClientSite::query()->where('workspace_id', $workspace->id)->first();
        $competitors = SiteCompetitor::query()->where('workspace_id', $workspace->id)->where('is_active', true)->count();
        $topics = $this->topicCount($workspace);
        $queries = LlmTrackingQuery::query()->where('workspace_id', $workspace->id)->count();
        $runs = LlmTrackingQueryRun::query()
            ->whereHas('trackingQuery', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->count();

        $requirements = [
            new ReadinessRequirement('brand_profile', 'Add your brand profile', 'AI visibility needs target brand terms.', $brand, 'required', 'Open brand profile', $this->routeOrNull('app.brand.company-profile')),
            new ReadinessRequirement('website', 'Connect a website', 'A site anchors target domain and URLs.', (bool) $site, 'required', 'Add site', $this->routeOrNull('app.sites')),
            new ReadinessRequirement('llm_queries', 'Create LLM tracking queries', 'Add monitoring prompts for AI visibility.', $queries >= 1, 'required', 'Open AI Visibility', $site ? $this->routeOrNull('app.sites.llm-tracking.index', $site) : null),
            new ReadinessRequirement('competitors', 'Add competitors', 'Competitors unlock share-of-voice comparisons.', $competitors >= 1, 'recommended', 'Manage competitors', $site ? $this->routeOrNull('app.sites.competitors.index', $site) : null),
            new ReadinessRequirement('topics', 'Define topics', 'Topics make prompts and comparisons more useful.', $topics >= 3, 'recommended', 'Edit company intelligence', $this->routeOrNull('app.brand.company-intelligence')),
        ];

        return $this->result($requirements, [
            $this->action('Open Setup', 'Review missing AI visibility setup.', $this->routeOrNull('app.setup.index'), 'primary'),
            $this->action('Open AI Visibility', 'Create or review tracking queries.', $site ? $this->routeOrNull('app.sites.llm-tracking.index', $site) : null),
        ], $runs === 0 ? 'AI Visibility becomes active after at least one tracking query has run.' : null, $runs > 0 && collect($requirements)->where('completed', true)->count() >= 5);
    }

    private function topicCount(Workspace $workspace): int
    {
        $profile = CompanyIntelligenceProfile::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', CompanyIntelligenceProfile::STATUS_ACTIVE)
            ->orderByDesc('is_default')
            ->latest()
            ->first();

        $contextPayload = (array) (BrandContext::query()->where('workspace_id', $workspace->id)->latest()->first()?->structured_json ?? []);
        $fields = ['primary_topics', 'authority_areas', 'target_entities', 'strategic_keywords', 'query_intents'];

        return collect($fields)
            ->flatMap(fn (string $key): array => array_merge(
                (array) ($profile?->{$key} ?? []),
                (array) data_get($contextPayload, $key, []),
            ))
            ->filter()
            ->unique()
            ->count();
    }
}
