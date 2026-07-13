<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Jobs\PageIntelligence\FetchMonitoredPageJob;
use App\Models\AlertRule;
use App\Models\Campaign;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\MarketPackInstallation;
use App\Models\MarketPackTheme;
use App\Models\MonitoredPage;
use App\Models\MonitoredSource;
use App\Models\PageAlert;
use App\Models\PageGeoObservation;
use App\Models\PageMarketPackMatch;
use App\Models\PagePrValue;
use App\Models\PageScore;
use App\Models\PageSentiment;
use App\Models\PageSerpObservation;
use App\Models\PageTopic;
use App\Models\SerpQuery;
use App\Models\SerpQuerySet;
use App\Models\SiteCompetitor;
use App\Models\Workspace;
use App\Services\PageIntelligence\PageIntelligenceScoreCalculator;
use App\Services\WebsiteContentInventory\WebsiteContentActivationService;
use App\Services\WebsiteContentInventory\WebsitePageEligibilityService;
use App\Support\Interaction\Action;
use App\Support\Interaction\AppInteractionRegistry;
use App\Support\Interaction\DrawerMetadataBuilder;
use App\Support\Interaction\DrawerState;
use App\Support\Interaction\DrawerTarget;
use App\Support\Interaction\MonitoredPageDataTable;
use App\Support\Interaction\MonitoredPageMetadataProvider;
use App\Support\Interaction\Providers\AppPageIntelligenceInteractionProvider;
use App\Support\Interaction\ResourceContext;
use App\Support\Interaction\ResourceType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AppMonitoredPageController extends Controller
{
    public function index(
        Request $request,
        MonitoredPageDataTable $dataTable,
        MonitoredPageMetadataProvider $metadataProvider,
        WebsitePageEligibilityService $eligibility,
    ): View {
        $workspace = $this->resolveWorkspace($request);
        $filters = $this->filters($request);
        $basePagesQuery = $dataTable->queryForWorkspace($workspace->id);
        $pagesQuery = $filters['tab'] === 'content-inventory'
            ? $this->applyInventoryFilters($basePagesQuery, $filters)
            : $this->applyPageFilters($basePagesQuery, $filters);
        $pages = $pagesQuery->paginate(15)->withQueryString();
        $pageIds = $pages->getCollection()->pluck('id')->all();
        $inventoryEligibility = $filters['tab'] === 'content-inventory'
            ? $pages->getCollection()
                ->mapWithKeys(fn (MonitoredPage $page): array => [$page->id => $eligibility->evaluate($page->loadMissing(['latestSnapshot', 'latestContentExtraction']))])
                ->all()
            : [];

        [$interactionResourcesByKey, $interactionActionsByKey] = $this->resolvePageInteractionMetadata($pages->getCollection(), $workspace);
        $drawerPage = $this->drawerPage($request, $workspace);
        $selectedDrawer = $drawerPage ? $metadataProvider->forPage($drawerPage) : null;

        return view('app.page-intelligence.index', [
            'workspace' => $workspace,
            'workspaces' => $this->availableWorkspaces($request),
            'filters' => $filters,
            'activeTab' => $filters['tab'],
            'metrics' => $this->metrics($workspace),
            'pages' => $pages,
            'pageRows' => $dataTable->rows($pages->getCollection()),
            'inventoryEligibility' => $inventoryEligibility,
            'pageInsights' => $this->pageInsights($pageIds),
            'interactionResourcesByKey' => $interactionResourcesByKey,
            'interactionActionsByKey' => $interactionActionsByKey,
            'drawerDescriptorsByKey' => $this->drawerDescriptors($pages->getCollection(), $interactionResourcesByKey, $interactionActionsByKey, $request),
            'drawerPage' => $drawerPage,
            'selectedDrawer' => $selectedDrawer,
            'linkableContents' => $filters['tab'] === 'content-inventory' ? $this->linkableContents($workspace) : collect(),
            'sources' => $this->sources($workspace, $filters),
            'alerts' => $this->alerts($workspace, $filters),
            'prValues' => $this->prValues($workspace),
            'intelligenceScores' => $this->intelligenceScores($workspace),
            'topOpportunities' => $this->topOpportunities($workspace),
            'riskPages' => $this->riskPages($workspace),
            'competitorPressure' => $this->competitorPressure($workspace),
            'serpQuerySets' => $this->serpQuerySets($workspace),
            'selectedSerpQuerySet' => $this->selectedSerpQuerySet($workspace, $filters),
            'serpQueryHistory' => $this->serpQueryHistory($workspace, $filters),
            'serpObservations' => $this->serpObservations($workspace),
            'geoObservations' => $this->geoObservations($workspace),
            'marketPacks' => $this->marketPacks($workspace, $filters),
            'packCompetitors' => $this->packCompetitors($workspace, $filters),
            'packThemes' => $this->packThemes($workspace, $filters),
            'latestSignals' => $this->latestSignals($workspace, $filters),
            'filterOptions' => $this->filterOptions($workspace),
        ]);
    }

    public function show(Request $request, MonitoredPage $monitoredPage, MonitoredPageMetadataProvider $metadataProvider): View
    {
        abort_unless($request->user()?->can('view', $monitoredPage), 403);

        $resourceKey = ResourceType::MONITORED_PAGE.':'.$monitoredPage->getKey();
        $registry = AppInteractionRegistry::resourceRegistryFor([$monitoredPage]);
        $resource = $registry->resolve($resourceKey, ResourceContext::make([
            'user' => $request->user(),
            'resource_type' => ResourceType::MONITORED_PAGE,
            'resource_id' => $monitoredPage->getKey(),
            'workspace_id' => $monitoredPage->workspace_id,
            'organization_id' => $monitoredPage->organization_id,
            'subject' => $monitoredPage,
            'metadata' => ['subject' => $monitoredPage],
        ]));

        abort_if($resource === null, 403);

        return view('app.page-intelligence.monitored-pages.show', [
            'monitoredPage' => $monitoredPage,
            'resource' => $resource,
            'drawer' => $metadataProvider->forPage($monitoredPage),
        ]);
    }

    public function refresh(Request $request, MonitoredPage $monitoredPage): RedirectResponse
    {
        abort_unless($request->user()?->can('update', $monitoredPage), 403);

        FetchMonitoredPageJob::dispatch((string) $monitoredPage->id, $monitoredPage->first_seen_url, true);

        return back()->with('status', 'Page refresh queued.');
    }

    public function exclude(Request $request, MonitoredPage $monitoredPage): RedirectResponse
    {
        abort_unless($request->user()?->can('update', $monitoredPage), 403);

        $metadata = (array) ($monitoredPage->metadata_json ?? []);
        data_set($metadata, 'inventory.review_override', 'excluded');
        data_set($metadata, 'inventory.reviewed_by_user_id', $request->user()?->id);
        data_set($metadata, 'inventory.reviewed_at', now()->toISOString());

        $monitoredPage->forceFill(['metadata_json' => $metadata])->save();

        return back()->with('status', 'Page excluded from Content Inventory activation.');
    }

    public function include(Request $request, MonitoredPage $monitoredPage): RedirectResponse
    {
        abort_unless($request->user()?->can('update', $monitoredPage), 403);

        $metadata = (array) ($monitoredPage->metadata_json ?? []);
        data_set($metadata, 'inventory.review_override', 'included');
        data_set($metadata, 'inventory.reviewed_by_user_id', $request->user()?->id);
        data_set($metadata, 'inventory.reviewed_at', now()->toISOString());

        $monitoredPage->forceFill(['metadata_json' => $metadata])->save();

        return back()->with('status', 'Page included for Content Inventory review.');
    }

    public function activate(
        Request $request,
        MonitoredPage $monitoredPage,
        WebsiteContentActivationService $activation,
    ): RedirectResponse {
        abort_unless($request->user()?->can('update', $monitoredPage), 403);

        $result = $activation->promote($monitoredPage);

        return redirect()
            ->route('app.content.show', $result->content)
            ->with('status', $result->contentCreated ? 'Content asset activated.' : 'Existing content asset refreshed from observed evidence.');
    }

    public function linkContent(
        Request $request,
        MonitoredPage $monitoredPage,
        WebsiteContentActivationService $activation,
    ): RedirectResponse {
        abort_unless($request->user()?->can('update', $monitoredPage), 403);

        $validated = $request->validate([
            'content_id' => ['required', 'string'],
        ]);

        $content = Content::query()
            ->where('workspace_id', $monitoredPage->workspace_id)
            ->whereKey((string) $validated['content_id'])
            ->firstOrFail();

        abort_unless($request->user()?->can('update', $content), 403);

        $activation->linkExistingContent($monitoredPage, $content);

        return back()->with('status', 'Observed page linked to existing content.');
    }

    private function applyPageFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['source_type'] !== '', fn (Builder $query): Builder => $query->where('source_type', $filters['source_type']))
            ->when($filters['domain'] !== '', fn (Builder $query): Builder => $query->where('domain', $filters['domain']))
            ->when($filters['market_pack'] !== '', fn (Builder $query): Builder => $query->where(function (Builder $query) use ($filters): void {
                $query->whereHas('marketPackMatches', fn (Builder $match): Builder => $match->where('market_pack_key', $filters['market_pack']))
                    ->orWhereHas('source', fn (Builder $source): Builder => $this->whereMetadataValue($source, 'market_pack_key', $filters['market_pack']));
            }))
            ->when($filters['sentiment'] !== '', fn (Builder $query): Builder => $query->whereHas('sentiments', fn (Builder $sentiment): Builder => $sentiment->where('label', $filters['sentiment'])))
            ->when($filters['pr_value'] !== '', fn (Builder $query): Builder => $query->whereHas('prValues', fn (Builder $value): Builder => $value->where('score', '>=', (float) $filters['pr_value'])))
            ->when($filters['serp_score'] !== '', fn (Builder $query): Builder => $query->whereHas('serpObservations', fn (Builder $score): Builder => $score->where('visibility_score', '>=', (float) $filters['serp_score'])))
            ->when($filters['geo_score'] !== '', fn (Builder $query): Builder => $query->whereHas('geoObservations', fn (Builder $score): Builder => $score->where('geo_visibility_score', '>=', (float) $filters['geo_score'])))
            ->when($filters['competitor'] !== '', fn (Builder $query): Builder => $query->whereHas('competitorMatches', fn (Builder $match): Builder => $match->where('site_competitor_id', $filters['competitor'])))
            ->when($filters['campaign'] !== '', fn (Builder $query): Builder => $query->whereHas('campaignMatches', fn (Builder $match): Builder => $match->where('campaign_id', $filters['campaign'])))
            ->when($filters['date_from'] !== '', fn (Builder $query): Builder => $query->whereDate(DB::raw('COALESCE(last_seen_at, first_seen_at, created_at)'), '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn (Builder $query): Builder => $query->whereDate(DB::raw('COALESCE(last_seen_at, first_seen_at, created_at)'), '<=', $filters['date_to']));
    }

    private function applyInventoryFilters(Builder $query, array $filters): Builder
    {
        $query->with(['latestContentExtraction', 'contentPageLinks.content']);

        return $query
            ->when($filters['site'] !== '', fn (Builder $query): Builder => $query->where('client_site_id', $filters['site']))
            ->when($filters['inventory_source'] !== '', fn (Builder $query): Builder => $query->where('source_type', $filters['inventory_source']))
            ->when($filters['page_type'] !== '', fn (Builder $query): Builder => $query->where('page_type', $filters['page_type']))
            ->when($filters['linked'] === 'linked', fn (Builder $query): Builder => $query->whereHas('contentPageLinks'))
            ->when($filters['linked'] === 'unlinked', fn (Builder $query): Builder => $query->whereDoesntHave('contentPageLinks'))
            ->when($filters['changed'] === 'changed', fn (Builder $query): Builder => $query->whereNotNull('last_changed_at'))
            ->when($filters['changed'] === 'unchanged', fn (Builder $query): Builder => $query->whereNull('last_changed_at'))
            ->when($filters['fetch_status'] === 'never_fetched', fn (Builder $query): Builder => $query->whereNull('last_fetched_at'))
            ->when($filters['fetch_status'] === 'fetched', fn (Builder $query): Builder => $query->where('crawl_status', MonitoredPage::CRAWL_STATUS_FETCHED))
            ->when($filters['fetch_status'] === 'failed', fn (Builder $query): Builder => $query->where('crawl_status', MonitoredPage::CRAWL_STATUS_FAILED))
            ->when($filters['extraction_status'] === 'extracted', fn (Builder $query): Builder => $query->whereHas('contentExtractions'))
            ->when($filters['extraction_status'] === 'missing', fn (Builder $query): Builder => $query->whereDoesntHave('contentExtractions'))
            ->when($filters['eligibility'] === 'excluded', fn (Builder $query): Builder => $this->applyExcludedInventoryFilter($query))
            ->when($filters['eligibility'] === 'ineligible', fn (Builder $query): Builder => $query->where(function (Builder $query): void {
                $query->where('crawl_status', MonitoredPage::CRAWL_STATUS_FAILED)
                    ->orWhereIn('indexability_status', (array) config('website_content_inventory.eligibility.ineligible_indexability_statuses', []));
            }))
            ->when($filters['eligibility'] === 'eligible', fn (Builder $query): Builder => $query->where(function (Builder $query): void {
                $query->whereNull('indexability_status')
                    ->orWhereNotIn('indexability_status', (array) config('website_content_inventory.eligibility.ineligible_indexability_statuses', []));
            }))
            ->when($filters['search'] !== '', fn (Builder $query): Builder => $query->where(function (Builder $query) use ($filters): void {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['search']).'%';
                $query->where('canonical_url', 'like', $like)
                    ->orWhere('first_seen_url', 'like', $like)
                    ->orWhere('final_url', 'like', $like)
                    ->orWhere('title_current', 'like', $like);
            }));
    }

    private function applyExcludedInventoryFilter(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('metadata_json', 'like', '%"review_override":"excluded"%')
                ->orWhere('metadata_json', 'like', '%"review_override": "excluded"%');

            foreach ((array) config('website_content_inventory.excluded_paths', []) as $path) {
                $path = '/'.ltrim((string) $path, '/');
                $query->orWhere('path', $path)
                    ->orWhere('path', 'like', rtrim($path, '/').'/%');
            }
        });
    }

    private function pageInsights(array $pageIds): array
    {
        if ($pageIds === []) {
            return [];
        }

        return [
            'sentiments' => PageSentiment::query()
                ->whereIn('monitored_page_id', $pageIds)
                ->where('target_type', PageSentiment::TARGET_PAGE)
                ->latest('analyzed_at')
                ->get()
                ->unique('monitored_page_id')
                ->keyBy('monitored_page_id'),
            'prValues' => PagePrValue::query()
                ->whereIn('monitored_page_id', $pageIds)
                ->latest('calculated_at')
                ->get()
                ->unique('monitored_page_id')
                ->keyBy('monitored_page_id'),
            'serp' => PageSerpObservation::query()
                ->whereIn('monitored_page_id', $pageIds)
                ->latest('observed_at')
                ->get()
                ->unique('monitored_page_id')
                ->keyBy('monitored_page_id'),
            'geo' => PageGeoObservation::query()
                ->whereIn('monitored_page_id', $pageIds)
                ->latest('observed_at')
                ->get()
                ->unique('monitored_page_id')
                ->keyBy('monitored_page_id'),
            'intelligenceScores' => PageScore::query()
                ->whereIn('monitored_page_id', $pageIds)
                ->where('score_type', PageIntelligenceScoreCalculator::SCORE_TYPE)
                ->latest('computed_at')
                ->get()
                ->unique('monitored_page_id')
                ->keyBy('monitored_page_id'),
            'alerts' => PageAlert::query()
                ->whereIn('monitored_page_id', $pageIds)
                ->open()
                ->selectRaw('monitored_page_id, count(*) as open_alerts')
                ->groupBy('monitored_page_id')
                ->pluck('open_alerts', 'monitored_page_id'),
        ];
    }

    private function sources(Workspace $workspace, array $filters): Collection
    {
        return MonitoredSource::query()
            ->where('workspace_id', $workspace->id)
            ->when($filters['market_pack'] !== '', fn (Builder $query): Builder => $this->whereMetadataValue($query, 'market_pack_key', $filters['market_pack']))
            ->withCount('pages')
            ->latest('last_discovered_at')
            ->limit(50)
            ->get();
    }

    private function alerts(Workspace $workspace, array $filters): Collection
    {
        return PageAlert::query()
            ->where('workspace_id', $workspace->id)
            ->when($filters['market_pack'] !== '', fn (Builder $query): Builder => $query->whereHas('rule', fn (Builder $rule): Builder => $this->whereMetadataValue($rule, 'market_pack_key', $filters['market_pack'])))
            ->with(['page:id,title_current,canonical_url,domain', 'rule:id,name', 'recommendedAction:id,title,status'])
            ->latest('fired_at')
            ->limit(50)
            ->get();
    }

    private function marketPacks(Workspace $workspace, array $filters): Collection
    {
        $installations = MarketPackInstallation::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', MarketPackInstallation::STATUS_ACTIVE)
            ->with([
                'marketPack:id,key,name,description,market_category,version,defaults_json',
                'marketPack.sources:id,market_pack_id,key,name,source_type,domain,authority_score',
                'marketPack.competitors:id,market_pack_id,key,name,domain',
                'marketPack.themes:id,market_pack_id,key,name,weight',
                'marketPack.alertTemplates:id,market_pack_id,key,name,severity',
            ])
            ->when($filters['market_pack'] !== '', fn (Builder $query): Builder => $query->whereHas('marketPack', fn (Builder $pack): Builder => $pack->where('key', $filters['market_pack'])))
            ->latest('installed_at')
            ->get();
        $packKeys = $installations->pluck('marketPack.key')->filter()->map(fn ($key): string => (string) $key)->values();
        $sourceCounts = MonitoredSource::query()
            ->where('workspace_id', $workspace->id)
            ->get(['id', 'metadata_json'])
            ->groupBy(fn (MonitoredSource $source): string => (string) data_get($source->metadata_json, 'market_pack_key'))
            ->map->count();
        $matchedPageCounts = PageMarketPackMatch::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('market_pack_key', $packKeys)
            ->selectRaw('market_pack_key, count(distinct monitored_page_id) as matched_pages_count')
            ->groupBy('market_pack_key')
            ->pluck('matched_pages_count', 'market_pack_key');
        $alertRuleCounts = AlertRule::query()
            ->where('workspace_id', $workspace->id)
            ->get(['id', 'metadata_json'])
            ->groupBy(fn (AlertRule $rule): string => (string) data_get($rule->metadata_json, 'market_pack_key'))
            ->map->count();

        return $installations
            ->map(function (MarketPackInstallation $installation) use ($sourceCounts, $matchedPageCounts, $alertRuleCounts): array {
                $pack = $installation->marketPack;
                $packKey = (string) $pack?->key;

                return [
                    'installation' => $installation,
                    'pack' => $pack,
                    'sources_count' => (int) ($sourceCounts[$packKey] ?? 0),
                    'matched_pages_count' => (int) ($matchedPageCounts[$packKey] ?? 0),
                    'alert_rules_count' => (int) ($alertRuleCounts[$packKey] ?? 0),
                ];
            });
    }

    private function packCompetitors(Workspace $workspace, array $filters): Collection
    {
        return MarketPackInstallation::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', MarketPackInstallation::STATUS_ACTIVE)
            ->with(['marketPack:id,key,name', 'marketPack.competitors:id,market_pack_id,key,name,domain,aliases_json'])
            ->when($filters['market_pack'] !== '', fn (Builder $query): Builder => $query->whereHas('marketPack', fn (Builder $pack): Builder => $pack->where('key', $filters['market_pack'])))
            ->get()
            ->flatMap(function (MarketPackInstallation $installation): array {
                $pack = $installation->marketPack;

                return $pack?->competitors->map(fn ($competitor): array => [
                    'pack' => $pack,
                    'competitor' => $competitor,
                    'aliases' => collect((array) $competitor->aliases_json)->filter()->implode(', '),
                ])->all() ?? [];
            })
            ->values();
    }

    private function packThemes(Workspace $workspace, array $filters): Collection
    {
        $installations = MarketPackInstallation::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', MarketPackInstallation::STATUS_ACTIVE)
            ->with(['marketPack:id,key,name', 'marketPack.themes.keywords:id,market_pack_theme_id,keyword,intent,weight'])
            ->when($filters['market_pack'] !== '', fn (Builder $query): Builder => $query->whereHas('marketPack', fn (Builder $pack): Builder => $pack->where('key', $filters['market_pack'])))
            ->get();
        $themeIds = $installations
            ->flatMap(fn (MarketPackInstallation $installation): Collection => $installation->marketPack?->themes ?? collect())
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values();
        $classifiedPageCounts = PageTopic::query()
            ->where('workspace_id', $workspace->id)
            ->where('source_ref_type', MarketPackTheme::class)
            ->whereIn('source_ref_id', $themeIds)
            ->selectRaw('source_ref_id, count(distinct monitored_page_id) as classified_pages_count')
            ->groupBy('source_ref_id')
            ->pluck('classified_pages_count', 'source_ref_id');

        return $installations
            ->flatMap(function (MarketPackInstallation $installation) use ($classifiedPageCounts): array {
                $pack = $installation->marketPack;

                return $pack?->themes->map(fn ($theme): array => [
                    'pack' => $pack,
                    'theme' => $theme,
                    'keywords' => $theme->keywords,
                    'classified_pages_count' => (int) ($classifiedPageCounts[(string) $theme->id] ?? 0),
                ])->all() ?? [];
            })
            ->values();
    }

    private function latestSignals(Workspace $workspace, array $filters): Collection
    {
        $marketPackMatches = PageMarketPackMatch::query()
            ->where('workspace_id', $workspace->id)
            ->when($filters['market_pack'] !== '', fn (Builder $query): Builder => $query->where('market_pack_key', $filters['market_pack']))
            ->with('page:id,title_current,canonical_url,domain')
            ->latest('observed_at')
            ->limit(8)
            ->get()
            ->toBase()
            ->map(fn (PageMarketPackMatch $match): array => [
                'type' => 'Market pack match',
                'title' => $match->page?->title_current ?: $match->page?->domain ?: 'Monitored page',
                'detail' => $match->market_pack_name.' · '.str($match->match_type)->headline(),
                'time' => $match->observed_at,
            ]);

        $alerts = PageAlert::query()
            ->where('workspace_id', $workspace->id)
            ->when($filters['market_pack'] !== '', fn (Builder $query): Builder => $query->whereHas('rule', fn (Builder $rule): Builder => $this->whereMetadataValue($rule, 'market_pack_key', $filters['market_pack'])))
            ->with('page:id,title_current,canonical_url,domain')
            ->latest('fired_at')
            ->limit(8)
            ->get()
            ->toBase()
            ->map(fn (PageAlert $alert): array => [
                'type' => 'Alert',
                'title' => $alert->title,
                'detail' => $alert->page?->title_current ?: $alert->page?->domain ?: str($alert->trigger)->headline(),
                'time' => $alert->fired_at,
            ]);

        return $marketPackMatches
            ->merge($alerts)
            ->sortByDesc('time')
            ->take(8)
            ->values();
    }

    private function whereMetadataValue(Builder $query, string $key, string $value): Builder
    {
        return $query->where(function (Builder $query) use ($key, $value): void {
            $query->where('metadata_json->'.$key, $value)
                ->orWhere('metadata_json', 'like', '%"'.$key.'":"'.$value.'"%')
                ->orWhere('metadata_json', 'like', '%"'.$key.'": "'.$value.'"%');
        });
    }

    private function prValues(Workspace $workspace): Collection
    {
        return PagePrValue::query()
            ->where('workspace_id', $workspace->id)
            ->with('page:id,title_current,canonical_url,domain')
            ->latest('calculated_at')
            ->limit(50)
            ->get();
    }

    private function intelligenceScores(Workspace $workspace): Collection
    {
        return PageScore::query()
            ->where('workspace_id', $workspace->id)
            ->where('score_type', PageIntelligenceScoreCalculator::SCORE_TYPE)
            ->with('page:id,title_current,canonical_url,domain')
            ->latest('computed_at')
            ->limit(50)
            ->get();
    }

    private function topOpportunities(Workspace $workspace): Collection
    {
        return PageScore::query()
            ->where('workspace_id', $workspace->id)
            ->where('score_type', PageIntelligenceScoreCalculator::SCORE_TYPE)
            ->with('page:id,title_current,canonical_url,domain')
            ->orderByDesc('score')
            ->limit(8)
            ->get();
    }

    private function riskPages(Workspace $workspace): Collection
    {
        return PageSentiment::query()
            ->where('workspace_id', $workspace->id)
            ->where('target_type', PageSentiment::TARGET_PAGE)
            ->where(function (Builder $query): void {
                $query->where('label', 'negative')->orWhere('compound_score', '<', -0.1);
            })
            ->with('page.source')
            ->latest('analyzed_at')
            ->limit(50)
            ->get()
            ->map(function (PageSentiment $sentiment): array {
                $source = $sentiment->page?->source;
                $authority = max((float) ($source?->authority_score ?? 0), (int) ($source?->trust_level ?? 0) * 10);

                return [
                    'sentiment' => $sentiment,
                    'page' => $sentiment->page,
                    'source_authority' => $authority,
                    'risk_score' => round($authority * abs((float) $sentiment->compound_score), 2),
                ];
            })
            ->filter(fn (array $row): bool => $row['source_authority'] >= 60)
            ->sortByDesc('risk_score')
            ->take(8)
            ->values();
    }

    private function competitorPressure(Workspace $workspace): Collection
    {
        return PageScore::query()
            ->where('workspace_id', $workspace->id)
            ->where('score_type', PageIntelligenceScoreCalculator::SCORE_TYPE)
            ->with('page:id,title_current,canonical_url,domain')
            ->latest('computed_at')
            ->limit(50)
            ->get()
            ->map(fn (PageScore $score): array => [
                'score' => $score,
                'page' => $score->page,
                'competitor_pressure' => (float) data_get($score->breakdown_json, 'components.competitor_pressure.score', 0),
                'competitor_mentions' => (int) data_get($score->breakdown_json, 'components.competitor_pressure.competitor_mentions', 0),
            ])
            ->filter(fn (array $row): bool => $row['competitor_pressure'] > 0)
            ->sortByDesc('competitor_pressure')
            ->take(8)
            ->values();
    }

    private function serpObservations(Workspace $workspace): Collection
    {
        return PageSerpObservation::query()
            ->where('workspace_id', $workspace->id)
            ->with(['page:id,title_current,canonical_url,domain', 'querySet:id,name'])
            ->latest('observed_at')
            ->limit(50)
            ->get();
    }

    private function serpQuerySets(Workspace $workspace): Collection
    {
        $latestObservations = PageSerpObservation::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('serp_query_set_id')
            ->selectRaw('serp_query_set_id, max(observed_at) as last_observed_at, avg(visibility_score) as avg_visibility_score')
            ->groupBy('serp_query_set_id')
            ->get()
            ->keyBy('serp_query_set_id');

        return SerpQuerySet::query()
            ->where('workspace_id', $workspace->id)
            ->withCount(['queries', 'observations'])
            ->latest('updated_at')
            ->get()
            ->map(function (SerpQuerySet $querySet) use ($latestObservations): array {
                $latest = $latestObservations[$querySet->id] ?? null;

                return [
                    'query_set' => $querySet,
                    'queries_count' => $querySet->queries_count,
                    'observations_count' => $querySet->observations_count,
                    'last_observed_at' => $latest?->last_observed_at,
                    'avg_visibility_score' => $latest ? round((float) $latest->avg_visibility_score, 1) : null,
                ];
            });
    }

    private function selectedSerpQuerySet(Workspace $workspace, array $filters): ?SerpQuerySet
    {
        if ($filters['serp_query_set'] === '') {
            return null;
        }

        return SerpQuerySet::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($filters['serp_query_set'])
            ->with('queries')
            ->first();
    }

    private function serpQueryHistory(Workspace $workspace, array $filters): Collection
    {
        if ($filters['serp_query_set'] === '') {
            return collect();
        }

        $queries = SerpQuery::query()
            ->where('workspace_id', $workspace->id)
            ->where('serp_query_set_id', $filters['serp_query_set'])
            ->orderBy('priority')
            ->orderBy('query')
            ->get();

        $observationsByQuery = PageSerpObservation::query()
            ->whereIn('serp_query_id', $queries->pluck('id'))
            ->with('page:id,title_current,canonical_url,domain')
            ->latest('observed_at')
            ->get()
            ->groupBy('serp_query_id')
            ->map(fn (Collection $observations): Collection => $observations->take(12)->values());

        return $queries
            ->map(function (SerpQuery $query) use ($observationsByQuery): array {
                $observations = $observationsByQuery->get($query->id, collect());

                return [
                    'query' => $query,
                    'observations' => $observations,
                    'latest' => $observations->first(),
                    'best_position' => $observations->min('absolute_position'),
                ];
            });
    }

    private function geoObservations(Workspace $workspace): Collection
    {
        return PageGeoObservation::query()
            ->where('workspace_id', $workspace->id)
            ->with('page:id,title_current,canonical_url,domain')
            ->latest('observed_at')
            ->limit(50)
            ->get();
    }

    private function filterOptions(Workspace $workspace): array
    {
        return [
            'sourceTypes' => MonitoredPage::query()->where('workspace_id', $workspace->id)->distinct()->orderBy('source_type')->pluck('source_type')->filter()->values(),
            'pageTypes' => MonitoredPage::query()->where('workspace_id', $workspace->id)->distinct()->orderBy('page_type')->pluck('page_type')->filter()->values(),
            'domains' => MonitoredPage::query()->where('workspace_id', $workspace->id)->distinct()->orderBy('domain')->pluck('domain')->filter()->values(),
            'sites' => ClientSite::query()->where('workspace_id', $workspace->id)->orderBy('name')->get(['id', 'name', 'base_url', 'site_url']),
            'marketPacks' => MarketPackInstallation::query()
                ->where('workspace_id', $workspace->id)
                ->with('marketPack:id,key,name')
                ->get()
                ->pluck('marketPack')
                ->filter()
                ->unique('key')
                ->values(),
            'sentiments' => PageSentiment::query()->where('workspace_id', $workspace->id)->distinct()->orderBy('label')->pluck('label')->filter()->values(),
            'competitors' => SiteCompetitor::query()->where('workspace_id', $workspace->id)->orderBy('name')->get(['id', 'name']),
            'campaigns' => Campaign::query()->where('workspace_id', $workspace->id)->orderBy('name')->get(['id', 'name']),
        ];
    }

    private function linkableContents(Workspace $workspace): Collection
    {
        return Content::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('updated_at')
            ->limit(75)
            ->get(['id', 'title', 'published_url', 'normalized_url', 'canonical_url', 'client_site_id', 'updated_at']);
    }

    private function metrics(Workspace $workspace): array
    {
        return [
            'pages' => MonitoredPage::query()->where('workspace_id', $workspace->id)->count(),
            'sources' => MonitoredSource::query()->where('workspace_id', $workspace->id)->count(),
            'openAlerts' => PageAlert::query()->where('workspace_id', $workspace->id)->open()->count(),
            'avgPrValue' => round((float) PagePrValue::query()->where('workspace_id', $workspace->id)->avg('score'), 1),
            'avgIntelligenceScore' => round((float) PageScore::query()->where('workspace_id', $workspace->id)->where('score_type', PageIntelligenceScoreCalculator::SCORE_TYPE)->avg('score'), 1),
            'avgSerp' => round((float) PageSerpObservation::query()->where('workspace_id', $workspace->id)->avg('visibility_score'), 1),
            'avgGeo' => round((float) PageGeoObservation::query()->where('workspace_id', $workspace->id)->avg('geo_visibility_score'), 1),
        ];
    }

    /**
     * @param  iterable<int, MonitoredPage>  $pages
     * @return array{0: array<string, array>, 1: array<string, array<string, array>>}
     */
    private function resolvePageInteractionMetadata(iterable $pages, Workspace $workspace): array
    {
        $user = request()->user();
        $resourceRegistry = AppInteractionRegistry::resourceRegistryFor($pages);
        $actionRegistry = AppInteractionRegistry::actionRegistry();
        $resourcesByKey = [];
        $actionsByKey = [];

        foreach ($pages as $page) {
            $resourceKey = ResourceType::MONITORED_PAGE.':'.$page->getKey();
            $context = ResourceContext::make([
                'user' => $user,
                'surface' => Action::SURFACE_ROW,
                'page_key' => 'app.page-intelligence.index',
                'route_name' => 'app.page-intelligence.index',
                'workspace_id' => $workspace->getKey(),
                'organization_id' => $user?->organization_id,
                'resource_type' => ResourceType::MONITORED_PAGE,
                'resource_id' => $page->getKey(),
                'subject' => $page,
                'metadata' => ['subject' => $page, 'workspace' => $workspace],
            ]);

            $resource = $resourceRegistry->resolve($resourceKey, $context);
            if ($resource === null) {
                continue;
            }

            $resourcesByKey[$resourceKey] = $resource;
            $actionsByKey[$resourceKey] = [];

            foreach ($resource['available_actions'] as $actionKey) {
                if (! $actionRegistry->has($actionKey)) {
                    continue;
                }

                $action = $actionRegistry->resolve($actionKey, $context->toActionContext());
                if ($action['visible']) {
                    $actionsByKey[$resourceKey][$actionKey] = $action;
                }
            }
        }

        return [$resourcesByKey, $actionsByKey];
    }

    private function drawerDescriptors(Collection $pages, array $resourcesByKey, array $actionsByKey, Request $request): array
    {
        return $pages
            ->mapWithKeys(function (MonitoredPage $page) use ($resourcesByKey, $actionsByKey, $request): array {
                $resourceKey = ResourceType::MONITORED_PAGE.':'.$page->getKey();
                $resource = $resourcesByKey[$resourceKey] ?? null;
                $action = $actionsByKey[$resourceKey][AppPageIntelligenceInteractionProvider::ACTION_MONITORED_PAGE_OPEN] ?? null;

                if (! is_array($resource) || ! is_array($action)) {
                    return [];
                }

                return [$resourceKey => $this->drawerDescriptor($page, $resource, $action, $request)];
            })
            ->all();
    }

    private function drawerDescriptor(MonitoredPage $page, array $resource, array $action, Request $request): array
    {
        $resourceKey = ResourceType::MONITORED_PAGE.':'.$page->getKey();

        return DrawerMetadataBuilder::make()->build(
            DrawerTarget::make('monitored-page.inspect', DrawerState::MODE_INSPECT, 'xl')
                ->forResource(ResourceType::MONITORED_PAGE, $page->getKey(), $resourceKey)
                ->forAction(AppPageIntelligenceInteractionProvider::ACTION_MONITORED_PAGE_OPEN)
                ->withHref(route('app.page-intelligence.monitored-pages.show', $page)),
            [
                'resource' => $resource,
                'action' => $action,
                'title' => 'Inspect',
                'subtitle' => $page->domain,
                'tabs' => [],
                'sections' => [],
                'footer_actions' => [],
                'metadata' => [
                    'dashboard_url' => route('app.page-intelligence.index', array_merge($request->query(), ['drawer' => $page->getKey()])),
                    'renders_production_content' => true,
                ],
            ],
        )->toArray();
    }

    private function drawerPage(Request $request, Workspace $workspace): ?MonitoredPage
    {
        $drawerId = trim((string) $request->query('drawer', ''));
        if ($drawerId === '') {
            return null;
        }

        return MonitoredPage::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($drawerId)
            ->firstOrFail();
    }

    private function filters(Request $request): array
    {
        return [
            'tab' => in_array($request->query('tab'), ['market-packs', 'content-inventory', 'pages', 'competitors', 'themes', 'sources', 'alerts', 'pr-value', 'intelligence', 'serp', 'geo'], true) ? (string) $request->query('tab') : 'pages',
            'site' => trim((string) $request->query('site', '')),
            'inventory_source' => trim((string) $request->query('inventory_source', '')),
            'page_type' => trim((string) $request->query('page_type', '')),
            'eligibility' => in_array($request->query('eligibility'), ['', 'eligible', 'ineligible', 'excluded'], true) ? (string) $request->query('eligibility', '') : '',
            'linked' => in_array($request->query('linked'), ['', 'linked', 'unlinked'], true) ? (string) $request->query('linked', '') : '',
            'changed' => in_array($request->query('changed'), ['', 'changed', 'unchanged'], true) ? (string) $request->query('changed', '') : '',
            'fetch_status' => in_array($request->query('fetch_status'), ['', 'never_fetched', 'fetched', 'failed'], true) ? (string) $request->query('fetch_status', '') : '',
            'extraction_status' => in_array($request->query('extraction_status'), ['', 'extracted', 'missing'], true) ? (string) $request->query('extraction_status', '') : '',
            'search' => trim((string) $request->query('search', '')),
            'source_type' => trim((string) $request->query('source_type', '')),
            'domain' => trim((string) $request->query('domain', '')),
            'market_pack' => trim((string) $request->query('market_pack', '')),
            'sentiment' => trim((string) $request->query('sentiment', '')),
            'pr_value' => trim((string) $request->query('pr_value', '')),
            'serp_score' => trim((string) $request->query('serp_score', '')),
            'geo_score' => trim((string) $request->query('geo_score', '')),
            'competitor' => trim((string) $request->query('competitor', '')),
            'campaign' => trim((string) $request->query('campaign', '')),
            'date_from' => trim((string) $request->query('date_from', '')),
            'date_to' => trim((string) $request->query('date_to', '')),
            'serp_query_set' => trim((string) $request->query('serp_query_set', '')),
        ];
    }

    private function resolveWorkspace(Request $request, mixed $preferredWorkspaceId = null): Workspace
    {
        $organizationId = $request->user()?->organization_id;
        $query = Workspace::query()->where('organization_id', $organizationId)->orderBy('created_at');
        $workspaceId = $preferredWorkspaceId ?: $request->query('workspace');

        if ($workspaceId) {
            $workspace = (clone $query)->whereKey($workspaceId)->first();
            if (! $workspace) {
                throw new AuthorizationException('Workspace is not available for this user.');
            }

            return $workspace;
        }

        return $query->firstOrFail();
    }

    private function availableWorkspaces(Request $request): Collection
    {
        return Workspace::query()
            ->where('organization_id', $request->user()?->organization_id)
            ->orderBy('name')
            ->get(['id', 'name', 'display_name']);
    }
}
