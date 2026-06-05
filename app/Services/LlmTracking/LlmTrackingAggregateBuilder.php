<?php

namespace App\Services\LlmTracking;

use App\Models\LlmTrackingAggregate;
use App\Models\LlmTrackingQueryRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class LlmTrackingAggregateBuilder
{
    public function build(?CarbonImmutable $fromDate = null): int
    {
        $grouped = [];

        LlmTrackingQueryRun::query()
            ->with(['trackingQuery:id,client_site_id,locale'])
            ->where('status', 'succeeded')
            ->where(function ($query): void {
                $query->where('is_cached', false)->orWhereNull('is_cached');
            })
            ->when($fromDate, fn ($query) => $query->whereDate('run_at', '>=', $fromDate?->toDateString()))
            ->orderBy('id')
            ->chunkById(200, function (Collection $runs) use (&$grouped): void {
                foreach ($runs as $run) {
                    if (! $run->trackingQuery || ! $run->run_at) {
                        continue;
                    }

                    $query = $run->trackingQuery;
                    $siteId = (string) ($query->client_site_id ?? '');
                    if ($siteId === '') {
                        continue;
                    }

                    $model = trim((string) ($run->model ?? ''));
                    $provider = trim((string) ($run->provider ?? ''));
                    $locale = trim((string) ($query->locale ?: 'en')) ?: 'en';

                    foreach (['day', 'week', 'month'] as $period) {
                        $periodStart = $this->periodStart($run->run_at->toImmutable(), $period)->toDateString();
                        $key = implode('|', [
                            (string) $run->llm_tracking_query_id,
                            $period,
                            $periodStart,
                            $provider,
                            $model,
                            $locale,
                        ]);

                        if (! isset($grouped[$key])) {
                            $grouped[$key] = [
                                'site_id' => $siteId,
                                'query_id' => (int) $run->llm_tracking_query_id,
                                'period' => $period,
                                'period_start' => $periodStart,
                                'provider' => $provider,
                                'model' => $model,
                                'locale' => $locale,
                                'share_brand_values' => [],
                                'ai_visibility_scores' => [],
                                'presence_scores' => [],
                                'position_scores' => [],
                                'citation_scores' => [],
                                'context_scores' => [],
                                'sentiment_scores' => [],
                                'competitor_share_scores' => [],
                                'competitive_scores' => [],
                                'owned_visibility_scores' => [],
                                'earned_visibility_scores' => [],
                                'competitor_pressure_scores' => [],
                                'citation_diversity_scores' => [],
                                'model_confidence_scores' => [],
                                'real_world_gap_scores' => [],
                                'citation_counts' => [
                                    'brand' => ['first' => 0, 'middle' => 0, 'last' => 0, 'none' => 0],
                                    'url' => ['first' => 0, 'middle' => 0, 'last' => 0, 'none' => 0],
                                ],
                                'source_types' => [],
                                'total_mentions' => [],
                                'competitor_mentions' => [],
                                'brand_query_presence_count' => 0,
                                'citation_present_count' => 0,
                                'positive_context_count' => 0,
                                'missing_visibility_count' => 0,
                                'run_count' => 0,
                            ];
                        }

                        $grouped[$key]['run_count']++;

                        $shareBrand = data_get($run->share_of_voice_snapshot, 'share_brand');
                        if (is_numeric($shareBrand)) {
                            $grouped[$key]['share_brand_values'][] = (float) $shareBrand;
                        }

                        foreach ([
                            'ai_visibility_score' => 'ai_visibility_scores',
                            'presence_score' => 'presence_scores',
                            'position_score' => 'position_scores',
                            'citation_score' => 'citation_scores',
                            'context_score' => 'context_scores',
                            'sentiment_score' => 'sentiment_scores',
                            'competitor_share_score' => 'competitor_share_scores',
                            'competitive_score' => 'competitive_scores',
                            'owned_visibility_score' => 'owned_visibility_scores',
                            'earned_visibility_score' => 'earned_visibility_scores',
                            'competitor_pressure_score' => 'competitor_pressure_scores',
                            'citation_diversity_score' => 'citation_diversity_scores',
                            'model_confidence_score' => 'model_confidence_scores',
                            'real_world_gap_score' => 'real_world_gap_scores',
                        ] as $sourceKey => $targetKey) {
                            $value = $run->{$sourceKey};
                            if (is_numeric($value)) {
                                $grouped[$key][$targetKey][] = (float) $value;
                            }
                        }

                        if ((bool) $run->brand_mentioned) {
                            $grouped[$key]['brand_query_presence_count']++;
                        } else {
                            $grouped[$key]['missing_visibility_count']++;
                        }

                        if (((float) ($run->citation_score ?? 0)) > 0.0 || (bool) $run->urls_cited) {
                            $grouped[$key]['citation_present_count']++;
                        }

                        if ((string) ($run->context_label ?? $run->sentiment_label ?? '') === 'positive') {
                            $grouped[$key]['positive_context_count']++;
                        }

                        $brandBucket = (string) (data_get($run->citation_ranking, 'brand.bucket') ?? 'none');
                        $urlBucket = (string) (data_get($run->citation_ranking, 'url.bucket') ?? 'none');

                        if (! isset($grouped[$key]['citation_counts']['brand'][$brandBucket])) {
                            $brandBucket = 'none';
                        }
                        if (! isset($grouped[$key]['citation_counts']['url'][$urlBucket])) {
                            $urlBucket = 'none';
                        }

                        $grouped[$key]['citation_counts']['brand'][$brandBucket]++;
                        $grouped[$key]['citation_counts']['url'][$urlBucket]++;

                        foreach ((array) ($run->sources ?? []) as $source) {
                            $type = (string) ($source['type'] ?? 'unknown');
                            $grouped[$key]['source_types'][$type] = ($grouped[$key]['source_types'][$type] ?? 0) + 1;
                        }

                        $mentions = array_merge(
                            (array) ($run->brand_hits ?? []),
                            (array) ($run->competitor_hits ?? []),
                        );

                        foreach ($mentions as $mention) {
                            $term = trim((string) ($mention['term'] ?? ''));
                            if ($term === '') {
                                continue;
                            }

                            $grouped[$key]['total_mentions'][$term] = ($grouped[$key]['total_mentions'][$term] ?? 0) + (int) ($mention['count'] ?? 0);
                        }

                        foreach ((array) ($run->competitor_hits ?? []) as $mention) {
                            $term = trim((string) ($mention['term'] ?? ''));
                            if ($term === '') {
                                continue;
                            }

                            $grouped[$key]['competitor_mentions'][$term] = ($grouped[$key]['competitor_mentions'][$term] ?? 0) + (int) ($mention['count'] ?? 0);
                        }
                    }
                }
            });

        $rows = [];
        foreach ($grouped as $item) {
            $avgShareBrand = $this->average($item['share_brand_values']);
            $sourceTypes = (array) ($item['source_types'] ?? []);
            arsort($sourceTypes);
            $competitorMentions = (array) ($item['competitor_mentions'] ?? []);
            arsort($competitorMentions);
            $runCount = max(1, (int) ($item['run_count'] ?? 0));

            $rows[] = [
                'site_id' => (string) $item['site_id'],
                'query_id' => (int) $item['query_id'],
                'period' => (string) $item['period'],
                'period_start' => (string) $item['period_start'],
                'provider' => (string) $item['provider'],
                'model' => (string) $item['model'],
                'locale' => (string) $item['locale'],
                'metrics' => json_encode([
                    'avg_ai_visibility_score' => $this->average((array) ($item['ai_visibility_scores'] ?? [])),
                    'presence_rate' => round(((int) ($item['brand_query_presence_count'] ?? 0)) / $runCount, 4),
                    'average_position_score' => $this->average((array) ($item['position_scores'] ?? [])),
                    'citation_rate' => round(((int) ($item['citation_present_count'] ?? 0)) / $runCount, 4),
                    'positive_context_rate' => round(((int) ($item['positive_context_count'] ?? 0)) / $runCount, 4),
                    'average_citation_score' => $this->average((array) ($item['citation_scores'] ?? [])),
                    'average_context_score' => $this->average((array) ($item['context_scores'] ?? [])),
                    'average_sentiment_score' => $this->average((array) ($item['sentiment_scores'] ?? [])),
                    'average_competitor_share_score' => $this->average((array) ($item['competitor_share_scores'] ?? [])),
                    'average_competitive_score' => $this->average((array) ($item['competitive_scores'] ?? [])),
                    'average_owned_visibility_score' => $this->average((array) ($item['owned_visibility_scores'] ?? [])),
                    'average_earned_visibility_score' => $this->average((array) ($item['earned_visibility_scores'] ?? [])),
                    'average_competitor_pressure_score' => $this->average((array) ($item['competitor_pressure_scores'] ?? [])),
                    'average_citation_diversity_score' => $this->average((array) ($item['citation_diversity_scores'] ?? [])),
                    'average_model_confidence_score' => $this->average((array) ($item['model_confidence_scores'] ?? [])),
                    'average_real_world_gap_score' => $this->average((array) ($item['real_world_gap_scores'] ?? [])),
                    'missing_visibility_count' => (int) ($item['missing_visibility_count'] ?? 0),
                    'brand_share' => $avgShareBrand,
                    'competitor_share' => $avgShareBrand !== null ? round(1 - $avgShareBrand, 4) : null,
                    'total_mentions' => (array) ($item['total_mentions'] ?? []),
                    'top_competitors' => array_slice($competitorMentions, 0, 5, true),
                    'citation_counts' => (array) ($item['citation_counts'] ?? []),
                    'top_sources_by_type' => $sourceTypes,
                    'run_count' => (int) ($item['run_count'] ?? 0),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            LlmTrackingAggregate::query()->upsert(
                $rows,
                ['query_id', 'period', 'period_start', 'provider', 'model', 'locale'],
                ['site_id', 'metrics', 'updated_at'],
            );
        }

        return count($rows);
    }

    private function periodStart(CarbonImmutable $date, string $period): CarbonImmutable
    {
        return match ($period) {
            'week' => $date->startOfWeek(),
            'month' => $date->startOfMonth(),
            default => $date->startOfDay(),
        };
    }

    /**
     * @param array<int,float|int> $values
     */
    private function average(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 4);
    }
}
