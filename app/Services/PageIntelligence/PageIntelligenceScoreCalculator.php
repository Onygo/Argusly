<?php

namespace App\Services\PageIntelligence;

use App\Models\MarketPackInstallation;
use App\Models\MarketPackScoringModel;
use App\Models\PageCampaignMatch;
use App\Models\PageCompetitorMatch;
use App\Models\PageContentExtraction;
use App\Models\PageEntity;
use App\Models\PageGeoObservation;
use App\Models\PageMarketPackMatch;
use App\Models\PagePrValue;
use App\Models\PageScore;
use App\Models\PageSentiment;
use App\Models\PageSerpObservation;
use App\Models\PageSnapshot;
use App\Models\PageTopic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PageIntelligenceScoreCalculator
{
    public const SCORE_TYPE = 'argusly_intelligence_score';
    public const MODEL_KEY = 'argusly_intelligence_score';
    public const MODEL_VERSION = 'v1';

    private const DEFAULT_WEIGHTS = [
        'pr_value' => 0.14,
        'source_authority' => 0.10,
        'sentiment' => 0.10,
        'brand_prominence' => 0.10,
        'topic_relevance' => 0.10,
        'competitor_pressure' => 0.10,
        'market_pack_relevance' => 0.08,
        'campaign_relevance' => 0.06,
        'serp_visibility' => 0.07,
        'geo_visibility' => 0.07,
        'recency' => 0.04,
        'content_depth' => 0.04,
    ];

    private const FALLBACK_POLICY = [
        'snapshot_scoped_value',
        'observation_at_or_before_snapshot_fetched_at',
        'latest_page_level_value_only_for_current_snapshot',
    ];

    public function calculate(PageSnapshot $snapshot): PageScore
    {
        $snapshot = $snapshot->loadMissing(['page.source', 'contentExtraction']);
        $page = $snapshot->page;
        $extraction = $snapshot->contentExtraction
            ?: PageContentExtraction::query()->where('page_snapshot_id', $snapshot->id)->first();
        $computedAt = now();
        $marketPack = $this->marketPackKey($snapshot);
        $weights = $this->weights($marketPack['key']);
        $components = $this->components($snapshot, $extraction, $weights);
        $componentCollection = collect($components);
        $missingInputs = $componentCollection
            ->filter(fn (array $component): bool => ! $component['available'])
            ->keys()
            ->values()
            ->all();
        $availableWeight = $componentCollection
            ->filter(fn (array $component): bool => $component['available'])
            ->sum(fn (array $component): float => (float) $component['weight']);
        $missingWeightTotal = round(max(0, 1 - $availableWeight), 4);
        $rawScore = round($componentCollection->sum(fn (array $component): float => (float) $component['weighted']), 2);
        $confidence = round(max(0, min(100, $availableWeight * 100)), 2);
        $confidenceAdjustedScore = round($rawScore * ($confidence / 100), 2);
        $previous = PageScore::query()
            ->where('monitored_page_id', $snapshot->monitored_page_id)
            ->where('score_type', self::SCORE_TYPE)
            ->where('score_version', self::MODEL_VERSION)
            ->where('page_snapshot_id', '!=', $snapshot->id)
            ->latest('computed_at')
            ->first();

        return PageScore::query()->updateOrCreate(
            [
                'page_snapshot_id' => $snapshot->id,
                'score_type' => self::SCORE_TYPE,
                'score_version' => self::MODEL_VERSION,
            ],
            [
                'organization_id' => $snapshot->organization_id,
                'workspace_id' => $snapshot->workspace_id,
                'client_site_id' => $snapshot->client_site_id,
                'monitored_page_id' => $snapshot->monitored_page_id,
                'page_content_extraction_id' => $extraction?->id,
                'score' => $confidenceAdjustedScore,
                'previous_score' => $previous?->score,
                'delta' => $previous ? round($confidenceAdjustedScore - (float) $previous->score, 2) : null,
                'calculation_method' => 'deterministic_composite',
                'model_used' => self::MODEL_KEY,
                'explanation' => 'Argusly Intelligence Score combines PR value, authority, sentiment, relevance, competitive pressure, visibility, recency and content depth.',
                'breakdown_json' => [
                    'components' => $components,
                    'weights' => $weights,
                    'fallback_policy' => self::FALLBACK_POLICY,
                    'market_pack_key_source' => $marketPack['source'],
                    'available_weight' => round($availableWeight, 4),
                    'missing_weight_total' => $missingWeightTotal,
                    'raw_score' => $rawScore,
                    'confidence' => $confidence,
                    'confidence_adjusted_score' => $confidenceAdjustedScore,
                    'weighted_total' => $rawScore,
                    'display_score' => $confidenceAdjustedScore,
                ],
                'evidence_json' => [
                    'page_snapshot_id' => $snapshot->id,
                    'snapshot_fetched_at' => $snapshot->fetched_at?->toISOString(),
                    'monitored_page_id' => $snapshot->monitored_page_id,
                    'market_pack_key' => $marketPack['key'],
                    'market_pack_key_source' => $marketPack['source'],
                    'canonical_url' => $page?->canonical_url,
                ],
                'computed_at' => $computedAt,
                'metadata_json' => [
                    'model_key' => self::MODEL_KEY,
                    'model_version' => self::MODEL_VERSION,
                    'missing_inputs' => $missingInputs,
                    'missing_weight_total' => $missingWeightTotal,
                    'raw_score' => $rawScore,
                    'confidence' => $confidence,
                    'confidence_adjusted_score' => $confidenceAdjustedScore,
                    'computed_at' => $computedAt->toISOString(),
                    'market_pack_key' => $marketPack['key'],
                    'phase' => 'page_intelligence_phase_17',
                ],
            ]
        );
    }

    /**
     * @param array<string,float> $weights
     * @return array<string,array<string,mixed>>
     */
    private function components(PageSnapshot $snapshot, ?PageContentExtraction $extraction, array $weights): array
    {
        $componentScores = [
            'pr_value' => $this->prValue($snapshot),
            'source_authority' => $this->sourceAuthority($snapshot),
            'sentiment' => $this->sentiment($snapshot),
            'brand_prominence' => $this->brandProminence($snapshot),
            'topic_relevance' => $this->topicRelevance($snapshot),
            'competitor_pressure' => $this->competitorPressure($snapshot),
            'market_pack_relevance' => $this->marketPackRelevance($snapshot),
            'campaign_relevance' => $this->campaignRelevance($snapshot),
            'serp_visibility' => $this->serpVisibility($snapshot),
            'geo_visibility' => $this->geoVisibility($snapshot),
            'recency' => $this->recency($snapshot),
            'content_depth' => $this->contentDepth($snapshot, $extraction),
        ];

        return collect($weights)
            ->mapWithKeys(function (float $weight, string $key) use ($componentScores): array {
                $component = $componentScores[$key] ?? ['score' => null, 'available' => false, 'source' => 'not_configured', 'fallback_source' => 'missing'];
                $score = $component['score'];
                $weighted = $score === null ? 0.0 : round($this->clamp((float) $score) * $weight, 4);

                return [$key => array_merge($component, [
                    'score' => $score === null ? null : $this->clamp((float) $score),
                    'weight' => round($weight, 4),
                    'weighted' => $weighted,
                ])];
            })
            ->all();
    }

    /**
     * @return array<string,float>
     */
    private function weights(?string $packKey): array
    {
        $weights = self::DEFAULT_WEIGHTS;

        if ($packKey !== null) {
            $model = MarketPackScoringModel::query()
                ->where('key', self::MODEL_KEY)
                ->whereHas('marketPack', fn ($query) => $query->where('key', $packKey))
                ->first();

            foreach ((array) ($model?->weights_json ?? []) as $key => $weight) {
                if (array_key_exists($key, $weights) && is_numeric($weight)) {
                    $weights[$key] = max(0, (float) $weight);
                }
            }
        }

        return $this->normalizeWeights($weights);
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

        [$matches, $source] = $this->scopedRows(
            PageMarketPackMatch::query()->where('monitored_page_id', $snapshot->monitored_page_id),
            $snapshot,
            'observed_at'
        );
        $match = $matches->sortByDesc(fn (PageMarketPackMatch $match): float => (float) $match->match_score)->first();

        if ($match?->market_pack_key) {
            return [
                'key' => (string) $match->market_pack_key,
                'source' => $source,
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

    private function prValue(PageSnapshot $snapshot): array
    {
        [$value, $source] = $this->scopedRecord(
            PagePrValue::query()->where('monitored_page_id', $snapshot->monitored_page_id),
            $snapshot,
            'calculated_at'
        );

        return [
            'score' => $value ? (float) $value->score : null,
            'available' => $value !== null,
            'source' => $value ? $value->model_key.' '.$value->model_version : 'missing',
            'fallback_source' => $source,
            ...$this->inputAudit($value, 'calculated_at'),
        ];
    }

    private function sourceAuthority(PageSnapshot $snapshot): array
    {
        $source = $snapshot->page?->source;
        $score = $source ? max((float) $source->authority_score, (int) $source->trust_level * 10) : null;

        return [
            'score' => $score,
            'available' => $source !== null,
            'source' => $source ? 'monitored_source' : 'missing',
            'fallback_source' => $source ? 'page_level_source_current' : 'missing',
            'input_id' => $source?->id ? (string) $source->id : null,
            'input_timestamp' => $source?->updated_at?->toISOString(),
        ];
    }

    private function sentiment(PageSnapshot $snapshot): array
    {
        [$sentiment, $source] = $this->scopedRecord(
            PageSentiment::query()
                ->where('monitored_page_id', $snapshot->monitored_page_id)
                ->whereIn('target_type', [PageSentiment::TARGET_BRAND, PageSentiment::TARGET_PAGE, PageSentiment::TARGET_ENTITY]),
            $snapshot,
            'analyzed_at'
        );

        if (! $sentiment) {
            return ['score' => null, 'available' => false, 'source' => 'missing', 'fallback_source' => 'missing'];
        }

        $compound = (float) $sentiment->compound_score;

        return [
            'score' => ($compound + 1) * 50,
            'available' => true,
            'source' => 'page_sentiment',
            'fallback_source' => $source,
            'label' => $sentiment->label,
            'compound_score' => round($compound, 4),
            ...$this->inputAudit($sentiment, 'analyzed_at'),
        ];
    }

    private function brandProminence(PageSnapshot $snapshot): array
    {
        [$entities, $entitySource] = $this->scopedRows(
            PageEntity::query()
                ->where('monitored_page_id', $snapshot->monitored_page_id)
                ->where('entity_type', PageEntity::TYPE_BRAND),
            $snapshot,
            'observed_at'
        );
        [$campaigns, $campaignSource] = $this->scopedRows(
            PageCampaignMatch::query()->where('monitored_page_id', $snapshot->monitored_page_id),
            $snapshot,
            'observed_at'
        );
        $entityScore = (float) $entities->max('prominence_score');
        $matchScore = (float) $campaigns->max('match_score');
        $score = max($entityScore, $matchScore);
        $inputs = $this->collectionAudit($entityScore >= $matchScore ? $entities : $campaigns, 'observed_at');

        return [
            'score' => $score > 0 ? $score : null,
            'available' => $score > 0,
            'source' => $score > 0 ? 'brand_or_campaign_match' : 'missing',
            'fallback_source' => $score > 0 ? ($entityScore >= $matchScore ? $entitySource : $campaignSource) : 'missing',
            ...$inputs,
        ];
    }

    private function topicRelevance(PageSnapshot $snapshot): array
    {
        [$topics, $source] = $this->scopedRows(
            PageTopic::query()->where('monitored_page_id', $snapshot->monitored_page_id),
            $snapshot,
            'classified_at'
        );
        $score = $topics->isEmpty() ? null : $topics->avg(fn (PageTopic $topic): float => (float) $topic->confidence_score);

        return [
            'score' => $score === null ? null : (float) $score,
            'available' => $score !== null,
            'source' => $score !== null ? 'page_topics' : 'missing',
            'fallback_source' => $score !== null ? $source : 'missing',
            ...$this->collectionAudit($topics, 'classified_at'),
        ];
    }

    private function competitorPressure(PageSnapshot $snapshot): array
    {
        [$matches, $matchSource] = $this->scopedRows(
            PageCompetitorMatch::query()->where('monitored_page_id', $snapshot->monitored_page_id),
            $snapshot,
            'observed_at'
        );
        [$entities, $entitySource] = $this->scopedRows(
            PageEntity::query()
                ->where('monitored_page_id', $snapshot->monitored_page_id)
                ->where('entity_type', PageEntity::TYPE_COMPETITOR),
            $snapshot,
            'observed_at'
        );
        $matchScore = (float) $matches->max('match_score');
        $mentions = (int) $entities->sum('mention_count');
        $score = max($matchScore, min(100, $mentions * 20));
        $inputs = $this->collectionAudit($matchScore >= min(100, $mentions * 20) ? $matches : $entities, 'observed_at');

        return [
            'score' => $score > 0 ? $score : null,
            'available' => $score > 0,
            'source' => $score > 0 ? 'competitor_matches' : 'missing',
            'fallback_source' => $score > 0 ? ($matchScore >= min(100, $mentions * 20) ? $matchSource : $entitySource) : 'missing',
            'competitor_mentions' => $mentions,
            ...$inputs,
        ];
    }

    private function marketPackRelevance(PageSnapshot $snapshot): array
    {
        [$matches, $matchSource] = $this->scopedRows(
            PageMarketPackMatch::query()->where('monitored_page_id', $snapshot->monitored_page_id),
            $snapshot,
            'observed_at'
        );
        [$topics, $topicSource] = $this->scopedRows(
            PageTopic::query()
                ->where('monitored_page_id', $snapshot->monitored_page_id)
                ->where('source_type', 'market_pack'),
            $snapshot,
            'classified_at'
        );
        $matchScore = (float) $matches->max('match_score');
        $topicScore = $topics->isEmpty() ? 0.0 : (float) $topics->avg(fn (PageTopic $topic): float => (float) $topic->confidence_score);
        $score = max($matchScore, $topicScore);
        $inputs = $this->collectionAudit($matchScore >= $topicScore ? $matches : $topics, $matchScore >= $topicScore ? 'observed_at' : 'classified_at');

        return [
            'score' => $score > 0 ? $score : null,
            'available' => $score > 0,
            'source' => $score > 0 ? 'market_pack_matches' : 'missing',
            'fallback_source' => $score > 0 ? ($matchScore >= $topicScore ? $matchSource : $topicSource) : 'missing',
            ...$inputs,
        ];
    }

    private function campaignRelevance(PageSnapshot $snapshot): array
    {
        [$matches, $source] = $this->scopedRows(
            PageCampaignMatch::query()->where('monitored_page_id', $snapshot->monitored_page_id),
            $snapshot,
            'observed_at'
        );
        $score = $matches->isEmpty() ? null : (float) $matches->max('match_score');

        return [
            'score' => $score,
            'available' => $score !== null,
            'source' => $score !== null ? 'campaign_matches' : 'missing',
            'fallback_source' => $score !== null ? $source : 'missing',
            ...$this->collectionAudit($matches, 'observed_at'),
        ];
    }

    private function serpVisibility(PageSnapshot $snapshot): array
    {
        [$observation, $source] = $this->scopedRecord(
            PageSerpObservation::query()->where('monitored_page_id', $snapshot->monitored_page_id),
            $snapshot,
            'observed_at'
        );
        $score = $observation?->visibility_score;

        return [
            'score' => $score === null ? null : (float) $score,
            'available' => $score !== null,
            'source' => $score !== null ? 'serp_observations' : 'missing',
            'fallback_source' => $score !== null ? $source : 'missing',
            ...$this->inputAudit($observation, 'observed_at'),
        ];
    }

    private function geoVisibility(PageSnapshot $snapshot): array
    {
        [$observation, $source] = $this->scopedRecord(
            PageGeoObservation::query()->where('monitored_page_id', $snapshot->monitored_page_id),
            $snapshot,
            'observed_at'
        );
        $score = $observation?->geo_visibility_score;

        return [
            'score' => $score === null ? null : (float) $score,
            'available' => $score !== null,
            'source' => $score !== null ? 'geo_observations' : 'missing',
            'fallback_source' => $score !== null ? $source : 'missing',
            ...$this->inputAudit($observation, 'observed_at'),
        ];
    }

    private function recency(PageSnapshot $snapshot): array
    {
        $asOf = $snapshot->fetched_at ?: $snapshot->created_at ?: now();
        $date = $snapshot->page?->published_at_current ?: $snapshot->page?->first_seen_at ?: $snapshot->fetched_at;

        if (! $date) {
            return ['score' => null, 'available' => false, 'source' => 'missing', 'fallback_source' => 'missing'];
        }

        $days = Carbon::parse($date)->diffInDays(Carbon::parse($asOf));

        return [
            'score' => match (true) {
                $days <= 7 => 100,
                $days <= 30 => 85,
                $days <= 90 => 65,
                $days <= 365 => 45,
                default => 25,
            },
            'available' => true,
            'source' => 'page_dates',
            'fallback_source' => 'snapshot_time_bounded',
            'input_timestamp' => Carbon::parse($date)->toISOString(),
            'as_of_timestamp' => Carbon::parse($asOf)->toISOString(),
            'age_days' => $days,
        ];
    }

    private function contentDepth(PageSnapshot $snapshot, ?PageContentExtraction $extraction): array
    {
        if (! $extraction) {
            return ['score' => null, 'available' => false, 'source' => 'missing', 'fallback_source' => 'missing'];
        }

        $score = $extraction->content_depth_score !== null
            ? (float) $extraction->content_depth_score
            : min(100, ((int) $extraction->word_count) / 12);

        return [
            'score' => $score,
            'available' => true,
            'source' => 'content_extraction',
            'fallback_source' => (string) $extraction->page_snapshot_id === (string) $snapshot->id ? 'snapshot_scoped' : 'latest_page_level',
            'input_id' => (string) $extraction->id,
            'input_timestamp' => $extraction->updated_at?->toISOString(),
            'page_snapshot_id' => (string) $extraction->page_snapshot_id,
            'word_count' => (int) $extraction->word_count,
        ];
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
            $latestRecord = (clone $query)
                ->latest($timestampColumn)
                ->first();

            if ($latestRecord instanceof Model) {
                return [$latestRecord, 'latest_page_level_current_snapshot'];
            }
        }

        return [null, 'missing'];
    }

    /**
     * @return array{0:Collection<int,Model>,1:string}
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

    /**
     * @return array<string,mixed>
     */
    private function inputAudit(?Model $model, string $timestampColumn): array
    {
        if (! $model) {
            return [];
        }

        $timestamp = $model->{$timestampColumn};

        return [
            'input_id' => (string) $model->getKey(),
            'input_timestamp' => $timestamp instanceof Carbon ? $timestamp->toISOString() : ($timestamp ? (string) $timestamp : null),
            'page_snapshot_id' => $model->page_snapshot_id ? (string) $model->page_snapshot_id : null,
        ];
    }

    /**
     * @param Collection<int,Model> $models
     * @return array<string,mixed>
     */
    private function collectionAudit(Collection $models, string $timestampColumn): array
    {
        return [
            'input_ids' => $models->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(),
            'input_timestamps' => $models
                ->map(fn (Model $model): ?string => $model->{$timestampColumn} instanceof Carbon ? $model->{$timestampColumn}->toISOString() : ($model->{$timestampColumn} ? (string) $model->{$timestampColumn} : null))
                ->filter()
                ->values()
                ->all(),
            'page_snapshot_ids' => $models->pluck('page_snapshot_id')->filter()->map(fn ($id): string => (string) $id)->unique()->values()->all(),
        ];
    }

    /**
     * @param array<string,float> $weights
     * @return array<string,float>
     */
    private function normalizeWeights(array $weights): array
    {
        $total = array_sum($weights);
        if ($total <= 0) {
            return self::DEFAULT_WEIGHTS;
        }

        return collect($weights)
            ->map(fn (float $weight): float => round($weight / $total, 4))
            ->all();
    }

    private function clamp(float $value): float
    {
        return round(min(100, max(0, $value)), 2);
    }
}
