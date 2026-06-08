<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Jobs\LlmTracking\RescoreLlmTrackingQueryJob;
use App\Jobs\LlmTracking\RunLlmTrackingQueryJob;
use App\Models\ClientSite;
use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQueryRun;
use App\Models\LlmTrackingQuerySet;
use App\Services\Entitlements\FeatureGate;
use App\Services\LlmTracking\AiAttentionDashboardBuilder;
use App\Services\LlmTracking\ArguslyTrackingDefaults;
use App\View\Presenters\TrackingQueryDetailPresenter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AppLlmTrackingController extends Controller
{
    public function index(
        Request $request,
        ClientSite $site,
        FeatureGate $featureGate,
        ArguslyTrackingDefaults $defaults,
        AiAttentionDashboardBuilder $dashboardBuilder,
    ): View {
        $this->assertSiteInOrganization($request, $site);
        $this->assertFeature($featureGate, $site);
        $defaults->ensureForSite($site);

        $filters = $this->filters($request);
        $queries = $dashboardBuilder->loadQueriesForSite($site, $filters);
        $querySets = $this->querySetsForSite($site);
        $indexSummary = $dashboardBuilder->buildIndexSummary($queries, $filters);

        return view('app.sites.llm-tracking.index', [
            'site' => $site,
            'queries' => $queries,
            'querySets' => $querySets,
            'indexSummary' => $indexSummary,
            'siteTrend' => $dashboardBuilder->buildSiteTrend($site, 'week', 8, $filters),
            'queryPerformanceRows' => $dashboardBuilder->buildQueryPerformance($queries),
            'latestResponseRows' => $dashboardBuilder->buildLatestResponses($queries),
            'filters' => $filters,
            'providerOptions' => $this->providerOptions(),
            'modelOptions' => $queries->flatMap(fn (LlmTrackingQuery $query) => $query->runs->pluck('model'))
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'localeOptions' => $querySets->pluck('locale')
                ->merge($queries->pluck('locale'))
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all(),
        ]);
    }

    public function show(
        Request $request,
        ClientSite $site,
        LlmTrackingQuery $query,
        FeatureGate $featureGate,
    ): View {
        $this->assertSiteInOrganization($request, $site);
        $this->assertFeature($featureGate, $site);
        $this->assertQueryInSite($site, $query);

        $filters = $this->filters($request);
        $query->load([
            'querySet',
            'runs' => function ($builder) use ($filters): void {
                $builder->where('status', 'succeeded')
                    ->when(($filters['provider'] ?? '') !== '', fn ($query) => $query->where('provider', (string) $filters['provider']))
                    ->when(($filters['model'] ?? '') !== '', fn ($query) => $query->where('model', (string) $filters['model']))
                    ->latest('run_at')
                    ->limit(50);
            },
        ]);

        $weeklyAggregates = $query->aggregates()
            ->where('period', 'week')
            ->when(($filters['provider'] ?? '') !== '', fn ($builder) => $builder->where('provider', (string) $filters['provider']))
            ->when(($filters['model'] ?? '') !== '', fn ($builder) => $builder->where('model', (string) $filters['model']))
            ->when(($filters['locale'] ?? '') !== '', fn ($builder) => $builder->where('locale', (string) $filters['locale']))
            ->orderByDesc('period_start')
            ->limit(8)
            ->get();

        $detail = TrackingQueryDetailPresenter::make($query, $query->runs, $weeklyAggregates);
        $activeTab = $this->detailTab($request);

        return view('app.sites.llm-tracking.show', [
            'site' => $site,
            'query' => $query,
            'querySets' => $this->querySetsForSite($site),
            'runs' => $query->runs,
            'latestRun' => $query->runs->first(),
            'weeklyAggregates' => $weeklyAggregates,
            'filters' => $filters,
            'detail' => $detail,
            'activeTab' => $activeTab,
        ]);
    }

    public function store(Request $request, ClientSite $site, FeatureGate $featureGate): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->assertSiteInOrganization($request, $site);
        $this->assertFeature($featureGate, $site);

        $data = $this->validateQuery($request, $site);

        LlmTrackingQuery::query()->create($this->queryPayload($site, $data));

        return redirect()->route('app.sites.llm-tracking.index', $site)->with('status', 'Tracking query created.');
    }

    public function update(Request $request, ClientSite $site, LlmTrackingQuery $query, FeatureGate $featureGate): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->assertSiteInOrganization($request, $site);
        $this->assertFeature($featureGate, $site);
        $this->assertQueryInSite($site, $query);

        $data = $this->validateQuery($request, $site);

        $query->update($this->queryPayload($site, $data, false));

        return back()->with('status', 'Tracking query updated.');
    }

    public function toggle(Request $request, ClientSite $site, LlmTrackingQuery $query, FeatureGate $featureGate): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->assertSiteInOrganization($request, $site);
        $this->assertFeature($featureGate, $site);
        $this->assertQueryInSite($site, $query);

        $query->is_active = ! $query->is_active;
        $query->save();

        return back()->with('status', 'Tracking query status updated.');
    }

    public function runNow(Request $request, ClientSite $site, LlmTrackingQuery $query, FeatureGate $featureGate): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->assertSiteInOrganization($request, $site);
        $this->assertFeature($featureGate, $site);
        $this->assertQueryInSite($site, $query);

        RunLlmTrackingQueryJob::dispatch($query->id, now()->toDateString())->onQueue('default');

        return back()->with('status', 'Tracking run queued. Run now uses 1 credit; same-day identical runs are cached at 0 credits.');
    }

    public function rescore(Request $request, ClientSite $site, LlmTrackingQuery $query, FeatureGate $featureGate): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->assertSiteInOrganization($request, $site);
        $this->assertFeature($featureGate, $site);
        $this->assertQueryInSite($site, $query);

        RescoreLlmTrackingQueryJob::dispatch($query->id)->onQueue('default');

        return back()->with('status', 'Stored runs queued for AI visibility rescore.');
    }

    public function aggregates(Request $request, ClientSite $site, LlmTrackingQuery $query, FeatureGate $featureGate): JsonResponse
    {
        $this->assertSiteInOrganization($request, $site);
        $this->assertFeature($featureGate, $site);
        $this->assertQueryInSite($site, $query);

        $period = trim((string) $request->query('period', 'week'));
        if (! in_array($period, ['day', 'week', 'month'], true)) {
            $period = 'week';
        }

        $aggregates = $query->aggregates()
            ->where('period', $period)
            ->when(trim((string) $request->query('provider')) !== '', fn ($builder) => $builder->where('provider', trim((string) $request->query('provider'))))
            ->when(trim((string) $request->query('model')) !== '', fn ($builder) => $builder->where('model', trim((string) $request->query('model'))))
            ->when(trim((string) $request->query('locale')) !== '', fn ($builder) => $builder->where('locale', trim((string) $request->query('locale'))))
            ->orderByDesc('period_start')
            ->limit(120)
            ->get();

        return response()->json([
            'period' => $period,
            'data' => $aggregates,
        ]);
    }

    public function runDetails(
        Request $request,
        ClientSite $site,
        LlmTrackingQuery $query,
        LlmTrackingQueryRun $run,
        FeatureGate $featureGate
    ): JsonResponse {
        $this->assertSiteInOrganization($request, $site);
        $this->assertFeature($featureGate, $site);
        $this->assertQueryInSite($site, $query);

        if ((int) $run->llm_tracking_query_id !== (int) $query->id) {
            abort(404);
        }

        return response()->json([
            'data' => $run,
        ]);
    }

    private function assertSiteInOrganization(Request $request, ClientSite $site): void
    {
        if ((int) $site->workspace?->organization_id !== (int) $request->user()->organization_id) {
            abort(404);
        }
    }

    private function assertFeature(FeatureGate $featureGate, ClientSite $site): void
    {
        try {
            $featureGate->assert($site->workspace, 'link_intelligence');
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }
    }

    private function assertQueryInSite(ClientSite $site, LlmTrackingQuery $query): void
    {
        if ((string) $query->client_site_id !== (string) $site->id) {
            abort(404);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function filters(Request $request): array
    {
        $period = trim((string) $request->query('period', '30d'));
        if (! in_array($period, ['7d', '30d', '90d'], true)) {
            $period = '30d';
        }

        return [
            'query_set_id' => trim((string) $request->query('query_set_id', '')),
            'period' => $period,
            'provider' => trim((string) $request->query('provider', '')),
            'model' => trim((string) $request->query('model', '')),
            'locale' => trim((string) $request->query('locale', '')),
            'brand' => trim((string) $request->query('brand', '')),
            'competitor' => trim((string) $request->query('competitor', '')),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int,LlmTrackingQuerySet>
     */
    private function querySetsForSite(ClientSite $site)
    {
        return LlmTrackingQuerySet::query()
            ->where('workspace_id', $site->workspace_id)
            ->where(function ($query) use ($site): void {
                $query->whereNull('client_site_id')
                    ->orWhere('client_site_id', $site->id);
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string,mixed>
     */
    private function validateQuery(Request $request, ClientSite $site): array
    {
        return $request->validate([
            'llm_tracking_query_set_id' => [
                'nullable',
                Rule::exists('llm_tracking_query_sets', 'id')->where(function ($query) use ($site): void {
                    $query->where('workspace_id', $site->workspace_id)
                        ->where(function ($builder) use ($site): void {
                            $builder->whereNull('client_site_id')
                                ->orWhere('client_site_id', $site->id);
                        });
                }),
            ],
            'name' => ['required', 'string', 'max:120'],
            'query_text' => ['required', 'string', 'max:5000'],
            'query_variants' => ['nullable', 'string', 'max:10000'],
            'target_brand' => ['nullable', 'string', 'max:160'],
            'target_domain' => ['nullable', 'string', 'max:255'],
            'brand_terms' => ['nullable', 'string', 'max:5000'],
            'competitor_terms' => ['nullable', 'string', 'max:5000'],
            'target_urls' => ['nullable', 'string', 'max:5000'],
            'tags' => ['nullable', 'string', 'max:5000'],
            'locale' => ['nullable', 'string', 'max:16'],
            'frequency' => ['nullable', 'in:daily,weekly'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function queryPayload(ClientSite $site, array $data, bool $includeDefaults = true): array
    {
        $brandTerms = $this->parseListInput((string) ($data['brand_terms'] ?? ''));
        $targetUrls = $this->parseListInput((string) ($data['target_urls'] ?? ''));

        $targetBrand = trim((string) ($data['target_brand'] ?? ''));
        if ($targetBrand === '') {
            $targetBrand = (string) (collect($brandTerms)->first() ?? '');
        }

        $targetDomain = $this->normalizeDomainInput((string) ($data['target_domain'] ?? ''));
        if ($targetDomain === '') {
            $targetDomain = $this->inferDomainFromUrls($targetUrls);
        }

        return array_filter([
            'workspace_id' => $includeDefaults ? $site->workspace_id : null,
            'client_site_id' => $includeDefaults ? $site->id : null,
            'llm_tracking_query_set_id' => ($data['llm_tracking_query_set_id'] ?? null) ?: null,
            'name' => trim((string) $data['name']),
            'query_text' => trim((string) $data['query_text']),
            'query_variants' => $this->queryVariantsPayload((string) ($data['query_text'] ?? ''), (string) ($data['query_variants'] ?? '')),
            'target_brand' => $targetBrand !== '' ? $targetBrand : null,
            'target_domain' => $targetDomain !== '' ? $targetDomain : null,
            'brand_terms' => $brandTerms,
            'competitor_terms' => $this->parseListInput((string) ($data['competitor_terms'] ?? '')),
            'target_urls' => $targetUrls,
            'tags' => $this->parseListInput((string) ($data['tags'] ?? '')),
            'locale' => trim((string) ($data['locale'] ?? 'en')) ?: 'en',
            'frequency' => (string) ($data['frequency'] ?? 'daily'),
            'priority' => max(1, (int) ($data['priority'] ?? 50)),
            'is_active' => (bool) ($data['is_active'] ?? false),
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * @return array<int,string>
     */
    private function parseListInput(string $value): array
    {
        return collect(preg_split('/[\n,;]+/', $value) ?: [])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int,array{key:string,intent:string,query_text:string}>
     */
    private function queryVariantsPayload(string $baseQuery, string $value): array
    {
        $custom = $this->parseListInput($value);
        if ($custom !== []) {
            return collect($custom)
                ->map(fn (string $query, int $index): array => [
                    'key' => 'custom_' . ($index + 1),
                    'intent' => 'custom',
                    'query_text' => $query,
                ])
                ->values()
                ->all();
        }

        $base = trim($baseQuery);
        if ($base === '') {
            return [];
        }

        return [
            ['key' => 'exact', 'intent' => 'exact', 'query_text' => $base],
            ['key' => 'buyer_intent', 'intent' => 'buyer', 'query_text' => $base . ' and GEO'],
            ['key' => 'comparison_intent', 'intent' => 'comparison', 'query_text' => 'best alternatives and comparisons for ' . $base],
            ['key' => 'category_intent', 'intent' => 'category', 'query_text' => 'tools to improve AI search visibility'],
            ['key' => 'problem_intent', 'intent' => 'problem', 'query_text' => 'how to improve visibility in AI-generated answers'],
        ];
    }

    private function normalizeDomainInput(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $host = parse_url($value, PHP_URL_HOST);

        return strtolower(trim(is_string($host) ? $host : $value));
    }

    /**
     * @param array<int,string> $urls
     */
    private function inferDomainFromUrls(array $urls): string
    {
        foreach ($urls as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && trim($host) !== '') {
                return strtolower(trim($host));
            }
        }

        return '';
    }

    /**
     * @return array<int,string>
     */
    private function providerOptions(): array
    {
        return collect((array) config('llm_tracking.providers', []))
            ->filter(fn ($enabled): bool => (bool) $enabled)
            ->keys()
            ->values()
            ->all();
    }

    private function detailTab(Request $request): string
    {
        $tab = trim((string) $request->query('tab', 'overview'));

        return in_array($tab, ['overview', 'competitors', 'sources', 'findings', 'history', 'raw'], true)
            ? $tab
            : 'overview';
    }
}
