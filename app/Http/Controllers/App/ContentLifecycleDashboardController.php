<?php

namespace App\Http\Controllers\App;

use App\Enums\ContentLifecycleStatus;
use App\Enums\ContentIntelligenceStatus;
use App\Enums\ContentRefreshTaskStatus;
use App\Enums\SupportedLanguage;
use App\Exceptions\InvalidLifecycleTransitionException;
use App\Http\Controllers\Controller;
use App\Jobs\ContentLifecycle\AnalyzeContentLifecycleJob;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentAiVisibilitySnapshot;
use App\Models\ContentAutomation;
use App\Models\ContentLifecycleAnalysis;
use App\Models\ContentRefreshTask;
use App\Models\ContentSeries;
use App\Models\User;
use App\Services\Content\ContentHealthService;
use App\Services\Content\ContentLifecycleTransitionService;
use App\Services\Performance\PerformanceCacheService;
use App\Support\Database\RequestQueryProfiler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ContentLifecycleDashboardController extends Controller
{
    public function __construct(
        private readonly ContentLifecycleTransitionService $transitionService,
        private readonly ContentHealthService $contentHealthService,
        private readonly PerformanceCacheService $performanceCache,
    ) {}

    /**
     * Display the lifecycle dashboard.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Content::class);
        $profiler = RequestQueryProfiler::startIfEnabled($request, 'content.lifecycle');

        $user = $request->user();
        $organizationId = (int) $user->organization_id;
        $workspaceIds = $user->organization->workspaces()->pluck('id');

        $filters = [
            'stage' => trim((string) $request->query('stage', '')),
            'site' => trim((string) $request->query('site', '')),
            'locale' => trim((string) $request->query('locale', '')),
            'series' => trim((string) $request->query('series', '')),
            'automation' => trim((string) $request->query('automation', '')),
            'assigned' => trim((string) $request->query('assigned', '')),
            'reviewer' => trim((string) $request->query('reviewer', '')),
            'due_filter' => trim((string) $request->query('due_filter', '')),
            'publish_status' => trim((string) $request->query('publish_status', '')),
            'health_range' => trim((string) $request->query('health_range', '')),
            'ai_visibility_range' => trim((string) $request->query('ai_visibility_range', '')),
            'decay_risk' => trim((string) $request->query('decay_risk', '')),
            'missing_answer_blocks' => $request->boolean('missing_answer_blocks'),
            'stale_content' => $request->boolean('stale_content'),
            'weak_internal_links' => $request->boolean('weak_internal_links'),
            'translation_incomplete' => $request->boolean('translation_incomplete'),
            'semantic_coverage' => trim((string) $request->query('semantic_coverage', '')),
            'needs_optimization' => $request->boolean('needs_optimization'),
            'ai_optimized' => trim((string) $request->query('ai_optimized', '')),
            'q' => trim((string) $request->query('q', '')),
        ];

        $stageWindows = $this->resolveStageWindows($request);
        $baseQuery = Content::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->whereNull('deleted_at');

        $query = $this->applyLifecycleFilters($baseQuery, $filters);
        $stageSummaries = $this->performanceCache->rememberOrganization(
            'content-lifecycle-stage-summaries',
            $organizationId,
            ['filters' => $filters],
            now()->addSeconds(90),
            fn (): array => $this->stageSummaries(clone $query)
        );
        $operationsSummary = $this->performanceCache->rememberOrganization(
            'content-lifecycle-operations',
            $organizationId,
            ['filters' => $filters],
            now()->addSeconds(90),
            fn (): array => $this->operationsSummary(clone $query)
        );
        $groupedContents = $this->groupedLifecycleContents($query, $stageWindows, $stageSummaries);
        $cardDataById = $this->buildLifecycleCardData(
            collect($groupedContents)
                ->flatMap(fn (array $stageData): array => $stageData['contents']->all())
                ->values()
        );

        $sites = $this->performanceCache->rememberOrganization(
            'content-lifecycle-sites',
            $organizationId,
            ['workspace_ids' => $workspaceIds->values()->all()],
            now()->addMinutes(10),
            fn () => ClientSite::whereIn('workspace_id', $workspaceIds)
                ->orderBy('name')
                ->get(['id', 'workspace_id', 'name'])
        );

        $seriesList = $this->performanceCache->rememberOrganization(
            'content-lifecycle-series',
            $organizationId,
            ['workspace_ids' => $workspaceIds->values()->all()],
            now()->addMinutes(10),
            fn () => ContentSeries::query()
                ->forOrganization($organizationId)
                ->forWorkspaces($workspaceIds)
                ->orderBy('name')
                ->get(['id', 'organization_id', 'site_id', 'name'])
        );

        $automations = $this->performanceCache->rememberOrganization(
            'content-lifecycle-automations',
            $organizationId,
            ['workspace_ids' => $workspaceIds->values()->all()],
            now()->addMinutes(10),
            fn () => ContentAutomation::whereIn('workspace_id', $workspaceIds)
                ->orderBy('name')
                ->get(['id', 'workspace_id', 'name'])
        );

        $users = $this->performanceCache->rememberOrganization(
            'content-lifecycle-users',
            $organizationId,
            [],
            now()->addMinutes(10),
            fn () => User::where('organization_id', $organizationId)
                ->orderBy('name')
                ->get(['id', 'name'])
        );

        $localeOptions = SupportedLanguage::cases();
        $showLifecycleQueues = $request->boolean('show_lifecycle_queues') || $filters['decay_risk'] !== '';
        $decayAlerts = $showLifecycleQueues ? $this->decayAlerts($workspaceIds) : collect();
        $refreshTasks = $showLifecycleQueues ? $this->refreshQueue($workspaceIds) : collect();
        $healthIndicators = [
            'weak_internal_links' => (int) ($operationsSummary['weak_internal_links'] ?? 0),
            'ai_visibility_decline' => (int) ($operationsSummary['ai_visibility_decline'] ?? 0),
            'stale_articles' => (int) ($operationsSummary['stale_articles'] ?? 0),
            'missing_entity_coverage' => (int) ($operationsSummary['missing_entity_coverage'] ?? 0),
        ];

        $profiler?->logSummary([
            'stage_windows' => $stageWindows,
            'visible_cards' => count($cardDataById),
        ]);

        return view('app.content.lifecycle.index', [
            'filters' => $filters,
            'groupedContents' => $groupedContents,
            'cardDataById' => $cardDataById,
            'stageSummaries' => $stageSummaries,
            'stages' => ContentLifecycleStatus::canonicalStages(),
            'sites' => $sites,
            'seriesList' => $seriesList,
            'automations' => $automations,
            'users' => $users,
            'localeOptions' => $localeOptions,
            'operationsSummary' => $operationsSummary,
            'decayAlerts' => $decayAlerts,
            'refreshTasks' => $refreshTasks,
            'healthIndicators' => $healthIndicators,
        ]);
    }

    public function analyze(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Content::class);

        $workspaceIds = $request->user()->organization->workspaces()->pluck('id');

        foreach ($workspaceIds as $workspaceId) {
            AnalyzeContentLifecycleJob::dispatch((string) $workspaceId, [], 750);
        }

        return redirect()
            ->route('app.content.lifecycle.index', ['show_lifecycle_queues' => 1])
            ->with('status', 'Lifecycle analysis queued. Refresh alerts and tasks will update as workers process the batch.');
    }

    /**
     * @param  Collection<int,string>  $workspaceIds
     * @return Collection<int,ContentLifecycleAnalysis>
     */
    private function decayAlerts(Collection $workspaceIds): Collection
    {
        return ContentLifecycleAnalysis::query()
            ->with(['content:id,title,workspace_id,client_site_id,lifecycle_stage,content_health_score,decay_risk_level,updated_at', 'content.clientSite:id,name'])
            ->whereIn('workspace_id', $workspaceIds)
            ->whereIn('decay_risk_level', ['high', 'critical'])
            ->latest('refresh_priority_score')
            ->latest('analyzed_at')
            ->limit(8)
            ->get();
    }

    /**
     * @param  Collection<int,string>  $workspaceIds
     * @return Collection<int,ContentRefreshTask>
     */
    private function refreshQueue(Collection $workspaceIds): Collection
    {
        return ContentRefreshTask::query()
            ->with(['content:id,title,workspace_id,client_site_id,lifecycle_stage,decay_risk_level,content_health_score', 'content.clientSite:id,name', 'campaign:id,name'])
            ->whereIn('workspace_id', $workspaceIds)
            ->whereIn('status', [
                ContentRefreshTaskStatus::OPEN->value,
                ContentRefreshTaskStatus::QUEUED->value,
                ContentRefreshTaskStatus::IN_PROGRESS->value,
            ])
            ->orderByDesc('priority')
            ->orderBy('due_at')
            ->limit(10)
            ->get();
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    private function applyLifecycleFilters(Builder $query, array $filters): Builder
    {
        if ($filters['stage'] !== '') {
            $query->where('lifecycle_stage', $filters['stage']);
        }

        if ($filters['site'] !== '') {
            $query->where('client_site_id', $filters['site']);
        }

        if ($filters['locale'] !== '') {
            $resolvedLocale = SupportedLanguage::fromStringOrDefault($filters['locale'])->value;
            $query->where('language', $resolvedLocale);
        }

        if ($filters['series'] !== '') {
            $query->where('series_id', $filters['series']);
        }

        if ($filters['automation'] !== '') {
            $query->where('automation_id', $filters['automation']);
        }

        if ($filters['assigned'] !== '') {
            $query->where('assigned_user_id', $filters['assigned']);
        }

        if ($filters['reviewer'] !== '') {
            $query->where('reviewer_user_id', $filters['reviewer']);
        }

        if ($filters['due_filter'] === 'overdue') {
            $query->overdue();
        } elseif ($filters['due_filter'] === 'due_soon') {
            $query->whereNotNull('due_at')
                ->where('due_at', '>', now())
                ->where('due_at', '<', now()->addDays(7));
        } elseif ($filters['due_filter'] === 'no_due_date') {
            $query->whereNull('due_at');
        }

        if ($filters['publish_status'] !== '') {
            $query->where('publish_status', $filters['publish_status']);
        }

        if ($filters['health_range'] !== '') {
            match ($filters['health_range']) {
                'low' => $query->whereNotNull('content_health_score')->where('content_health_score', '<', 40),
                'medium' => $query->whereBetween('content_health_score', [40, 69]),
                'high' => $query->where('content_health_score', '>=', 70),
                default => null,
            };
        }

        if ($filters['ai_visibility_range'] !== '') {
            match ($filters['ai_visibility_range']) {
                'low' => $query->where(function ($builder): void {
                    $builder->whereNull('ai_visibility_score')->orWhere('ai_visibility_score', '<', 40);
                }),
                'medium' => $query->whereBetween('ai_visibility_score', [40, 69]),
                'high' => $query->where('ai_visibility_score', '>=', 70),
                default => null,
            };
        }

        if ($filters['decay_risk'] !== '') {
            $query->where('decay_risk_level', $filters['decay_risk']);
        }

        if ($filters['missing_answer_blocks']) {
            $query->where(function ($builder): void {
                $builder->whereNull('answer_block_score')
                    ->orWhere('answer_block_score', '<', 50)
                    ->orWhere('answer_block_generation_persisted_count', '<', 1);
            });
        }

        if ($filters['stale_content']) {
            $query->where(function ($builder): void {
                $builder->where('lifecycle_stage', ContentLifecycleStatus::REFRESH_NEEDED->value)
                    ->orWhere(function (Builder $nested): void {
                        $nested->whereNotNull('freshness_score')->where('freshness_score', '<', 50);
                    })
                    ->orWhere('updated_at', '<=', now()->subDays(90));
            });
        }

        if ($filters['weak_internal_links']) {
            $query->where(function ($builder): void {
                $builder->whereNull('internal_link_score')
                    ->orWhere('internal_link_score', '<', 50);
            });
        }

        if ($filters['translation_incomplete']) {
            $query->where(function ($builder): void {
                $builder->whereNull('translation_parity_score')
                    ->orWhere('translation_parity_score', '<', 70);
            });
        }

        if ($filters['semantic_coverage'] !== '') {
            match ($filters['semantic_coverage']) {
                'weak' => $query->where(function ($builder): void {
                    $builder->whereNull('semantic_coverage_score')->orWhere('semantic_coverage_score', '<', 50);
                }),
                'strong' => $query->where('semantic_coverage_score', '>=', 70),
                default => null,
            };
        }

        if ($filters['needs_optimization']) {
            $query->whereNotNull('optimization_opportunity_score')
                ->where('optimization_opportunity_score', '>=', 45);
        }

        if ($filters['ai_optimized'] !== '') {
            if ($filters['ai_optimized'] === 'yes') {
                $query->whereNotNull('ai_optimized_at');
            } elseif ($filters['ai_optimized'] === 'no') {
                $query->whereNull('ai_optimized_at');
            }
        }

        if ($filters['q'] !== '') {
            $searchTerm = '%'.$filters['q'].'%';
            $query->where(function ($nested) use ($searchTerm): void {
                $nested->where('title', 'like', $searchTerm)
                    ->orWhere('primary_keyword', 'like', $searchTerm);
            });
        }

        return $query;
    }

    /**
     * @return array<string,int>
     */
    private function resolveStageWindows(Request $request): array
    {
        $windows = [];

        foreach (ContentLifecycleStatus::canonicalStages() as $stage) {
            $limit = (int) $request->integer('stage_window.'.$stage->value, 10);
            $windows[$stage->value] = max(1, min(50, $limit));
        }

        return $windows;
    }

    /**
     * @return array<string,array{count:int,overdue:int,due_soon:int,avg_age_days:float|null,oldest_at:Carbon|null}>
     */
    private function stageSummaries(Builder $query): array
    {
        $now = now();
        $soon = now()->addDays(7);
        $rows = (clone $query)
            ->selectRaw('lifecycle_stage, COUNT(*) as total_count')
            ->selectRaw('SUM(CASE WHEN due_at IS NOT NULL AND due_at < ? THEN 1 ELSE 0 END) as overdue_count', [$now])
            ->selectRaw('SUM(CASE WHEN due_at IS NOT NULL AND due_at > ? AND due_at <= ? THEN 1 ELSE 0 END) as due_soon_count', [$now, $soon])
            ->groupBy('lifecycle_stage')
            ->get()
            ->keyBy('lifecycle_stage');

        $summaries = [];
        $totalCount = 0;
        $totalOverdue = 0;
        $totalDueSoon = 0;

        foreach (ContentLifecycleStatus::canonicalStages() as $stage) {
            $row = $rows->get($stage->value);
            $count = (int) ($row->total_count ?? 0);
            $overdue = (int) ($row->overdue_count ?? 0);
            $dueSoon = (int) ($row->due_soon_count ?? 0);

            $summaries[$stage->value] = [
                'count' => $count,
                'overdue' => $overdue,
                'due_soon' => $dueSoon,
                'avg_age_days' => null,
                'oldest_at' => null,
            ];

            $totalCount += $count;
            $totalOverdue += $overdue;
            $totalDueSoon += $dueSoon;
        }

        $summaries['_total'] = [
            'count' => $totalCount,
            'overdue' => $totalOverdue,
            'due_soon' => $totalDueSoon,
            'avg_age_days' => null,
            'oldest_at' => null,
        ];

        return $summaries;
    }

    /**
     * @param  array<string,array{count:int,overdue:int,due_soon:int,avg_age_days:float|null,oldest_at:Carbon|null}>  $stageSummaries
     * @return array<string,array{stage: ContentLifecycleStatus, contents: Collection<int,Content>, summary: array<string,int|float|null|Carbon|null>, has_more: bool, visible_count: int, limit: int}>
     */
    private function groupedLifecycleContents(Builder $query, array $stageWindows, array $stageSummaries): array
    {
        $grouped = [];
        $maxLimit = max(array_values($stageWindows));
        $visibleIds = $this->visibleLifecycleContentIds($query, $maxLimit);

        $visibleContents = empty($visibleIds)
            ? collect()
            : Content::query()
                ->select($this->lifecycleCardSelectColumns())
                ->with([
                    'clientSite:id,name',
                    'series:id,name',
                    'automation:id,name',
                    'assignedUser:id,name',
                    'reviewerUser:id,name',
                    'indexationHealth:id,content_id,indexed,canonical_accepted,duplicate_detected,redirect_issue,health_score',
                ])
                ->withCount('recommendations')
                ->whereIn('id', $visibleIds)
                ->get()
                ->keyBy(fn (Content $content): string => (string) $content->id);

        foreach (ContentLifecycleStatus::canonicalStages() as $stage) {
            $limit = (int) ($stageWindows[$stage->value] ?? 10);
            $stageContents = collect($visibleIds)
                ->map(fn ($id): ?Content => $visibleContents->get((string) $id))
                ->filter(fn (?Content $content): bool => $content instanceof Content && $content->lifecycleStageEnum()->normalized() === $stage->normalized())
                ->take($limit)
                ->values();

            $totalCount = (int) ($stageSummaries[$stage->value]['count'] ?? 0);
            $grouped[$stage->value] = [
                'stage' => $stage,
                'contents' => $stageContents,
                'summary' => $stageSummaries[$stage->value] ?? [],
                'has_more' => $totalCount > $limit,
                'visible_count' => min($limit, $totalCount),
                'limit' => $limit,
            ];
        }

        return $grouped;
    }

    /**
     * @return list<string>
     */
    private function visibleLifecycleContentIds(Builder $query, int $maxLimit): array
    {
        $stageOrder = collect(ContentLifecycleStatus::canonicalStages())
            ->map(fn (ContentLifecycleStatus $stage): string => $stage->value)
            ->all();

        $candidateLimit = max($maxLimit * max(1, count($stageOrder)) * 4, $maxLimit);

        return (clone $query)
            ->whereNotNull('lifecycle_stage')
            ->whereHas('workspace', fn (Builder $workspaceQuery) => $workspaceQuery->whereNotNull('id'))
            ->select(['id', 'lifecycle_stage', 'updated_at'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($candidateLimit)
            ->get()
            ->sortBy(function (object $row) use ($stageOrder): array {
                $stage = $this->normalizeLifecycleStageValue($row->lifecycle_stage);
                $position = array_search($stage, $stageOrder, true);

                return [$position === false ? PHP_INT_MAX : (int) $position, -1 * (int) optional($row->updated_at)->getTimestamp()];
            })
            ->groupBy(fn (object $row): string => $this->normalizeLifecycleStageValue($row->lifecycle_stage))
            ->flatMap(fn (Collection $rows): Collection => $rows->take($maxLimit)->pluck('id'))
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeLifecycleStageValue(ContentLifecycleStatus|string|null $stage): string
    {
        if ($stage instanceof ContentLifecycleStatus) {
            return $stage->value;
        }

        return trim((string) $stage);
    }

    /**
     * @return list<string>
     */
    private function lifecycleCardSelectColumns(): array
    {
        return [
            'id',
            'title',
            'client_site_id',
            'series_id',
            'automation_id',
            'assigned_user_id',
            'reviewer_user_id',
            'language',
            'is_source_locale',
            'lifecycle_stage',
            'content_health_score',
            'ai_visibility_score',
            'semantic_coverage_score',
            'freshness_score',
            'internal_link_score',
            'answer_block_score',
            'translation_parity_score',
            'decay_risk_level',
            'intelligence_status',
            'optimization_opportunity_score',
            'due_at',
            'rejection_reason',
            'updated_at',
            'created_at',
        ];
    }

    /**
     * @param  Collection<int,Content>  $contents
     * @return array<string,array<string,mixed>>
     */
    private function buildLifecycleCardData(Collection $contents): array
    {
        if ($contents->isEmpty()) {
            return [];
        }

        $contentIds = $contents->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $snapshotRows = ContentAiVisibilitySnapshot::query()
            ->whereIn('content_id', $contentIds)
            ->orderBy('content_id')
            ->orderBy('provider')
            ->orderByDesc('captured_at')
            ->get(['content_id', 'provider', 'visibility_score', 'captured_at'])
            ->groupBy(fn (object $snapshot): string => (string) $snapshot->content_id);

        return $contents->mapWithKeys(function (Content $content) use ($snapshotRows): array {
            $stage = $content->lifecycleStageEnum();
            $intelligenceStatus = $content->intelligence_status
                ?? ContentIntelligenceStatus::OPPORTUNITY;
            $indexationHealth = $content->indexationHealth;
            $visibility = $this->visibilityCardMetrics($snapshotRows->get((string) $content->id, collect()), $content);
            $signalBadges = $this->contentHealthService->signalBadges($content, [
                'indexation_health' => [
                    'indexed' => $indexationHealth?->indexed,
                    'canonical_accepted' => $indexationHealth?->canonical_accepted,
                    'duplicate_detected' => $indexationHealth?->duplicate_detected ?? false,
                    'redirect_issue' => $indexationHealth?->redirect_issue ?? false,
                ],
                'content_health_score' => (int) ($content->content_health_score ?? 0),
                'ai_visibility_score' => $visibility['score'],
                'semantic_coverage_score' => (int) ($content->semantic_coverage_score ?? 0),
                'freshness_score' => (int) ($content->freshness_score ?? 0),
                'internal_link_score' => (int) ($content->internal_link_score ?? 0),
                'answer_block_score' => (int) ($content->answer_block_score ?? 0),
                'translation_parity_score' => (int) ($content->translation_parity_score ?? 0),
                'decay_risk_level' => (string) ($content->decay_risk_level?->value ?? $content->decay_risk_level ?? ''),
                'optimization_opportunity_score' => (int) ($content->optimization_opportunity_score ?? 0),
            ]);

            return [(string) $content->id => [
                'workflow_label' => $stage->label(),
                'intelligence_label' => $intelligenceStatus->label(),
                'intelligence_color' => $intelligenceStatus->color(),
                'content_health_score' => (int) ($content->content_health_score ?? 0),
                'ai_visibility_score' => $visibility['score'],
                'ai_visibility_trend' => $visibility['trend'],
                'provider_pills' => $visibility['provider_pills'],
                'signal_badges' => $signalBadges,
                'recommendations_count' => (int) ($content->recommendations_count ?? 0),
                'locale_label' => strtoupper($content->language?->value ?? 'EN'),
                'is_overdue' => $content->isOverdue(),
                'is_due_soon' => $this->isDueSoon($content),
            ]];
        })->all();
    }

    /**
     * @param  Collection<int,ContentAiVisibilitySnapshot>  $snapshots
     * @return array{score:?int,trend:int,provider_pills:array<int,array{provider:string,score:?int,tone:string}>}
     */
    private function visibilityCardMetrics(Collection $snapshots, Content $content): array
    {
        if ($snapshots->isEmpty()) {
            $score = is_numeric($content->ai_visibility_score) ? (int) $content->ai_visibility_score : null;

            return [
                'score' => $score,
                'trend' => 0,
                'provider_pills' => $this->mockVisibilityProviders($score),
            ];
        }

        $providerRows = $snapshots
            ->groupBy(fn (ContentAiVisibilitySnapshot $snapshot): string => (string) $snapshot->provider)
            ->map(fn (Collection $rows): Collection => $rows->take(2)->values());

        $providerPills = $providerRows->map(function (Collection $rows, string $provider): array {
            $latest = $rows->first();
            $score = is_numeric($latest?->visibility_score) ? (int) $latest->visibility_score : null;

            return [
                'provider' => $provider,
                'score' => $score,
                'tone' => $this->scoreTone($score),
            ];
        })->values();

        $trend = (int) round($providerRows->avg(function (Collection $rows): int {
            $latest = $rows->get(0);
            $previous = $rows->get(1);

            return (int) (($latest?->visibility_score ?? 0) - ($previous?->visibility_score ?? $latest?->visibility_score ?? 0));
        }) ?? 0);

        $score = $providerPills->pluck('score')->filter(fn ($value) => is_int($value))->avg();

        return [
            'score' => is_numeric($score) ? (int) round((float) $score) : (is_numeric($content->ai_visibility_score) ? (int) $content->ai_visibility_score : null),
            'trend' => $trend,
            'provider_pills' => $providerPills->take(4)->all(),
        ];
    }

    /**
     * @return array<int,array{provider:string,score:?int,tone:string}>
     */
    private function mockVisibilityProviders(?int $score): array
    {
        return collect(['ChatGPT', 'Perplexity', 'Gemini', 'Claude'])
            ->map(function (string $provider, int $index) use ($score): array {
                $providerScore = $score === null ? null : max(0, min(100, $score - ($index * 4)));

                return [
                    'provider' => $provider,
                    'score' => $providerScore,
                    'tone' => $this->scoreTone($providerScore),
                ];
            })
            ->all();
    }

    private function scoreTone(?int $score): string
    {
        return match (true) {
            ! is_int($score) => 'slate',
            $score >= 70 => 'green',
            $score >= 40 => 'amber',
            default => 'red',
        };
    }

    /**
     * @return array<string,int|float>
     */
    private function operationsSummary(Builder $query): array
    {
        $row = (clone $query)
            ->selectRaw('COALESCE(ROUND(AVG(content_health_score)), 0) as avg_health_score')
            ->selectRaw('COALESCE(ROUND(AVG(ai_visibility_score)), 0) as avg_ai_visibility_score')
            ->selectRaw("SUM(CASE WHEN intelligence_status IN ('at_risk', 'decaying') OR decay_risk_level IN ('high', 'critical') THEN 1 ELSE 0 END) as at_risk_count")
            ->selectRaw("SUM(CASE WHEN lifecycle_stage = ? OR decay_risk_level IN ('high', 'critical') THEN 1 ELSE 0 END) as refresh_candidates", [ContentLifecycleStatus::REFRESH_NEEDED->value])
            ->selectRaw("SUM(CASE WHEN ai_optimized_at IS NOT NULL OR intelligence_status = 'ai_optimized' THEN 1 ELSE 0 END) as ai_optimized_count")
            ->selectRaw('SUM(CASE WHEN internal_link_score IS NULL OR internal_link_score < 55 THEN 1 ELSE 0 END) as weak_internal_links')
            ->selectRaw('SUM(CASE WHEN ai_visibility_score IS NULL OR ai_visibility_score < 50 THEN 1 ELSE 0 END) as ai_visibility_decline')
            ->selectRaw('SUM(CASE WHEN freshness_score IS NULL OR freshness_score < 45 OR updated_at <= ? THEN 1 ELSE 0 END) as stale_articles', [now()->subDays(120)])
            ->selectRaw('SUM(CASE WHEN semantic_coverage_score IS NULL OR semantic_coverage_score < 55 THEN 1 ELSE 0 END) as missing_entity_coverage')
            ->first();

        return [
            'avg_health_score' => (int) ($row->avg_health_score ?? 0),
            'avg_ai_visibility_score' => (int) ($row->avg_ai_visibility_score ?? 0),
            'at_risk_count' => (int) ($row->at_risk_count ?? 0),
            'refresh_candidates' => (int) ($row->refresh_candidates ?? 0),
            'ai_optimized_count' => (int) ($row->ai_optimized_count ?? 0),
            'weak_internal_links' => (int) ($row->weak_internal_links ?? 0),
            'ai_visibility_decline' => (int) ($row->ai_visibility_decline ?? 0),
            'stale_articles' => (int) ($row->stale_articles ?? 0),
            'missing_entity_coverage' => (int) ($row->missing_entity_coverage ?? 0),
        ];
    }

    private function isDueSoon(Content $content): bool
    {
        if (! $content->due_at) {
            return false;
        }

        $daysUntilDue = Carbon::now()->diffInDays($content->due_at, false);

        return $daysUntilDue > 0 && $daysUntilDue <= 7;
    }

    /**
     * Transition content to a new stage.
     */
    public function transition(Request $request, Content $content): RedirectResponse
    {
        $validated = $request->validate([
            'target_stage' => ['required', 'string'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $targetStage = ContentLifecycleStatus::tryFrom($validated['target_stage']);
        if (! $targetStage) {
            throw ValidationException::withMessages([
                'target_stage' => 'Invalid lifecycle stage.',
            ]);
        }

        // Use policy to check if user can transition to this stage
        $this->authorize('transition', [$content, $validated['target_stage']]);

        try {
            $this->transitionService->transition(
                $content,
                $targetStage,
                $request->user(),
                $validated['notes'] ?? null
            );

            return redirect()->back()->with('success', sprintf(
                'Content moved to "%s" stage.',
                $targetStage->label()
            ));
        } catch (InvalidLifecycleTransitionException $e) {
            return redirect()->back()->withErrors([
                'transition' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send content to review.
     */
    public function sendToReview(Request $request, Content $content): RedirectResponse
    {
        $this->authorize('sendToReview', $content);

        $validated = $request->validate([
            'reviewer_id' => ['nullable', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $reviewer = null;
        if (! empty($validated['reviewer_id'])) {
            $reviewer = User::find($validated['reviewer_id']);
        }

        try {
            $this->transitionService->sendToReview(
                $content,
                $request->user(),
                $reviewer,
                $validated['notes'] ?? null
            );

            return redirect()->back()->with('success', 'Content sent for review.');
        } catch (InvalidLifecycleTransitionException $e) {
            return redirect()->back()->withErrors([
                'transition' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Approve content.
     */
    public function approve(Request $request, Content $content): RedirectResponse
    {
        $this->authorize('approve', $content);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->transitionService->approve(
                $content,
                $request->user(),
                $validated['notes'] ?? null
            );

            return redirect()->back()->with('success', 'Content approved.');
        } catch (InvalidLifecycleTransitionException $e) {
            return redirect()->back()->withErrors([
                'transition' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reject content.
     */
    public function reject(Request $request, Content $content): RedirectResponse
    {
        $this->authorize('reject', $content);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->transitionService->reject(
                $content,
                $request->user(),
                $validated['reason'],
                $validated['notes'] ?? null
            );

            return redirect()->back()->with('success', 'Content rejected and sent back to draft.');
        } catch (InvalidLifecycleTransitionException $e) {
            return redirect()->back()->withErrors([
                'transition' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Assign content to a user.
     */
    public function assign(Request $request, Content $content): RedirectResponse
    {
        $this->authorize('assign', $content);

        $validated = $request->validate([
            'assignee_id' => ['required', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $assignee = User::findOrFail($validated['assignee_id']);

        $this->transitionService->assign(
            $content,
            $assignee,
            $request->user(),
            $validated['notes'] ?? null
        );

        return redirect()->back()->with('success', sprintf(
            'Content assigned to %s.',
            $assignee->name
        ));
    }

    /**
     * Set reviewer for content.
     */
    public function setReviewer(Request $request, Content $content): RedirectResponse
    {
        $this->authorize('setReviewer', $content);

        $validated = $request->validate([
            'reviewer_id' => ['required', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $reviewer = User::findOrFail($validated['reviewer_id']);

        $this->transitionService->setReviewer(
            $content,
            $reviewer,
            $request->user(),
            $validated['notes'] ?? null
        );

        return redirect()->back()->with('success', sprintf(
            '%s set as reviewer.',
            $reviewer->name
        ));
    }

    /**
     * Mark content as needing refresh.
     */
    public function markRefreshNeeded(Request $request, Content $content): RedirectResponse
    {
        $this->authorize('markRefreshNeeded', $content);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->transitionService->markRefreshNeeded(
                $content,
                $request->user(),
                $validated['reason'] ?? null
            );

            return redirect()->back()->with('success', 'Content marked as needing refresh.');
        } catch (InvalidLifecycleTransitionException $e) {
            return redirect()->back()->withErrors([
                'transition' => $e->getMessage(),
            ]);
        }
    }

    /**
     * View lifecycle history for content.
     */
    public function lifecycleHistory(Content $content): View
    {
        $this->authorize('viewLifecycleHistory', $content);

        $content->load([
            'lifecycleEvents' => function ($query) {
                $query->with('user')->orderByDesc('created_at')->limit(50);
            },
            'assignedUser',
            'reviewerUser',
            'approvedByUser',
            'rejectedByUser',
        ]);

        return view('app.content.lifecycle.history', [
            'content' => $content,
            'events' => $content->lifecycleEvents,
        ]);
    }
}
