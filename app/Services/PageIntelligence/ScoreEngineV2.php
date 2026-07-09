<?php

namespace App\Services\PageIntelligence;

use App\Models\ClientSite;
use App\Models\MarketPackInstallation;
use App\Models\MarketPackScoringModel;
use App\Models\MarketingObservation;
use App\Models\PageCompetitorMatch;
use App\Models\PageEntity;
use App\Models\PageGeoObservation;
use App\Models\PageMarketPackMatch;
use App\Models\PagePrValue;
use App\Models\PageScore;
use App\Models\PageSerpObservation;
use App\Models\PageSnapshot;
use App\Models\PageTopic;
use App\Models\Workspace;
use App\Services\PageIntelligence\Scoring\ScoreBreakdown;
use App\Services\PageIntelligence\Scoring\ScoreComponent;
use App\Services\PageIntelligence\Scoring\ScoreEvidence;
use App\Services\PageIntelligence\Scoring\ScoreExplanation;
use App\Services\PerformanceIntelligence\PerformanceIntelligenceEngine;
use App\Services\PerformanceIntelligence\PerformancePageSummary;
use App\Services\PerformanceIntelligence\PerformanceSignal;
use App\Services\PerformanceIntelligence\PerformanceSnapshot;
use App\Services\PerformanceIntelligence\PerformanceTrend;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ScoreEngineV2
{
    public const SCORE_TYPE = PageIntelligenceScoreCalculator::SCORE_TYPE;
    public const MODEL_KEY = 'argusly_intelligence_score_v2';
    public const MODEL_VERSION = 'v2';
    public const CALCULATION_METHOD = 'deterministic_composite_v2';

    public function __construct(private readonly PerformanceIntelligenceEngine $performance)
    {
    }

    public function calculate(
        PageSnapshot $snapshot,
        CarbonInterface|string|null $from = null,
        CarbonInterface|string|null $to = null,
        string $granularity = MarketingObservation::GRANULARITY_DAILY,
    ): PageScore {
        $snapshot = $snapshot->loadMissing(['page.source', 'contentExtraction']);
        $workspace = Workspace::query()->findOrFail($snapshot->workspace_id);
        $clientSite = $snapshot->client_site_id ? ClientSite::query()->find($snapshot->client_site_id) : null;
        $periodEnd = $this->immutable($to ?: $snapshot->fetched_at ?: $snapshot->created_at ?: now());
        $periodStart = $this->immutable($from ?: $periodEnd->subDays($this->lookbackDays() - 1));
        $performance = $this->performance->snapshot($workspace, $clientSite, $periodStart, $periodEnd, $granularity);
        $marketPack = $this->marketPackKey($snapshot);
        $weights = $this->weights($marketPack['key'], $workspace, $clientSite);
        $components = $this->components($snapshot, $performance, $weights);
        $missingInputs = collect($components)
            ->filter(fn (ScoreComponent $component): bool => ! $component->available)
            ->keys()
            ->values()
            ->all();
        $availableWeight = round((float) collect($components)
            ->filter(fn (ScoreComponent $component): bool => $component->available)
            ->sum(fn (ScoreComponent $component): float => $component->weight), 4);
        $rawScore = round((float) collect($components)->sum(fn (ScoreComponent $component): float => $component->weighted), 2);
        $confidenceMultiplier = $availableWeight > 0
            ? (float) collect($components)
                ->filter(fn (ScoreComponent $component): bool => $component->available)
                ->sum(fn (ScoreComponent $component): float => $component->confidence * $component->weight) / $availableWeight
            : 0.0;
        $confidence = round(max(0.0, min(100.0, $availableWeight * $confidenceMultiplier * 100)), 2);
        $confidenceAdjustedScore = round($rawScore * ($confidence / 100), 2);
        $evidence = ScoreEvidence::merge(
            new ScoreEvidence(pageSnapshotIds: [(string) $snapshot->id]),
            ...array_map(fn (ScoreComponent $component): ScoreEvidence => $component->evidence, array_values($components))
        );
        $breakdown = new ScoreBreakdown(
            modelKey: $this->modelKey(),
            modelVersion: $this->modelVersion(),
            scoreType: self::SCORE_TYPE,
            calculationMethod: self::CALCULATION_METHOD,
            marketPackKey: $marketPack['key'],
            marketPackSource: $marketPack['source'],
            periodStart: $performance->periodStart,
            periodEnd: $performance->periodEnd,
            weights: $weights,
            components: $components,
            missingInputs: $missingInputs,
            availableWeight: $availableWeight,
            missingWeightTotal: round(max(0.0, 1 - $availableWeight), 4),
            rawScore: $rawScore,
            confidence: $confidence,
            confidenceAdjustedScore: $confidenceAdjustedScore,
        );
        $explanation = new ScoreExplanation(
            summary: 'Argusly Intelligence Score v2 combines page intelligence, performance intelligence, search visibility, AI visibility, PR value, competitor pressure, market-pack relevance, risk and opportunity signals.',
            method: self::CALCULATION_METHOD,
            modelKey: $this->modelKey(),
            modelVersion: $this->modelVersion(),
            componentExplanations: collect($components)->map(fn (ScoreComponent $component): string => $component->explanation)->all(),
            missingInputs: $missingInputs,
            evidence: $evidence,
        );
        $previous = PageScore::query()
            ->where('monitored_page_id', $snapshot->monitored_page_id)
            ->where('score_type', self::SCORE_TYPE)
            ->where('score_version', $this->modelVersion())
            ->where('page_snapshot_id', '!=', $snapshot->id)
            ->latest('computed_at')
            ->first();
        $computedAt = now();

        return PageScore::query()->updateOrCreate(
            [
                'page_snapshot_id' => $snapshot->id,
                'score_type' => self::SCORE_TYPE,
                'score_version' => $this->modelVersion(),
            ],
            [
                'organization_id' => $snapshot->organization_id,
                'workspace_id' => $snapshot->workspace_id,
                'client_site_id' => $snapshot->client_site_id,
                'monitored_page_id' => $snapshot->monitored_page_id,
                'page_content_extraction_id' => $snapshot->contentExtraction?->id,
                'score' => $confidenceAdjustedScore,
                'previous_score' => $previous?->score,
                'delta' => $previous ? round($confidenceAdjustedScore - (float) $previous->score, 2) : null,
                'calculation_method' => self::CALCULATION_METHOD,
                'model_used' => $this->modelKey(),
                'explanation' => $explanation->summary,
                'breakdown_json' => $breakdown->toArray(),
                'evidence_json' => [
                    ...$evidence->toArray(),
                    'period_start' => $performance->periodStart->toDateTimeString(),
                    'period_end' => $performance->periodEnd->toDateTimeString(),
                    'granularity' => $performance->granularity,
                    'market_pack_key' => $marketPack['key'],
                    'market_pack_source' => $marketPack['source'],
                    'score_explanation' => $explanation->toArray(),
                ],
                'computed_at' => $computedAt,
                'metadata_json' => [
                    'model_key' => $this->modelKey(),
                    'model_version' => $this->modelVersion(),
                    'phase' => 'phase_36_intelligence_score_v2',
                    'missing_inputs' => $missingInputs,
                    'raw_score' => $rawScore,
                    'confidence' => $confidence,
                    'confidence_adjusted_score' => $confidenceAdjustedScore,
                    'computed_at' => $computedAt->toISOString(),
                    'lookback_days' => $this->lookbackDays(),
                    'market_pack_key' => $marketPack['key'],
                    'market_pack_source' => $marketPack['source'],
                    'performance_observations_count' => $performance->observationsCount,
                ],
            ]
        );
    }

    /**
     * @param  array<string, float>  $weights
     * @return array<string, ScoreComponent>
     */
    private function components(PageSnapshot $snapshot, PerformanceSnapshot $performance, array $weights): array
    {
        $labels = (array) config('argusly.intelligence_score_v2.labels', []);
        $builders = [
            'organic_growth' => fn (string $key, float $weight): ScoreComponent => $this->organicGrowth($key, $this->label($key, $labels), $weight, $performance),
            'traffic_trend' => fn (string $key, float $weight): ScoreComponent => $this->pageTrendCategory($key, $this->label($key, $labels), $weight, $snapshot, $performance, 'traffic'),
            'engagement_trend' => fn (string $key, float $weight): ScoreComponent => $this->pageTrendCategory($key, $this->label($key, $labels), $weight, $snapshot, $performance, 'engagement'),
            'topic_momentum' => fn (string $key, float $weight): ScoreComponent => $this->topicMomentum($key, $this->label($key, $labels), $weight, $snapshot, $performance),
            'channel_momentum' => fn (string $key, float $weight): ScoreComponent => $this->signalOverlapComponent($key, $this->label($key, $labels), $weight, $snapshot, $performance, 'channel_momentum'),
            'content_momentum' => fn (string $key, float $weight): ScoreComponent => $this->contentMomentum($key, $this->label($key, $labels), $weight, $snapshot, $performance),
            'search_visibility' => fn (string $key, float $weight): ScoreComponent => $this->searchVisibility($key, $this->label($key, $labels), $weight, $snapshot, $performance),
            'ai_visibility' => fn (string $key, float $weight): ScoreComponent => $this->aiVisibility($key, $this->label($key, $labels), $weight, $snapshot, $performance),
            'pr_value' => fn (string $key, float $weight): ScoreComponent => $this->prValue($key, $this->label($key, $labels), $weight, $snapshot),
            'competitor_pressure' => fn (string $key, float $weight): ScoreComponent => $this->competitorPressure($key, $this->label($key, $labels), $weight, $snapshot),
            'market_pack_relevance' => fn (string $key, float $weight): ScoreComponent => $this->marketPackRelevance($key, $this->label($key, $labels), $weight, $snapshot),
            'risk' => fn (string $key, float $weight): ScoreComponent => $this->risk($key, $this->label($key, $labels), $weight, $snapshot, $performance),
            'opportunity' => fn (string $key, float $weight): ScoreComponent => $this->signalOverlapComponent($key, $this->label($key, $labels), $weight, $snapshot, $performance, 'performance_opportunity'),
        ];

        return collect($weights)
            ->mapWithKeys(fn (float $weight, string $key): array => [
                $key => isset($builders[$key])
                    ? $builders[$key]($key, $weight)
                    : $this->missingComponent($key, $this->label($key, $labels), $weight, 'No v2 component builder is registered for this configured weight.'),
            ])
            ->all();
    }

    private function organicGrowth(string $key, string $label, float $weight, PerformanceSnapshot $performance): ScoreComponent
    {
        return $this->componentFromSignals($key, $label, $weight, collect($performance->signals)->where('type', 'organic_growth'), 'Organic growth is derived from Performance Intelligence channel signals.');
    }

    private function pageTrendCategory(string $key, string $label, float $weight, PageSnapshot $snapshot, PerformanceSnapshot $performance, string $category): ScoreComponent
    {
        $summary = $this->pageSummary($snapshot, $performance);
        $trends = $summary
            ? collect($summary->trends)->filter(fn (PerformanceTrend $trend): bool => $this->metricCategory($trend->metricKey) === $category)
            : collect();

        return $this->componentFromTrends($key, $label, $weight, $trends, Str::headline($category).' trend is derived from page-scoped Performance Intelligence trends.');
    }

    private function topicMomentum(string $key, string $label, float $weight, PageSnapshot $snapshot, PerformanceSnapshot $performance): ScoreComponent
    {
        $trends = collect($performance->topics)
            ->filter(fn ($summary): bool => in_array((string) $snapshot->monitored_page_id, $summary->pageIds, true))
            ->flatMap(fn ($summary): array => $summary->trends);

        return $this->componentFromTrends($key, $label, $weight, $trends, 'Topic momentum is derived from topic summaries linked to this page.');
    }

    private function contentMomentum(string $key, string $label, float $weight, PageSnapshot $snapshot, PerformanceSnapshot $performance): ScoreComponent
    {
        $summary = $this->pageSummary($snapshot, $performance);

        return $this->componentFromTrends(
            $key,
            $label,
            $weight,
            $summary ? collect($summary->trends) : collect(),
            'Content momentum is derived from page-scoped Performance Intelligence trends.'
        );
    }

    private function searchVisibility(string $key, string $label, float $weight, PageSnapshot $snapshot, PerformanceSnapshot $performance): ScoreComponent
    {
        [$serpRows, $source] = $this->scopedRows(
            PageSerpObservation::query()->where('monitored_page_id', $snapshot->monitored_page_id),
            $snapshot,
            'observed_at'
        );
        $summary = $this->pageSummary($snapshot, $performance);
        $visibilityTrends = $summary
            ? collect($summary->trends)->filter(fn (PerformanceTrend $trend): bool => $this->metricCategory($trend->metricKey) === 'visibility')
            : collect();
        $scores = $serpRows
            ->pluck('visibility_score')
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): float => (float) $value)
            ->merge($visibilityTrends->map(fn (PerformanceTrend $trend): ?float => $this->trendScore($trend))->filter());

        if ($scores->isEmpty()) {
            return $this->missingComponent($key, $label, $weight, 'Search visibility requires SERP observations or visibility-class canonical marketing observations.');
        }

        $evidence = ScoreEvidence::merge(
            $this->collectionEvidence($serpRows, 'page_serp_observations'),
            ...$visibilityTrends->map(fn (PerformanceTrend $trend): ScoreEvidence => $this->trendEvidence($trend))->values()->all()
        );

        return $this->component(
            key: $key,
            label: $label,
            score: (float) $scores->avg(),
            weight: $weight,
            confidence: $this->boundedConfidence($serpRows->isNotEmpty() ? 0.9 : 0.75, $visibilityTrends->avg(fn (PerformanceTrend $trend): float => $trend->confidence)),
            source: 'serp_and_canonical_visibility',
            explanation: 'Search visibility combines SERP visibility scores with page-scoped visibility trends.',
            evidence: $evidence,
            metadata: [
                'fallback_source' => $source,
                'serp_observation_count' => $serpRows->count(),
                'visibility_trend_count' => $visibilityTrends->count(),
            ],
        );
    }

    private function aiVisibility(string $key, string $label, float $weight, PageSnapshot $snapshot, PerformanceSnapshot $performance): ScoreComponent
    {
        [$geoRows, $source] = $this->scopedRows(
            PageGeoObservation::query()->where('monitored_page_id', $snapshot->monitored_page_id),
            $snapshot,
            'observed_at'
        );
        $summary = $this->pageSummary($snapshot, $performance);
        $aiTrends = $summary
            ? collect($summary->trends)->filter(fn (PerformanceTrend $trend): bool => $this->isAiVisibilityMetric($trend->metricKey))
            : collect();
        $scores = $geoRows
            ->pluck('geo_visibility_score')
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): float => (float) $value)
            ->merge($aiTrends->map(fn (PerformanceTrend $trend): ?float => $this->trendScore($trend))->filter());

        if ($scores->isEmpty()) {
            return $this->missingComponent($key, $label, $weight, 'AI visibility requires GEO observations or canonical AI visibility observations.');
        }

        $evidence = ScoreEvidence::merge(
            $this->collectionEvidence($geoRows, 'page_geo_observations'),
            ...$aiTrends->map(fn (PerformanceTrend $trend): ScoreEvidence => $this->trendEvidence($trend))->values()->all()
        );

        return $this->component(
            key: $key,
            label: $label,
            score: (float) $scores->avg(),
            weight: $weight,
            confidence: $this->boundedConfidence($geoRows->isNotEmpty() ? 0.9 : 0.75, $aiTrends->avg(fn (PerformanceTrend $trend): float => $trend->confidence)),
            source: 'geo_and_canonical_ai_visibility',
            explanation: 'AI visibility combines GEO visibility scores with canonical AI visibility trends.',
            evidence: $evidence,
            metadata: [
                'fallback_source' => $source,
                'geo_observation_count' => $geoRows->count(),
                'ai_visibility_trend_count' => $aiTrends->count(),
            ],
        );
    }

    private function prValue(string $key, string $label, float $weight, PageSnapshot $snapshot): ScoreComponent
    {
        [$value, $source] = $this->scopedRecord(
            PagePrValue::query()->where('monitored_page_id', $snapshot->monitored_page_id),
            $snapshot,
            'calculated_at'
        );

        if (! $value instanceof PagePrValue || ! is_numeric($value->score)) {
            return $this->missingComponent($key, $label, $weight, 'PR value requires a page PR Intelligence value for this snapshot period.');
        }

        return $this->component(
            key: $key,
            label: $label,
            score: (float) $value->score,
            weight: $weight,
            confidence: $this->normalizeConfidence($value->confidence),
            source: $value->model_key.' '.$value->model_version,
            explanation: 'PR value is sourced from the page PR Intelligence value attached to this page.',
            evidence: $this->modelEvidence($value, 'page_pr_values'),
            metadata: [
                'fallback_source' => $source,
                'estimated_value_amount' => $value->estimated_value_amount === null ? null : (float) $value->estimated_value_amount,
                'currency' => $value->currency,
            ],
        );
    }

    private function competitorPressure(string $key, string $label, float $weight, PageSnapshot $snapshot): ScoreComponent
    {
        [$matches] = $this->scopedRows(
            PageCompetitorMatch::query()->where('monitored_page_id', $snapshot->monitored_page_id),
            $snapshot,
            'observed_at'
        );
        [$entities] = $this->scopedRows(
            PageEntity::query()
                ->where('monitored_page_id', $snapshot->monitored_page_id)
                ->where('entity_type', PageEntity::TYPE_COMPETITOR),
            $snapshot,
            'observed_at'
        );
        $matchPressure = (float) $matches->max('match_score');
        $entityPressure = max((float) $entities->max('prominence_score'), min(100, (int) $entities->sum('mention_count') * 20));
        $pressure = max($matchPressure, $entityPressure);

        if ($pressure <= 0) {
            return $this->missingComponent($key, $label, $weight, 'Competitor pressure requires competitor matches or competitor entity evidence.');
        }

        return $this->component(
            key: $key,
            label: $label,
            score: 100 - $pressure,
            weight: $weight,
            confidence: $this->boundedConfidence($matches->isNotEmpty() ? 0.85 : null, $entities->isNotEmpty() ? 0.8 : null),
            source: 'page_competitor_intelligence',
            explanation: 'Competitor pressure lowers the component score as competitor match strength or mentions increase.',
            evidence: ScoreEvidence::merge(
                $this->collectionEvidence($matches, 'page_competitor_matches'),
                $this->collectionEvidence($entities, 'page_entities')
            ),
            metadata: [
                'raw_competitor_pressure' => round($pressure, 4),
                'match_pressure' => round($matchPressure, 4),
                'entity_pressure' => round($entityPressure, 4),
            ],
        );
    }

    private function marketPackRelevance(string $key, string $label, float $weight, PageSnapshot $snapshot): ScoreComponent
    {
        [$matches] = $this->scopedRows(
            PageMarketPackMatch::query()->where('monitored_page_id', $snapshot->monitored_page_id),
            $snapshot,
            'observed_at'
        );
        [$topics] = $this->scopedRows(
            PageTopic::query()
                ->where('monitored_page_id', $snapshot->monitored_page_id)
                ->where('source_type', 'market_pack'),
            $snapshot,
            'classified_at'
        );
        $matchScore = (float) $matches->max('match_score');
        $topicScore = $topics->isEmpty() ? 0.0 : (float) $topics->avg(fn (PageTopic $topic): float => (float) $topic->confidence_score);
        $score = max($matchScore, $topicScore);

        if ($score <= 0) {
            return $this->missingComponent($key, $label, $weight, 'Market-pack relevance requires market-pack matches or market-pack topic classifications.');
        }

        return $this->component(
            key: $key,
            label: $label,
            score: $score,
            weight: $weight,
            confidence: $this->boundedConfidence($matches->isNotEmpty() ? 0.9 : null, $topics->isNotEmpty() ? 0.85 : null),
            source: 'page_market_pack_intelligence',
            explanation: 'Market-pack relevance uses page market-pack matches and market-pack topic classifications.',
            evidence: ScoreEvidence::merge(
                $this->collectionEvidence($matches, 'page_market_pack_matches'),
                $this->collectionEvidence($topics, 'page_topics')
            ),
            metadata: [
                'match_score' => round($matchScore, 4),
                'topic_score' => round($topicScore, 4),
            ],
        );
    }

    private function risk(string $key, string $label, float $weight, PageSnapshot $snapshot, PerformanceSnapshot $performance): ScoreComponent
    {
        $pageSummary = $this->pageSummary($snapshot, $performance);
        $signals = $this->signalsWithPageOverlap($performance, $pageSummary, 'performance_risk');

        if ($performance->observationIds === []) {
            return $this->missingComponent($key, $label, $weight, 'Risk requires canonical observations in the scoring period.');
        }

        if ($signals->isEmpty()) {
            return $this->component(
                key: $key,
                label: $label,
                score: 100,
                weight: $weight,
                confidence: $pageSummary?->confidence ?? 0.65,
                source: 'performance_intelligence',
                explanation: 'No page-linked performance risk signal was detected for the scoring period.',
                evidence: new ScoreEvidence(marketingObservationIds: $pageSummary?->observationIds ?: $performance->observationIds),
                metadata: ['risk_signal_count' => 0],
            );
        }

        return $this->componentFromSignals($key, $label, $weight, $signals, 'Risk is reduced when Performance Intelligence decline signals are weaker.');
    }

    private function signalOverlapComponent(string $key, string $label, float $weight, PageSnapshot $snapshot, PerformanceSnapshot $performance, string $type): ScoreComponent
    {
        $signals = $this->signalsWithPageOverlap($performance, $this->pageSummary($snapshot, $performance), $type);

        return $this->componentFromSignals($key, $label, $weight, $signals, Str::headline(str_replace('_', ' ', $type)).' is derived from page-linked Performance Intelligence signals.');
    }

    /**
     * @param  Collection<int, PerformanceSignal>  $signals
     */
    private function componentFromSignals(string $key, string $label, float $weight, Collection $signals, string $explanation): ScoreComponent
    {
        $signals = $signals
            ->filter(fn (mixed $signal): bool => $signal instanceof PerformanceSignal)
            ->filter(fn (PerformanceSignal $signal): bool => $signal->observationIds !== [])
            ->values();

        if ($signals->isEmpty()) {
            return $this->missingComponent($key, $label, $weight, $explanation.' No qualifying performance signal is available.');
        }

        $scores = $signals->map(fn (PerformanceSignal $signal): float => $this->signalScore($signal));

        return $this->component(
            key: $key,
            label: $label,
            score: (float) $scores->avg(),
            weight: $weight,
            confidence: (float) $signals->avg(fn (PerformanceSignal $signal): float => $signal->confidence),
            source: 'performance_intelligence_signals',
            explanation: $explanation,
            evidence: ScoreEvidence::merge(...$signals->map(fn (PerformanceSignal $signal): ScoreEvidence => $this->signalEvidence($signal))->all()),
            metadata: [
                'signal_count' => $signals->count(),
                'signal_types' => $signals->pluck('type')->unique()->values()->all(),
            ],
        );
    }

    /**
     * @param  Collection<int, PerformanceTrend>  $trends
     */
    private function componentFromTrends(string $key, string $label, float $weight, Collection $trends, string $explanation): ScoreComponent
    {
        $trends = $trends
            ->filter(fn (mixed $trend): bool => $trend instanceof PerformanceTrend && ! $trend->isInsufficient())
            ->values();

        if ($trends->isEmpty()) {
            return $this->missingComponent($key, $label, $weight, $explanation.' No sufficient trend data is available.');
        }

        $scores = $trends->map(fn (PerformanceTrend $trend): float => $this->trendScore($trend));

        return $this->component(
            key: $key,
            label: $label,
            score: (float) $scores->avg(),
            weight: $weight,
            confidence: (float) $trends->avg(fn (PerformanceTrend $trend): float => $trend->confidence),
            source: 'performance_intelligence_trends',
            explanation: $explanation,
            evidence: ScoreEvidence::merge(...$trends->map(fn (PerformanceTrend $trend): ScoreEvidence => $this->trendEvidence($trend))->all()),
            metadata: [
                'trend_count' => $trends->count(),
                'metric_keys' => $trends->pluck('metricKey')->unique()->values()->all(),
            ],
        );
    }

    private function component(
        string $key,
        string $label,
        ?float $score,
        float $weight,
        float $confidence,
        string $source,
        string $explanation,
        ScoreEvidence $evidence,
        array $metadata = [],
    ): ScoreComponent {
        $score = $score === null ? null : $this->clamp($score);

        return new ScoreComponent(
            key: $key,
            label: $label,
            score: $score,
            available: $score !== null,
            weight: round($weight, 4),
            weighted: $score === null ? 0.0 : round($score * $weight, 4),
            confidence: round(max(0.0, min(1.0, $confidence)), 4),
            source: $source,
            explanation: $explanation,
            evidence: $evidence,
            metadata: $metadata,
        );
    }

    private function missingComponent(string $key, string $label, float $weight, string $explanation): ScoreComponent
    {
        return $this->component(
            key: $key,
            label: $label,
            score: null,
            weight: $weight,
            confidence: 0.0,
            source: 'missing',
            explanation: $explanation,
            evidence: new ScoreEvidence(),
        );
    }

    private function pageSummary(PageSnapshot $snapshot, PerformanceSnapshot $performance): ?PerformancePageSummary
    {
        return collect($performance->pages)->first(fn (PerformancePageSummary $summary): bool => $summary->pageId === (string) $snapshot->monitored_page_id);
    }

    private function signalsWithPageOverlap(PerformanceSnapshot $performance, ?PerformancePageSummary $pageSummary, string $type): Collection
    {
        $pageObservationIds = $pageSummary?->observationIds ?? [];

        return collect($performance->signals)
            ->filter(fn (PerformanceSignal $signal): bool => $signal->type === $type)
            ->filter(function (PerformanceSignal $signal) use ($pageSummary, $pageObservationIds): bool {
                if ($pageSummary && $signal->subjectType === 'page' && $signal->subjectKey === $pageSummary->pageId) {
                    return true;
                }

                return $pageObservationIds !== []
                    && array_intersect($pageObservationIds, $signal->observationIds) !== [];
            })
            ->values();
    }

    private function signalScore(PerformanceSignal $signal): float
    {
        $growth = abs((float) ($signal->metadata['growth_percent'] ?? 0));
        $movement = min(50.0, $growth);

        return $signal->direction === 'decline'
            ? $this->clamp(50 - $movement)
            : $this->clamp(50 + $movement);
    }

    private function trendScore(PerformanceTrend $trend): float
    {
        $growth = abs((float) ($trend->growthPercent ?? 0));
        $movement = min(50.0, $growth);

        return $trend->direction === 'decline'
            ? $this->clamp(50 - $movement)
            : $this->clamp(50 + $movement);
    }

    private function trendEvidence(PerformanceTrend $trend): ScoreEvidence
    {
        return new ScoreEvidence(
            marketingObservationIds: $trend->observationIds,
            trendIds: [$this->trendId($trend)],
            sourceMetrics: ['trends' => [$this->trendId($trend) => $trend->sourceMetrics]],
        );
    }

    private function signalEvidence(PerformanceSignal $signal): ScoreEvidence
    {
        return new ScoreEvidence(
            marketingObservationIds: $signal->observationIds,
            trendIds: [$this->signalTrendId($signal)],
            performanceSignalKeys: [$signal->key],
            sourceMetrics: ['signals' => [$signal->key => $signal->sourceMetrics]],
        );
    }

    private function modelEvidence(Model $model, string $type): ScoreEvidence
    {
        return new ScoreEvidence(
            pageSnapshotIds: $model->page_snapshot_id ? [(string) $model->page_snapshot_id] : [],
            pageIntelligenceInputIds: [$type => [(string) $model->getKey()]],
        );
    }

    private function collectionEvidence(Collection $models, string $type): ScoreEvidence
    {
        return new ScoreEvidence(
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

    private function signalTrendId(PerformanceSignal $signal): string
    {
        return 'performance-trend:'.hash('sha1', implode('|', [
            $signal->type,
            $signal->subjectType,
            $signal->subjectKey,
            $signal->metricKey,
            $signal->periodStart->toDateTimeString(),
            $signal->periodEnd->toDateTimeString(),
            implode(',', $signal->observationIds),
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

    private function isAiVisibilityMetric(string $metricKey): bool
    {
        $metric = mb_strtolower($metricKey);

        foreach (['ai_visibility', 'geo_visibility', 'citation', 'topic_ownership', 'answer_engine'] as $part) {
            if (str_contains($metric, $part)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{key:?string,source:string,input_id?:string,input_timestamp?:string}
     */
    private function marketPackKey(PageSnapshot $snapshot): array
    {
        $metadataKey = data_get($snapshot->page?->source?->metadata_json, 'market_pack_key');
        if (is_string($metadataKey) && $metadataKey !== '') {
            return [
                'key' => $metadataKey,
                'source' => 'source_metadata',
                'input_id' => (string) $snapshot->page?->source?->id,
                'input_timestamp' => $snapshot->page?->source?->updated_at?->toISOString(),
            ];
        }

        [$matches] = $this->scopedRows(
            PageMarketPackMatch::query()->where('monitored_page_id', $snapshot->monitored_page_id),
            $snapshot,
            'observed_at'
        );
        $match = $matches->sortByDesc(fn (PageMarketPackMatch $match): float => (float) $match->match_score)->first();

        if ($match?->market_pack_key) {
            return [
                'key' => (string) $match->market_pack_key,
                'source' => 'page_market_pack_match',
                'input_id' => (string) $match->id,
                'input_timestamp' => $match->observed_at?->toISOString(),
            ];
        }

        if ($this->isLatestSnapshot($snapshot)) {
            $pack = MarketPackInstallation::query()
                ->where('workspace_id', $snapshot->workspace_id)
                ->where('status', MarketPackInstallation::STATUS_ACTIVE)
                ->with('marketPack:id,key')
                ->first()?->marketPack;

            if ($pack?->key) {
                return ['key' => (string) $pack->key, 'source' => 'latest_workspace_installation'];
            }
        }

        return ['key' => null, 'source' => 'missing'];
    }

    /**
     * @return array<string, float>
     */
    private function weights(?string $packKey, Workspace $workspace, ?ClientSite $clientSite): array
    {
        $weights = $this->numericWeights((array) config('argusly.intelligence_score_v2.weights', []));

        if ($packKey !== null) {
            $model = MarketPackScoringModel::query()
                ->where('key', $this->modelKey())
                ->whereHas('marketPack', fn (Builder $query): Builder => $query->where('key', $packKey))
                ->first();

            $weights = $this->applyWeights($weights, (array) ($model?->weights_json ?? []));

            $installation = MarketPackInstallation::query()
                ->where('workspace_id', $workspace->id)
                ->when($clientSite, fn (Builder $query): Builder => $query->where(function (Builder $query) use ($clientSite): void {
                    $query->whereNull('client_site_id')->orWhere('client_site_id', $clientSite->id);
                }))
                ->where('status', MarketPackInstallation::STATUS_ACTIVE)
                ->whereHas('marketPack', fn (Builder $query): Builder => $query->where('key', $packKey))
                ->first();

            $overrideWeights = data_get($installation?->scoring_overrides_json, $this->modelKey().'.weights')
                ?? data_get($installation?->scoring_overrides_json, 'weights.'.$this->modelKey())
                ?? data_get($installation?->scoring_overrides_json, 'weights');

            if (is_array($overrideWeights)) {
                $weights = $this->applyWeights($weights, $overrideWeights);
            }
        }

        return $this->normalizeWeights($weights);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     * @return array<string, float>
     */
    private function applyWeights(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $weight) {
            if (array_key_exists((string) $key, $base) && is_numeric($weight)) {
                $base[(string) $key] = max(0.0, (float) $weight);
            }
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $weights
     * @return array<string, float>
     */
    private function numericWeights(array $weights): array
    {
        return collect($weights)
            ->filter(fn (mixed $weight): bool => is_numeric($weight))
            ->map(fn (mixed $weight): float => max(0.0, (float) $weight))
            ->all();
    }

    /**
     * @param  array<string, float>  $weights
     * @return array<string, float>
     */
    private function normalizeWeights(array $weights): array
    {
        $total = array_sum($weights);

        if ($total <= 0) {
            return [];
        }

        return collect($weights)
            ->map(fn (float $weight): float => round($weight / $total, 4))
            ->all();
    }

    /**
     * @return array{0:?Model,1:string}
     */
    private function scopedRecord(Builder $query, PageSnapshot $snapshot, string $timestampColumn): array
    {
        $snapshotRecord = (clone $query)
            ->where('page_snapshot_id', $snapshot->id)
            ->latest($timestampColumn)
            ->first();

        if ($snapshotRecord instanceof Model) {
            return [$snapshotRecord, 'snapshot_scoped'];
        }

        $cutoff = $this->snapshotCutoff($snapshot);
        if ($cutoff !== null) {
            $historicalRecord = (clone $query)
                ->where($timestampColumn, '<=', $cutoff)
                ->latest($timestampColumn)
                ->first();

            if ($historicalRecord instanceof Model) {
                return [$historicalRecord, 'at_or_before_snapshot_fetched_at'];
            }
        }

        if ($this->isLatestSnapshot($snapshot)) {
            $latestRecord = (clone $query)->latest($timestampColumn)->first();

            if ($latestRecord instanceof Model) {
                return [$latestRecord, 'latest_page_level_current_snapshot'];
            }
        }

        return [null, 'missing'];
    }

    /**
     * @return array{0:Collection<int, Model>,1:string}
     */
    private function scopedRows(Builder $query, PageSnapshot $snapshot, string $timestampColumn): array
    {
        $snapshotRows = (clone $query)
            ->where('page_snapshot_id', $snapshot->id)
            ->get();

        if ($snapshotRows->isNotEmpty()) {
            return [$snapshotRows, 'snapshot_scoped'];
        }

        $cutoff = $this->snapshotCutoff($snapshot);
        if ($cutoff !== null) {
            $historicalRows = (clone $query)
                ->where($timestampColumn, '<=', $cutoff)
                ->get();

            if ($historicalRows->isNotEmpty()) {
                return [$historicalRows, 'at_or_before_snapshot_fetched_at'];
            }
        }

        if ($this->isLatestSnapshot($snapshot)) {
            $latestRows = (clone $query)->get();

            if ($latestRows->isNotEmpty()) {
                return [$latestRows, 'latest_page_level_current_snapshot'];
            }
        }

        return [collect(), 'missing'];
    }

    private function snapshotCutoff(PageSnapshot $snapshot): ?Carbon
    {
        return $snapshot->fetched_at ?: $snapshot->created_at;
    }

    private function isLatestSnapshot(PageSnapshot $snapshot): bool
    {
        $latestId = PageSnapshot::query()
            ->where('monitored_page_id', $snapshot->monitored_page_id)
            ->orderByDesc('snapshot_number')
            ->orderByDesc('fetched_at')
            ->value('id');

        return (string) $latestId === (string) $snapshot->id;
    }

    private function boundedConfidence(?float ...$values): float
    {
        $values = collect($values)
            ->filter(fn (?float $value): bool => $value !== null)
            ->map(fn (float $value): float => $value > 1 ? $value / 100 : $value)
            ->values();

        if ($values->isEmpty()) {
            return 0.0;
        }

        return round(max(0.0, min(1.0, (float) $values->avg())), 4);
    }

    private function normalizeConfidence(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.75;
        }

        $confidence = (float) $value;

        return round(max(0.0, min(1.0, $confidence > 1 ? $confidence / 100 : $confidence)), 4);
    }

    private function clamp(float $value): float
    {
        return round(min(100, max(0, $value)), 2);
    }

    private function lookbackDays(): int
    {
        return max(1, (int) config('argusly.intelligence_score_v2.lookback_days', 30));
    }

    private function modelKey(): string
    {
        return (string) config('argusly.intelligence_score_v2.model_key', self::MODEL_KEY);
    }

    private function modelVersion(): string
    {
        return (string) config('argusly.intelligence_score_v2.model_version', self::MODEL_VERSION);
    }

    /**
     * @param  array<string, mixed>  $labels
     */
    private function label(string $key, array $labels): string
    {
        return (string) ($labels[$key] ?? Str::headline(str_replace('_', ' ', $key)));
    }

    private function immutable(CarbonInterface|string $date): CarbonImmutable
    {
        if ($date instanceof CarbonInterface) {
            return CarbonImmutable::parse($date->toDateTimeString(), $date->getTimezone());
        }

        return CarbonImmutable::parse($date);
    }
}
