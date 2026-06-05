<?php

namespace App\Services\LlmTracking;

use App\Models\ClientSite;
use App\Models\LlmTrackingAggregate;
use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQueryRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AiAttentionDashboardBuilder
{
    /**
     * @param Collection<int,\App\Models\LlmTrackingQuery> $queries
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function buildIndexSummary(Collection $queries, array $filters = []): array
    {
        $latestRuns = $queries
            ->map(fn (LlmTrackingQuery $query) => $this->latestRunForQuery($query))
            ->filter();

        $scoredRuns = $latestRuns->filter(fn (LlmTrackingQueryRun $run): bool => is_numeric($run->ai_visibility_score));
        $queryCountWithRuns = max(1, $latestRuns->count());

        $topCompetitors = $latestRuns
            ->flatMap(fn (LlmTrackingQueryRun $run): array => (array) ($run->competitor_hits ?? []))
            ->groupBy(fn (array $hit): string => (string) ($hit['term'] ?? ''))
            ->map(function (Collection $group): array {
                return [
                    'term' => (string) data_get($group->first(), 'term', ''),
                    'mentions' => $group->sum(fn (array $hit): int => (int) ($hit['count'] ?? 0)),
                    'queries' => $group->count(),
                ];
            })
            ->filter(fn (array $entry): bool => $entry['term'] !== '')
            ->sortByDesc('mentions')
            ->take(5)
            ->values()
            ->all();

        $missingVisibility = $queries
            ->map(function (LlmTrackingQuery $query): array {
                $latestRun = $this->latestRunForQuery($query);

                return [
                    'query' => $query,
                    'latest_run' => $latestRun,
                    'is_missing' => $latestRun && ! $latestRun->brand_mentioned,
                    'suggestions' => (array) ($latestRun?->suggestions ?? []),
                ];
            })
            ->filter(fn (array $entry): bool => (bool) $entry['is_missing'])
            ->values()
            ->all();

        $ownedCitationCount = $latestRuns->filter(fn (LlmTrackingQueryRun $run): bool => collect((array) ($run->sources ?? []))->contains(function (array $source) use ($queries, $run): bool {
            $query = $queries->firstWhere('id', $run->llm_tracking_query_id);
            $targetDomain = Str::lower(trim((string) ($query?->target_domain ?? '')));
            $domain = Str::lower(trim((string) ($source['domain'] ?? '')));

            return $targetDomain !== '' && ($domain === $targetDomain || Str::endsWith($domain, '.' . $targetDomain));
        }));

        $providerBreakdown = $latestRuns
            ->groupBy(fn (LlmTrackingQueryRun $run): string => trim((string) ($run->provider ?: 'unknown')))
            ->map(fn (Collection $runs, string $provider): array => [
                'provider' => $provider,
                'run_count' => $runs->count(),
                'mention_rate' => round(($runs->where('brand_mentioned', true)->count() / max(1, $runs->count())) * 100, 1),
                'avg_visibility_score' => $runs->filter(fn (LlmTrackingQueryRun $run): bool => is_numeric($run->ai_visibility_score))->isNotEmpty()
                    ? round((float) $runs->avg('ai_visibility_score') * 100, 1)
                    : null,
                'avg_real_world_gap_score' => $runs->filter(fn (LlmTrackingQueryRun $run): bool => is_numeric($run->real_world_gap_score))->isNotEmpty()
                    ? round((float) $runs->avg('real_world_gap_score') * 100, 1)
                    : null,
            ])
            ->values()
            ->all();

        return [
            'total_queries' => $queries->count(),
            'active_queries' => $queries->where('is_active', true)->count(),
            'queries_with_runs' => $latestRuns->count(),
            'ai_visibility_score' => $scoredRuns->isNotEmpty()
                ? round((float) $scoredRuns->avg('ai_visibility_score') * 100, 1)
                : null,
            'presence_rate' => $latestRuns->isNotEmpty()
                ? round(($latestRuns->where('brand_mentioned', true)->count() / $queryCountWithRuns) * 100, 1)
                : null,
            'citation_rate' => $latestRuns->isNotEmpty()
                ? round(($latestRuns->filter(fn (LlmTrackingQueryRun $run): bool => ((float) ($run->citation_score ?? 0)) > 0 || (bool) $run->urls_cited)->count() / $queryCountWithRuns) * 100, 1)
                : null,
            'owned_visibility_score' => $latestRuns->filter(fn (LlmTrackingQueryRun $run): bool => is_numeric($run->owned_visibility_score))->isNotEmpty()
                ? round((float) $latestRuns->avg('owned_visibility_score') * 100, 1)
                : null,
            'earned_visibility_score' => $latestRuns->filter(fn (LlmTrackingQueryRun $run): bool => is_numeric($run->earned_visibility_score))->isNotEmpty()
                ? round((float) $latestRuns->avg('earned_visibility_score') * 100, 1)
                : null,
            'competitor_pressure_score' => $latestRuns->filter(fn (LlmTrackingQueryRun $run): bool => is_numeric($run->competitor_pressure_score))->isNotEmpty()
                ? round((float) $latestRuns->avg('competitor_pressure_score') * 100, 1)
                : null,
            'citation_diversity_score' => $latestRuns->filter(fn (LlmTrackingQueryRun $run): bool => is_numeric($run->citation_diversity_score))->isNotEmpty()
                ? round((float) $latestRuns->avg('citation_diversity_score') * 100, 1)
                : null,
            'real_world_gap_score' => $latestRuns->filter(fn (LlmTrackingQueryRun $run): bool => is_numeric($run->real_world_gap_score))->isNotEmpty()
                ? round((float) $latestRuns->avg('real_world_gap_score') * 100, 1)
                : null,
            'owned_citation_rate' => $latestRuns->isNotEmpty() ? round(($ownedCitationCount->count() / $queryCountWithRuns) * 100, 1) : null,
            'earned_citation_rate' => $latestRuns->isNotEmpty() ? round((($latestRuns->filter(fn (LlmTrackingQueryRun $run): bool => (bool) $run->urls_cited)->count() - $ownedCitationCount->count()) / $queryCountWithRuns) * 100, 1) : null,
            'positive_context_rate' => $latestRuns->isNotEmpty()
                ? round(($latestRuns->where('context_label', 'positive')->count() / $queryCountWithRuns) * 100, 1)
                : null,
            'average_position_score' => $scoredRuns->isNotEmpty()
                ? round((float) $scoredRuns->avg('position_score') * 100, 1)
                : null,
            'average_brand_share' => $latestRuns->isNotEmpty()
                ? round((float) $latestRuns
                    ->map(fn (LlmTrackingQueryRun $run): float => (float) data_get($run, 'share_of_voice_snapshot.share_brand', 0))
                    ->avg() * 100, 1)
                : null,
            'top_competitors' => $topCompetitors,
            'provider_breakdown' => $providerBreakdown,
            'missing_visibility' => $missingVisibility,
            'missing_visibility_count' => count($missingVisibility),
            'opportunities_total' => $latestRuns->sum(fn (LlmTrackingQueryRun $run): int => count((array) ($run->suggestions ?? []))),
            'latest_run_at' => $latestRuns
                ->map(fn (LlmTrackingQueryRun $run) => $run->run_at)
                ->filter()
                ->sortDesc()
                ->first(),
            'filters' => $filters,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function buildSiteTrend(ClientSite $site, string $period = 'week', int $limit = 8, array $filters = []): array
    {
        $aggregates = LlmTrackingAggregate::query()
            ->where('site_id', $site->id)
            ->where('period', $period)
            ->when(($filters['provider'] ?? '') !== '', fn (Builder $query) => $query->where('provider', (string) $filters['provider']))
            ->when(($filters['model'] ?? '') !== '', fn (Builder $query) => $query->where('model', (string) $filters['model']))
            ->when(($filters['locale'] ?? '') !== '', fn (Builder $query) => $query->where('locale', (string) $filters['locale']))
            ->when(($filters['query_set_id'] ?? '') !== '', function (Builder $query) use ($filters): void {
                $query->whereHas('trackingQuery', fn (Builder $builder) => $builder->where('llm_tracking_query_set_id', (int) $filters['query_set_id']));
            })
            ->orderByDesc('period_start')
            ->get();

        return $aggregates
            ->groupBy(fn (LlmTrackingAggregate $aggregate): string => (string) $aggregate->period_start?->toDateString())
            ->sortKeysDesc()
            ->take($limit)
            ->map(function (Collection $group): array {
                $metricRows = $group->map(fn (LlmTrackingAggregate $aggregate): array => (array) ($aggregate->metrics ?? []));

                return [
                    'period_start' => $group->first()?->period_start,
                    'ai_visibility_score' => $this->averageMetric($metricRows, 'avg_ai_visibility_score'),
                    'presence_rate' => $this->averageMetric($metricRows, 'presence_rate'),
                    'citation_rate' => $this->averageMetric($metricRows, 'citation_rate'),
                    'positive_context_rate' => $this->averageMetric($metricRows, 'positive_context_rate'),
                    'average_position_score' => $this->averageMetric($metricRows, 'average_position_score'),
                    'run_count' => (int) $metricRows->sum(fn (array $metrics): int => (int) ($metrics['run_count'] ?? 0)),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param Collection<int,\App\Models\LlmTrackingQuery> $queries
     * @return array<int,array<string,mixed>>
     */
    public function buildQueryPerformance(Collection $queries): array
    {
        return $queries
            ->map(function (LlmTrackingQuery $query): array {
                $runs = $query->runs instanceof Collection ? $query->runs : collect();
                $latestRun = $this->latestRunForQuery($query);
                $runCount = max(1, $runs->count());
                $topCompetitor = $runs
                    ->flatMap(fn (LlmTrackingQueryRun $run): array => (array) ($run->competitor_hits ?? []))
                    ->groupBy(fn (array $hit): string => (string) ($hit['term'] ?? ''))
                    ->map(fn (Collection $group): int => $group->sum(fn (array $hit): int => (int) ($hit['count'] ?? 0)))
                    ->sortDesc()
                    ->keys()
                    ->first();

                $history = $runs
                    ->pluck('ai_visibility_score')
                    ->filter(fn ($value): bool => is_numeric($value))
                    ->map(fn ($value): float => round((float) $value * 100, 1))
                    ->values();

                $trend = null;
                if ($history->count() >= 2) {
                    $trend = round((float) $history->first() - (float) $history->skip(1)->avg(), 1);
                }

                return [
                    'query' => $query,
                    'latest_run' => $latestRun,
                    'latest_score' => is_numeric($latestRun?->ai_visibility_score) ? round((float) $latestRun->ai_visibility_score * 100, 1) : null,
                    'average_score' => $history->isNotEmpty() ? round((float) $history->avg(), 1) : null,
                    'presence_percentage' => round(($runs->where('brand_mentioned', true)->count() / $runCount) * 100, 1),
                    'citation_percentage' => round(($runs->filter(fn (LlmTrackingQueryRun $run): bool => ((float) ($run->citation_score ?? 0)) > 0 || (bool) $run->urls_cited)->count() / $runCount) * 100, 1),
                    'top_competitor' => is_string($topCompetitor) && $topCompetitor !== '' ? $topCompetitor : null,
                    'trend' => $trend,
                ];
            })
            ->sortByDesc(fn (array $row): float => (float) ($row['latest_score'] ?? -1))
            ->values()
            ->all();
    }

    /**
     * @param Collection<int,\App\Models\LlmTrackingQuery> $queries
     * @return array<int,array<string,mixed>>
     */
    public function buildLatestResponses(Collection $queries, int $limit = 20): array
    {
        return $queries
            ->flatMap(function (LlmTrackingQuery $query): array {
                return collect($query->runs instanceof Collection ? $query->runs : [])
                    ->map(fn (LlmTrackingQueryRun $run): array => [
                        'query' => $query,
                        'run' => $run,
                    ])
                    ->all();
            })
            ->sortByDesc(fn (array $row) => data_get($row, 'run.run_at'))
            ->take($limit)
            ->values()
            ->map(function (array $row): array {
                /** @var LlmTrackingQuery $query */
                $query = $row['query'];
                /** @var LlmTrackingQueryRun $run */
                $run = $row['run'];

                return [
                    'query' => $query,
                    'run' => $run,
                    'score' => is_numeric($run->ai_visibility_score) ? round((float) $run->ai_visibility_score * 100, 1) : null,
                    'brand_mentioned' => (bool) $run->brand_mentioned,
                    'citation_present' => ((float) ($run->citation_score ?? 0)) > 0 || (bool) $run->urls_cited,
                    'context_label' => (string) ($run->context_label ?? $run->sentiment_label ?? ''),
                ];
            })
            ->all();
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,LlmTrackingQuery>
     */
    public function loadQueriesForSite(ClientSite $site, array $filters = []): Collection
    {
        $fromDate = $this->fromDateForFilters($filters);

        return LlmTrackingQuery::query()
            ->where('client_site_id', $site->id)
            ->when(($filters['query_set_id'] ?? '') !== '', fn (Builder $query) => $query->where('llm_tracking_query_set_id', (int) $filters['query_set_id']))
            ->when(($filters['locale'] ?? '') !== '', fn (Builder $query) => $query->where('locale', (string) $filters['locale']))
            ->when(($filters['brand'] ?? '') !== '', function (Builder $query) use ($filters): void {
                $needle = Str::lower(trim((string) $filters['brand']));
                $query->where(function (Builder $builder) use ($needle): void {
                    $builder->whereRaw('LOWER(COALESCE(target_brand, "")) like ?', ['%' . $needle . '%'])
                        ->orWhereRaw('LOWER(COALESCE(name, "")) like ?', ['%' . $needle . '%']);
                });
            })
            ->when(($filters['competitor'] ?? '') !== '', function (Builder $query) use ($filters): void {
                $needle = Str::lower(trim((string) $filters['competitor']));
                $query->where(function (Builder $builder) use ($filters, $needle): void {
                    $builder->whereJsonContains('competitor_terms', $filters['competitor'])
                        ->orWhereRaw('LOWER(COALESCE(query_text, "")) like ?', ['%' . $needle . '%']);
                });
            })
            ->with([
                'querySet',
                'runs' => function ($query) use ($filters, $fromDate): void {
                    $query->where('status', 'succeeded')
                        ->when($fromDate, fn (Builder $builder) => $builder->where('run_at', '>=', $fromDate))
                        ->when(($filters['provider'] ?? '') !== '', fn (Builder $builder) => $builder->where('provider', (string) $filters['provider']))
                        ->when(($filters['model'] ?? '') !== '', fn (Builder $builder) => $builder->where('model', (string) $filters['model']))
                        ->latest('run_at')
                        ->limit(50);
                },
            ])
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function fromDateForFilters(array $filters): ?CarbonImmutable
    {
        return match ((string) ($filters['period'] ?? '30d')) {
            '7d' => CarbonImmutable::now()->subDays(7),
            '90d' => CarbonImmutable::now()->subDays(90),
            default => CarbonImmutable::now()->subDays(30),
        };
    }

    private function latestRunForQuery(LlmTrackingQuery $query): ?LlmTrackingQueryRun
    {
        $runs = $query->runs instanceof Collection ? $query->runs : collect();

        /** @var LlmTrackingQueryRun|null $run */
        $run = $runs->first();

        return $run;
    }

    /**
     * @param Collection<int,array<string,mixed>> $metricRows
     */
    private function averageMetric(Collection $metricRows, string $key): ?float
    {
        $values = $metricRows
            ->pluck($key)
            ->filter(fn ($value): bool => is_numeric($value))
            ->map(fn ($value): float => (float) $value);

        if ($values->isEmpty()) {
            return null;
        }

        return round((float) $values->avg(), 4);
    }
}
