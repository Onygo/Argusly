<?php

namespace App\Services\Onboarding\Providers;

use App\Models\BrandContext;
use App\Models\ClientSite;
use App\Models\CompanyIntelligenceProfile;
use App\Models\LlmTrackingQuery;
use App\Models\SignalEvent;
use App\Models\SignalSource;
use App\Models\SiteCompetitor;
use App\Models\Workspace;
use App\Services\Onboarding\ModuleReadinessResult;
use App\Services\Onboarding\ReadinessRequirement;

class SignalIntelligenceReadinessProvider extends BaseReadinessProvider
{
    public function key(): string
    {
        return 'signal_intelligence';
    }

    public function label(): string
    {
        return 'Signal Intelligence';
    }

    public function description(): string
    {
        return 'Collects signal events and turns them into reviewed detections.';
    }

    public function evaluate(Workspace $workspace): ModuleReadinessResult
    {
        if (! (bool) config('features.signal_intelligence', false)) {
            return $this->result([], [$this->action('Enable Signal Intelligence', 'Feature flag is disabled.', null, 'disabled')], 'Signal Intelligence is not enabled for this workspace.');
        }

        $brand = BrandContext::query()->where('workspace_id', $workspace->id)->exists();
        $site = ClientSite::query()->where('workspace_id', $workspace->id)->exists();
        $competitors = SiteCompetitor::query()->where('workspace_id', $workspace->id)->where('is_active', true)->count();
        $topics = $this->topicCount($workspace);
        $sources = $this->sourceCount($workspace);
        $events = SignalEvent::query()->where('workspace_id', $workspace->id)->count();

        $requirements = [
            new ReadinessRequirement('brand_profile', 'Add your brand profile', 'Define the brand context used to recognize relevant signals.', $brand, 'required', 'Open brand profile', $this->routeOrNull('app.brand.company-profile')),
            new ReadinessRequirement('website', 'Connect a website', 'Signal Intelligence needs a workspace site or client site.', $site, 'required', 'Add site', $this->routeOrNull('app.sites')),
            new ReadinessRequirement('competitors', 'Add your competitors', 'Competitors provide comparison context for monitoring.', $competitors >= 1, 'required', 'Manage competitors', $this->firstSiteRoute($workspace, 'app.sites.competitors.index')),
            new ReadinessRequirement('topics', 'Define monitoring topics', 'Add at least three topics, authority areas, or strategic keywords.', $topics >= 3, 'recommended', 'Edit company intelligence', $this->routeOrNull('app.brand.company-intelligence')),
            new ReadinessRequirement('signal_sources', 'Add signal sources', 'Signal sources come from AI visibility queries, competitor monitoring, or imported feeds.', $sources >= 1, 'required', 'Create AI visibility query', $this->firstSiteRoute($workspace, 'app.sites.llm-tracking.index')),
        ];

        $complete = collect($requirements)->where('completed', true)->count() >= count($requirements);
        $actions = $complete && $events === 0
            ? [
                $this->action('Open AI Visibility', 'Run a tracking query to create signal input.', $this->firstSiteRoute($workspace, 'app.sites.llm-tracking.index'), 'primary'),
                $this->action('Open competitors', 'Import or review competitor monitoring input.', $this->firstSiteRoute($workspace, 'app.sites.competitors.index'), 'secondary'),
            ]
            : [
                $this->action('Open Setup', 'Review all missing setup steps.', $this->routeOrNull('app.setup.index'), 'primary'),
                $this->action('Open Signal Intelligence', 'Review signal feed and detections.', $this->routeOrNull('app.signal-intelligence.index'), 'secondary'),
            ];

        return $this->result(
            $requirements,
            $actions,
            $events === 0 ? ($complete
                ? 'Signal Intelligence is configured, but no signal events exist yet. Run AI Visibility or import source data first.'
                : 'Signal Intelligence becomes active after setup produces its first signal events.') : null,
            $events > 0 && $complete,
        );
    }

    private function topicCount(Workspace $workspace): int
    {
        $profile = CompanyIntelligenceProfile::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', CompanyIntelligenceProfile::STATUS_ACTIVE)
            ->orderByDesc('is_default')
            ->latest()
            ->first();

        $context = BrandContext::query()->where('workspace_id', $workspace->id)->latest()->first();
        $contextPayload = (array) ($context?->structured_json ?? []);
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

    private function sourceCount(Workspace $workspace): int
    {
        $registeredSources = SignalSource::query()
            ->where('workspace_id', $workspace->id)
            ->count();

        $trackingQueries = LlmTrackingQuery::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->count();

        $activeCompetitors = SiteCompetitor::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->count();

        return $registeredSources + $trackingQueries + $activeCompetitors;
    }

    private function firstSiteRoute(Workspace $workspace, string $route): ?string
    {
        $site = ClientSite::query()->where('workspace_id', $workspace->id)->first();

        return $site ? $this->routeOrNull($route, $site) : null;
    }
}
