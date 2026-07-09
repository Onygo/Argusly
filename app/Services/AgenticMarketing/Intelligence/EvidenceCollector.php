<?php

namespace App\Services\AgenticMarketing\Intelligence;

use App\Models\ClientSite;
use App\Models\MarketPackInstallation;
use App\Models\MarketingObservation;
use App\Models\MonitoredPage;
use App\Models\PageCompetitorMatch;
use App\Models\PageGeoObservation;
use App\Models\PageIntelligenceReport;
use App\Models\PageMarketPackMatch;
use App\Models\PagePrValue;
use App\Models\PageScore;
use App\Models\PageSerpObservation;
use App\Models\PageSnapshot;
use App\Models\PageTopic;
use App\Models\ScheduledPageIntelligenceBriefing;
use App\Models\Workspace;
use App\Services\PageIntelligence\ScoreEngineV2;
use App\Services\PerformanceIntelligence\PerformanceIntelligenceEngine;
use App\Services\PerformanceIntelligence\PerformancePageSummary;
use App\Services\PerformanceIntelligence\PerformanceSignal;
use App\Services\PerformanceIntelligence\PerformanceSnapshot;
use App\Services\PerformanceIntelligence\PerformanceTrend;
use App\Support\Intelligence\TimeWindowPreset;
use App\Support\Intelligence\TimeWindowResolver;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EvidenceCollector
{
    public function __construct(
        private readonly PerformanceIntelligenceEngine $performance,
        private readonly TimeWindowResolver $timeWindows,
    )
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(
        Workspace $workspace,
        ?ClientSite $clientSite,
        CarbonInterface|string $from,
        CarbonInterface|string $to,
        string $granularity = MarketingObservation::GRANULARITY_DAILY,
        ?string $marketPackKey = null,
    ): array {
        $window = $this->timeWindows->resolve(TimeWindowPreset::CUSTOM_RANGE, [
            'from' => $from,
            'to' => $to,
            'granularity' => $granularity,
        ], $workspace, $clientSite);
        $periodStart = $window->start;
        $periodEnd = $window->end;
        $performance = $this->performance->snapshot($workspace, $clientSite, $periodStart, $periodEnd, $granularity);
        $scores = $this->latestScoreRows($workspace, $clientSite);
        $performancePageIds = collect($performance->pages)->pluck('pageId')->filter()->map(fn (mixed $id): string => (string) $id);
        $scorePageIds = $scores->pluck('monitored_page_id')->filter()->map(fn (mixed $id): string => (string) $id);
        $pageIds = $performancePageIds->merge($scorePageIds)->unique()->values();

        $pages = $pageIds->isEmpty()
            ? collect()
            : MonitoredPage::query()
                ->with(['latestSnapshot'])
                ->whereIn('id', $pageIds->all())
                ->get()
                ->keyBy(fn (MonitoredPage $page): string => (string) $page->id);

        $serp = $this->pageInputRows(PageSerpObservation::query(), $workspace, $clientSite, $pageIds, 'observed_at', $periodStart, $periodEnd);
        $geo = $this->pageInputRows(PageGeoObservation::query(), $workspace, $clientSite, $pageIds, 'observed_at', $periodStart, $periodEnd);
        $competitors = $this->pageInputRows(
            PageCompetitorMatch::query()->with('competitor'),
            $workspace,
            $clientSite,
            $pageIds,
            'observed_at',
            $periodStart,
            $periodEnd
        );
        $prValues = $this->pageInputRows(PagePrValue::query(), $workspace, $clientSite, $pageIds, 'calculated_at', $periodStart, $periodEnd);
        $topics = $this->pageInputRows(PageTopic::query(), $workspace, $clientSite, $pageIds, 'classified_at', $periodStart, $periodEnd);
        $marketMatches = $this->pageInputRows(PageMarketPackMatch::query(), $workspace, $clientSite, $pageIds, 'observed_at', $periodStart, $periodEnd);
        $reports = $this->reports($workspace, $clientSite, $periodStart, $periodEnd, $marketPackKey);
        $briefings = $this->scheduledBriefings($workspace, $clientSite, $marketPackKey);
        $marketPackContext = $this->marketPackContext($workspace, $clientSite, $performance, $marketMatches, $marketPackKey);
        $pageContexts = $this->pageContexts($performance, $pages, $scores, $serp, $geo, $competitors, $prValues, $topics, $marketMatches);
        $baseEvidence = MarketingEvidence::merge(
            $this->performanceEvidence($performance),
            new MarketingEvidence(
                reportIds: $reports->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
                scheduledBriefingIds: $briefings->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all()
            ),
            ...collect($pageContexts)->map(fn (array $page): MarketingEvidence => $page['evidence'])->all()
        );

        return [
            'workspace' => $workspace,
            'client_site' => $clientSite,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'granularity' => $granularity,
            'performance' => $performance,
            'pages' => $pageContexts,
            'reports' => $reports,
            'scheduled_briefings' => $briefings,
            'market_pack_context' => $marketPackContext,
            'evidence' => $baseEvidence,
            'missing_data' => $this->missingData($performance, $pageContexts, $scores),
        ];
    }

    /**
     * @return Collection<int, PageScore>
     */
    private function latestScoreRows(Workspace $workspace, ?ClientSite $clientSite): Collection
    {
        return PageScore::query()
            ->where('workspace_id', $workspace->id)
            ->when($clientSite, fn (Builder $query): Builder => $query->where('client_site_id', $clientSite->id))
            ->where('model_used', ScoreEngineV2::MODEL_KEY)
            ->where('score_version', ScoreEngineV2::MODEL_VERSION)
            ->orderByDesc('computed_at')
            ->orderByDesc('created_at')
            ->get()
            ->unique(fn (PageScore $score): string => (string) $score->monitored_page_id)
            ->values();
    }

    /**
     * @param  Builder<Model>  $query
     * @param  Collection<int, string>  $pageIds
     * @return Collection<int, Model>
     */
    private function pageInputRows(
        Builder $query,
        Workspace $workspace,
        ?ClientSite $clientSite,
        Collection $pageIds,
        string $timestampColumn,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): Collection {
        if ($pageIds->isEmpty()) {
            return collect();
        }

        return $query
            ->where('workspace_id', $workspace->id)
            ->when($clientSite, fn (Builder $query): Builder => $query->where('client_site_id', $clientSite->id))
            ->whereIn('monitored_page_id', $pageIds->all())
            ->whereBetween($timestampColumn, [$periodStart, $periodEnd])
            ->get();
    }

    /**
     * @return Collection<int, PageIntelligenceReport>
     */
    private function reports(Workspace $workspace, ?ClientSite $clientSite, CarbonInterface $periodStart, CarbonInterface $periodEnd, ?string $marketPackKey): Collection
    {
        return PageIntelligenceReport::query()
            ->where('workspace_id', $workspace->id)
            ->when($clientSite, fn (Builder $query): Builder => $query->where('client_site_id', $clientSite->id))
            ->when($marketPackKey, fn (Builder $query): Builder => $query->where('market_pack_key', $marketPackKey))
            ->where('period_start', '<=', $periodEnd)
            ->where('period_end', '>=', $periodStart)
            ->orderByDesc('generated_at')
            ->limit(10)
            ->get();
    }

    /**
     * @return Collection<int, ScheduledPageIntelligenceBriefing>
     */
    private function scheduledBriefings(Workspace $workspace, ?ClientSite $clientSite, ?string $marketPackKey): Collection
    {
        return ScheduledPageIntelligenceBriefing::query()
            ->where('workspace_id', $workspace->id)
            ->when($clientSite, fn (Builder $query): Builder => $query->where(function (Builder $query) use ($clientSite): void {
                $query->whereNull('client_site_id')->orWhere('client_site_id', $clientSite->id);
            }))
            ->when($marketPackKey, fn (Builder $query): Builder => $query->where(function (Builder $query) use ($marketPackKey): void {
                $query->whereNull('market_pack_key')->orWhere('market_pack_key', $marketPackKey);
            }))
            ->where('is_active', true)
            ->orderBy('next_run_at')
            ->get();
    }

    /**
     * @param  Collection<string, MonitoredPage>  $pages
     * @param  Collection<int, PageScore>  $scores
     * @param  Collection<int, Model>  $serp
     * @param  Collection<int, Model>  $geo
     * @param  Collection<int, Model>  $competitors
     * @param  Collection<int, Model>  $prValues
     * @param  Collection<int, Model>  $topics
     * @param  Collection<int, Model>  $marketMatches
     * @return array<int, array<string, mixed>>
     */
    private function pageContexts(
        PerformanceSnapshot $performance,
        Collection $pages,
        Collection $scores,
        Collection $serp,
        Collection $geo,
        Collection $competitors,
        Collection $prValues,
        Collection $topics,
        Collection $marketMatches,
    ): array {
        $summaries = collect($performance->pages)->keyBy(fn (PerformancePageSummary $summary): string => $summary->pageId);
        $scoreByPage = $scores->keyBy(fn (PageScore $score): string => (string) $score->monitored_page_id);
        $pageIds = $summaries->keys()
            ->merge($scoreByPage->keys())
            ->unique()
            ->values();
        $serpByPage = $serp->groupBy(fn (Model $row): string => (string) $row->getAttribute('monitored_page_id'));
        $geoByPage = $geo->groupBy(fn (Model $row): string => (string) $row->getAttribute('monitored_page_id'));
        $competitorsByPage = $competitors->groupBy(fn (Model $row): string => (string) $row->getAttribute('monitored_page_id'));
        $prByPage = $prValues->groupBy(fn (Model $row): string => (string) $row->getAttribute('monitored_page_id'));
        $topicsByPage = $topics->groupBy(fn (Model $row): string => (string) $row->getAttribute('monitored_page_id'));
        $marketByPage = $marketMatches->groupBy(fn (Model $row): string => (string) $row->getAttribute('monitored_page_id'));

        return $pageIds
            ->map(function (string $pageId) use ($pages, $summaries, $scoreByPage, $serpByPage, $geoByPage, $competitorsByPage, $prByPage, $topicsByPage, $marketByPage): array {
                /** @var MonitoredPage|null $page */
                $page = $pages->get($pageId);
                /** @var PerformancePageSummary|null $summary */
                $summary = $summaries->get($pageId);
                /** @var PageScore|null $score */
                $score = $scoreByPage->get($pageId);
                $serpRows = $serpByPage->get($pageId, collect())->values();
                $geoRows = $geoByPage->get($pageId, collect())->values();
                $competitorRows = $competitorsByPage->get($pageId, collect())->values();
                $prRows = $prByPage->get($pageId, collect())->values();
                $topicRows = $topicsByPage->get($pageId, collect())->values();
                $marketRows = $marketByPage->get($pageId, collect())->values();
                $topicNames = $this->topicNames($summary, $topicRows);
                $competitorNames = $this->competitorNames($competitorRows, $geoRows);
                $marketContext = $this->pageMarketContext($marketRows);
                $pageEvidence = MarketingEvidence::merge(
                    $this->pageSummaryEvidence($summary),
                    $this->scoreEvidence($score),
                    $this->collectionEvidence($serpRows, 'page_serp_observations'),
                    $this->collectionEvidence($geoRows, 'page_geo_observations'),
                    $this->collectionEvidence($competitorRows, 'page_competitor_matches'),
                    $this->collectionEvidence($prRows, 'page_pr_values'),
                    $this->collectionEvidence($topicRows, 'page_topics'),
                    $this->collectionEvidence($marketRows, 'page_market_pack_matches')
                );

                return [
                    'id' => $pageId,
                    'url' => (string) ($page?->canonical_url ?: $page?->final_url ?: $summary?->url),
                    'title' => (string) ($page?->title_current ?: $summary?->title ?: 'Untitled page'),
                    'page' => $page,
                    'snapshot_id' => $page?->latestSnapshot?->id ? (string) $page->latestSnapshot->id : null,
                    'performance_summary' => $summary,
                    'score' => $score,
                    'intelligence_score' => $score ? (float) $score->score : null,
                    'traffic_trend' => $this->trendCategory($summary, 'traffic'),
                    'engagement_trend' => $this->trendCategory($summary, 'engagement'),
                    'visibility_trend' => $this->trendCategory($summary, 'visibility'),
                    'search_visibility' => $this->average($serpRows, 'visibility_score')
                        ?? $this->componentScore($score, 'search_visibility'),
                    'ai_visibility' => $this->average($geoRows, 'geo_visibility_score')
                        ?? $this->componentScore($score, 'ai_visibility'),
                    'competitor_pressure' => $this->max($competitorRows, 'match_score')
                        ?? $this->scorePressure($score, 'competitor_pressure'),
                    'pr_value' => $this->max($prRows, 'score') ?? $this->componentScore($score, 'pr_value'),
                    'topics' => $topicNames,
                    'channels' => $summary?->channels ?: [],
                    'competitors' => $competitorNames,
                    'market_pack_context' => $marketContext,
                    'evidence' => $pageEvidence,
                    'source_counts' => [
                        'serp_observations' => $serpRows->count(),
                        'geo_observations' => $geoRows->count(),
                        'competitor_matches' => $competitorRows->count(),
                        'pr_values' => $prRows->count(),
                        'topics' => $topicRows->count(),
                        'market_pack_matches' => $marketRows->count(),
                    ],
                ];
            })
            ->values()
            ->all();
    }

    private function performanceEvidence(PerformanceSnapshot $performance): MarketingEvidence
    {
        $trendEvidence = collect()
            ->merge($this->trendEvidenceFromSummaries($performance->pages))
            ->merge($this->trendEvidenceFromSummaries($performance->topics))
            ->merge($this->trendEvidenceFromSummaries($performance->channels))
            ->merge($this->trendEvidenceFromSummaries($performance->marketPacks));

        return MarketingEvidence::merge(
            new MarketingEvidence(marketingObservationIds: $performance->observationIds),
            new MarketingEvidence(
                trendIds: $trendEvidence->pluck('trend_id')->values()->all(),
                performanceSignalKeys: collect($performance->signals)->map(fn (PerformanceSignal $signal): string => $signal->key)->values()->all(),
                sourceMetrics: [
                    'performance_snapshot' => [
                        'observations_count' => $performance->observationsCount,
                        'period_start' => $performance->periodStart->toDateTimeString(),
                        'period_end' => $performance->periodEnd->toDateTimeString(),
                        'granularity' => $performance->granularity,
                    ],
                    'performance_signals' => collect($performance->signals)
                        ->mapWithKeys(fn (PerformanceSignal $signal): array => [$signal->key => $signal->sourceMetrics])
                        ->all(),
                ]
            )
        );
    }

    private function pageSummaryEvidence(?PerformancePageSummary $summary): MarketingEvidence
    {
        if (! $summary instanceof PerformancePageSummary) {
            return MarketingEvidence::empty();
        }

        return new MarketingEvidence(
            marketingObservationIds: $summary->observationIds,
            trendIds: collect($summary->trends)->map(fn (PerformanceTrend $trend): string => $this->trendId($trend))->values()->all(),
            sourceMetrics: [
                'page_summary' => [
                    $summary->pageId => [
                        'metrics' => $summary->metrics,
                        'confidence' => $summary->confidence,
                    ],
                ],
            ],
        );
    }

    private function scoreEvidence(?PageScore $score): MarketingEvidence
    {
        if (! $score instanceof PageScore) {
            return MarketingEvidence::empty();
        }

        $evidence = (array) ($score->evidence_json ?? []);
        $inputs = (array) data_get($evidence, 'page_intelligence_input_ids', []);

        return new MarketingEvidence(
            marketingObservationIds: (array) data_get($evidence, 'marketing_observation_ids', []),
            pageSnapshotIds: (array) data_get($evidence, 'page_snapshot_ids', []),
            pageScoreIds: [(string) $score->id],
            trendIds: (array) data_get($evidence, 'trend_ids', []),
            performanceSignalKeys: (array) data_get($evidence, 'performance_signal_keys', []),
            pageIntelligenceInputIds: $inputs,
            sourceMetrics: [
                'intelligence_score_v2' => [
                    (string) $score->id => [
                        'score' => (float) $score->score,
                        'model_used' => $score->model_used,
                        'score_version' => $score->score_version,
                        'components' => collect((array) data_get($score->breakdown_json, 'components', []))
                            ->map(fn (array $component): array => [
                                'score' => $component['score'] ?? null,
                                'confidence' => $component['confidence'] ?? null,
                                'available' => (bool) ($component['available'] ?? false),
                            ])
                            ->all(),
                    ],
                ],
            ],
        );
    }

    /**
     * @param  Collection<int, Model>  $models
     */
    private function collectionEvidence(Collection $models, string $type): MarketingEvidence
    {
        if ($models->isEmpty()) {
            return MarketingEvidence::empty();
        }

        return new MarketingEvidence(
            pageSnapshotIds: $models
                ->pluck('page_snapshot_id')
                ->filter()
                ->map(fn (mixed $id): string => (string) $id)
                ->unique()
                ->values()
                ->all(),
            pageIntelligenceInputIds: [
                $type => $models
                    ->pluck('id')
                    ->filter()
                    ->map(fn (mixed $id): string => (string) $id)
                    ->unique()
                    ->values()
                    ->all(),
            ],
        );
    }

    /**
     * @param  array<int, mixed>  $summaries
     * @return Collection<int, array{trend_id:string, observation_ids:array<int, string>}>
     */
    private function trendEvidenceFromSummaries(array $summaries): Collection
    {
        return collect($summaries)
            ->flatMap(fn (mixed $summary): array => is_object($summary) && property_exists($summary, 'trends') ? (array) $summary->trends : [])
            ->filter(fn (mixed $trend): bool => $trend instanceof PerformanceTrend)
            ->map(fn (PerformanceTrend $trend): array => [
                'trend_id' => $this->trendId($trend),
                'observation_ids' => $trend->observationIds,
            ])
            ->values();
    }

    private function marketPackContext(
        Workspace $workspace,
        ?ClientSite $clientSite,
        PerformanceSnapshot $performance,
        Collection $marketMatches,
        ?string $marketPackKey,
    ): array {
        $key = $marketPackKey
            ?: collect($performance->marketPacks)->pluck('marketPackKey')->filter()->first()
            ?: $marketMatches->pluck('market_pack_key')->filter()->first();
        $source = $key ? 'reasoning_context' : 'missing';
        $installation = null;

        if (! $key) {
            $installation = MarketPackInstallation::query()
                ->where('workspace_id', $workspace->id)
                ->when($clientSite, fn (Builder $query): Builder => $query->where(function (Builder $query) use ($clientSite): void {
                    $query->whereNull('client_site_id')->orWhere('client_site_id', $clientSite->id);
                }))
                ->where('status', MarketPackInstallation::STATUS_ACTIVE)
                ->with('marketPack')
                ->first();
            $key = $installation?->marketPack?->key;
            $source = $key ? 'active_installation' : 'missing';
        }

        $packSummary = $key ? collect($performance->marketPacks)->firstWhere('marketPackKey', $key) : null;

        return [
            'key' => $key ? (string) $key : null,
            'name' => $installation?->marketPack?->name ?: ($packSummary?->marketPackName ?? null),
            'source' => $source,
            'installation_id' => $installation?->id ? (string) $installation->id : null,
            'performance_summary' => $packSummary ? $packSummary->toArray() : null,
        ];
    }

    private function pageMarketContext(Collection $marketRows): array
    {
        $row = $marketRows
            ->sortByDesc(fn (Model $row): float => (float) $row->getAttribute('match_score'))
            ->first();

        if (! $row instanceof Model) {
            return [];
        }

        return [
            'key' => (string) $row->getAttribute('market_pack_key'),
            'name' => $row->getAttribute('market_pack_name') ? (string) $row->getAttribute('market_pack_name') : null,
            'match_score' => is_numeric($row->getAttribute('match_score')) ? (float) $row->getAttribute('match_score') : null,
            'source' => 'page_market_pack_match',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $pageContexts
     * @param  Collection<int, PageScore>  $scores
     * @return array<int, string>
     */
    private function missingData(PerformanceSnapshot $performance, array $pageContexts, Collection $scores): array
    {
        $missing = [];

        if ($performance->observationsCount === 0) {
            $missing[] = 'canonical_marketing_observations';
        }

        if ($scores->isEmpty()) {
            $missing[] = 'intelligence_score_v2';
        }

        if ($pageContexts === []) {
            $missing[] = 'page_mapping';
        }

        foreach ($pageContexts as $page) {
            if (($page['traffic_trend'] ?? null) === null) {
                $missing[] = 'traffic_trend';
            }

            if (($page['engagement_trend'] ?? null) === null) {
                $missing[] = 'engagement_trend';
            }

            if (($page['search_visibility'] ?? null) === null) {
                $missing[] = 'search_visibility';
            }

            if (($page['ai_visibility'] ?? null) === null) {
                $missing[] = 'ai_visibility';
            }
        }

        return collect($missing)->unique()->values()->all();
    }

    private function trendCategory(?PerformancePageSummary $summary, string $category): ?PerformanceTrend
    {
        if (! $summary instanceof PerformancePageSummary) {
            return null;
        }

        return collect($summary->trends)
            ->filter(fn (PerformanceTrend $trend): bool => ! $trend->isInsufficient())
            ->filter(fn (PerformanceTrend $trend): bool => $this->metricCategory($trend->metricKey) === $category)
            ->sortByDesc(fn (PerformanceTrend $trend): float => abs((float) ($trend->growthPercent ?? 0)))
            ->first();
    }

    private function componentScore(?PageScore $score, string $component): ?float
    {
        $value = data_get($score?->breakdown_json, 'components.'.$component.'.score');

        return is_numeric($value) ? (float) $value : null;
    }

    private function scorePressure(?PageScore $score, string $component): ?float
    {
        $value = $this->componentScore($score, $component);

        return $value === null ? null : max(0.0, min(100.0, 100.0 - $value));
    }

    private function average(Collection $rows, string $column): ?float
    {
        $values = $rows
            ->pluck($column)
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): float => (float) $value)
            ->values();

        return $values->isEmpty() ? null : round((float) $values->avg(), 4);
    }

    private function max(Collection $rows, string $column): ?float
    {
        $values = $rows
            ->pluck($column)
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): float => (float) $value)
            ->values();

        return $values->isEmpty() ? null : round((float) $values->max(), 4);
    }

    private function topicNames(?PerformancePageSummary $summary, Collection $topicRows): array
    {
        return collect($summary?->topics ?: [])
            ->pluck('name')
            ->merge($topicRows->pluck('topic_name'))
            ->merge($topicRows->pluck('topic_key')->map(fn (mixed $key): string => Str::headline(str_replace('_', ' ', (string) $key))))
            ->filter()
            ->map(fn (mixed $topic): string => (string) $topic)
            ->unique()
            ->values()
            ->all();
    }

    private function competitorNames(Collection $competitorRows, Collection $geoRows): array
    {
        return $competitorRows
            ->map(fn (Model $match): ?string => $match instanceof PageCompetitorMatch ? $match->competitor?->name : null)
            ->merge($geoRows->flatMap(fn (Model $row): array => (array) $row->getAttribute('mentioned_competitors_json')))
            ->filter()
            ->map(fn (mixed $competitor): string => is_array($competitor)
                ? (string) ($competitor['name'] ?? $competitor['domain'] ?? '')
                : (string) $competitor)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function trendId(PerformanceTrend $trend): string
    {
        return 'performance-trend:'.hash('sha1', implode('|', [
            $trend->metricKey,
            $trend->granularity,
            $trend->periodStart->toDateTimeString(),
            $trend->periodEnd->toDateTimeString(),
            implode(',', $trend->observationIds),
        ]));
    }

    private function metricCategory(string $metricKey): string
    {
        $metric = mb_strtolower($metricKey);

        foreach (['sessions', 'users', 'clicks', 'pageviews', 'views', 'traffic'] as $part) {
            if (str_contains($metric, $part)) {
                return 'traffic';
            }
        }

        foreach (['engagement', 'ctr', 'duration', 'event', 'comment', 'share', 'reaction', 'follower'] as $part) {
            if (str_contains($metric, $part)) {
                return 'engagement';
            }
        }

        foreach (['impressions', 'visibility', 'position', 'rank', 'citation', 'topic_ownership'] as $part) {
            if (str_contains($metric, $part)) {
                return 'visibility';
            }
        }

        return 'performance';
    }

}
