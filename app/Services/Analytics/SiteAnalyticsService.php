<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsSite;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SiteAnalyticsService
{
    private const PAGEVIEW_EVENT_TYPES = ['page_view', 'pageview'];
    private const ENGAGED_EVENT_TYPES = ['engaged'];
    private const READ_THROUGH_EVENT_TYPES = ['read_through'];
    public const SCOPE_PUBLISHLAYER_CONTENT = 'publishlayer_content';
    public const SCOPE_ALL = 'all';
    public const SCOPE_OTHER_PAGE = 'other_page';

    /**
     * @return array{pageviews_7d:int,pageviews_30d:int,article_daily:Collection<int,object>}
     */
    public function getQuickStats(?AnalyticsSite $analyticsSite, string $scope = self::SCOPE_PUBLISHLAYER_CONTENT): array
    {
        $scope = $this->normalizeScope($scope);

        $empty = [
            'pageviews_7d' => 0,
            'pageviews_30d' => 0,
            'article_daily' => collect(),
        ];

        if (! $analyticsSite) {
            return $empty;
        }

        $baseQuery = $this->pageviewBaseQuery($analyticsSite->id, $scope);

        $pageviews7d = (clone $baseQuery)
            ->where('event_time', '>=', now()->subDays(7)->startOfDay())
            ->count();

        $pageviews30d = (clone $baseQuery)
            ->where('event_time', '>=', now()->subDays(30)->startOfDay())
            ->count();

        $articleDaily = $this->pageKeyedEventQuery($analyticsSite->id, $scope, self::PAGEVIEW_EVENT_TYPES, includeMetadata: false)
            ->whereNotNull('page_key')
            ->where('event_time', '>=', now()->subDays(30)->startOfDay())
            ->select('page_key')
            ->selectRaw('DATE(event_time) as day')
            ->selectRaw('MAX(resolved_url) as canonical_url')
            ->selectRaw('COUNT(*) as pageviews')
            ->groupBy('day', 'page_key')
            ->orderByDesc('day')
            ->orderByDesc('pageviews')
            ->limit(100)
            ->get();

        return [
            'pageviews_7d' => $pageviews7d,
            'pageviews_30d' => $pageviews30d,
            'article_daily' => $articleDaily,
        ];
    }

    /**
     * @return array{
     *   days:int,
     *   start_date:Carbon,
     *   end_date:Carbon,
     *   trending:Collection<int,array{
     *     page_key:string,
     *     path:string,
     *     title:string,
     *     article_id:?string,
     *     views:int,
     *     uniques:int,
     *     engaged:int,
     *     read_through:int,
     *     avg_scroll_depth:float,
     *     max_scroll_depth:int,
     *     avg_read_time:float,
     *     median_read_time:float,
     *     roi_score:float,
     *     ai_visibility_score:float,
     *     ai_seo_score:float,
     *     llm_citations:int,
     *     is_ai_cited:bool,
     *     engagement_rate:float|int,
     *     last_seen:Carbon
     *   }>,
     *   summary:array{total_views:int,total_uniques:int,total_engaged:int,total_read_through:int,unique_pages:int,engagement_rate:float|int},
     *   daily_trend:Collection<int,array{date:string,views:int,uniques:int}>
     * }
     */
    public function getLearningsOverview(
        ?AnalyticsSite $analyticsSite,
        int $days,
        string $scope = self::SCOPE_PUBLISHLAYER_CONTENT
    ): array {
        $scope = $this->normalizeScope($scope);
        $days = min(max($days, 1), 90);
        $startDate = now()->subDays($days)->startOfDay();
        $endDate = now()->endOfDay();

        $empty = [
            'days' => $days,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'trending' => collect(),
            'summary' => [
                'total_views' => 0,
                'total_uniques' => 0,
                'total_engaged' => 0,
                'total_read_through' => 0,
                'unique_pages' => 0,
                'engagement_rate' => 0,
            ],
            'daily_trend' => collect(),
        ];

        if (! $analyticsSite) {
            return $empty;
        }

        $baseQuery = $this->pageviewBaseQuery($analyticsSite->id, $scope)
            ->whereBetween('event_time', [$startDate, $endDate]);
        $pageviewPages = $this->pageKeyedEventQuery($analyticsSite->id, $scope, self::PAGEVIEW_EVENT_TYPES)
            ->whereBetween('event_time', [$startDate, $endDate]);

        $trending = (clone $pageviewPages)
            ->whereNotNull('page_key')
            ->select('page_key')
            ->selectRaw('MAX(resolved_url) as path')
            ->selectRaw('MAX(title) as title')
            ->selectRaw('MAX(article_id) as article_id')
            ->selectRaw('COUNT(*) as total_views')
            ->selectRaw('COUNT(DISTINCT visitor_hash) as total_uniques')
            ->selectRaw('MAX(event_time) as last_seen')
            ->groupBy('page_key')
            ->orderByDesc('total_views')
            ->limit(50)
            ->get();

        $engagedByPath = $this->pageKeyedEventQuery($analyticsSite->id, $scope, self::ENGAGED_EVENT_TYPES, includeMetadata: false)
            ->whereBetween('event_time', [$startDate, $endDate])
            ->whereNotNull('page_key')
            ->select('page_key')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('page_key')
            ->pluck('total', 'page_key');

        $readThroughByPath = $this->pageKeyedEventQuery($analyticsSite->id, $scope, self::READ_THROUGH_EVENT_TYPES, includeMetadata: false)
            ->whereBetween('event_time', [$startDate, $endDate])
            ->whereNotNull('page_key')
            ->select('page_key')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('page_key')
            ->pluck('total', 'page_key');

        $trending = $trending
            ->map(function ($row) use ($engagedByPath, $readThroughByPath): array {
                $path = (string) ($row->path ?? '/');
                $pageKey = (string) ($row->page_key ?? $path);
                $views = (int) ($row->total_views ?? 0);
                $engaged = (int) ($engagedByPath->get($pageKey, 0) ?? 0);
                $readThrough = (int) ($readThroughByPath->get($pageKey, 0) ?? 0);
                $engagementRate = $views > 0 ? (int) round(($engaged / $views) * 100) : 0;

                return [
                    'page_key' => $pageKey,
                    'path' => $path,
                    'title' => (string) ($row->title ?: $path),
                    'article_id' => $row->article_id,
                    'views' => $views,
                    'uniques' => (int) ($row->total_uniques ?? 0),
                    'engaged' => $engaged,
                    'read_through' => $readThrough,
                    'engagement_rate' => $engagementRate,
                    'avg_scroll_depth' => null,
                    'max_scroll_depth' => null,
                    'avg_read_time' => null,
                    'median_read_time' => null,
                    'roi_score' => null,
                    'ai_visibility_score' => null,
                    'ai_seo_score' => null,
                    'ai_seo_score_stale' => false,
                    'llm_citations' => 0,
                    'is_ai_cited' => false,
                    'last_seen' => Carbon::parse((string) $row->last_seen),
                ];
            });

        $trending = $this->attachAdvancedMetrics($analyticsSite->id, $trending);

        $summary = (clone $baseQuery)
            ->selectRaw('COUNT(*) as total_views')
            ->selectRaw('COUNT(DISTINCT visitor_hash) as total_uniques')
            ->selectRaw('0 as unique_pages')
            ->first();

        $uniquePages = (clone $pageviewPages)
            ->whereNotNull('page_key')
            ->distinct()
            ->count('page_key');

        $totalViews = (int) ($summary->total_views ?? 0);
        $totalEngaged = $this->eventBaseQuery($analyticsSite->id, $scope, self::ENGAGED_EVENT_TYPES)
            ->whereBetween('event_time', [$startDate, $endDate])
            ->count();
        $totalReadThrough = $this->eventBaseQuery($analyticsSite->id, $scope, self::READ_THROUGH_EVENT_TYPES)
            ->whereBetween('event_time', [$startDate, $endDate])
            ->count();
        $engagementRate = $totalViews > 0 ? (int) round(($totalEngaged / $totalViews) * 100) : 0;

        $dailyTrend = (clone $baseQuery)
            ->selectRaw('DATE(event_time) as day, COUNT(*) as views, COUNT(DISTINCT visitor_hash) as uniques')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(static fn ($row): array => [
                'date' => Carbon::parse((string) $row->day)->format('M j'),
                'views' => (int) ($row->views ?? 0),
                'uniques' => (int) ($row->uniques ?? 0),
            ]);

        return [
            'days' => $days,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'trending' => $trending,
            'summary' => [
                'total_views' => $totalViews,
                'total_uniques' => (int) ($summary->total_uniques ?? 0),
                'total_engaged' => $totalEngaged,
                'total_read_through' => $totalReadThrough,
                'unique_pages' => $uniquePages > 0 ? $uniquePages : (int) $trending->count(),
                'engagement_rate' => $engagementRate,
            ],
            'daily_trend' => $dailyTrend,
        ];
    }

    public function normalizeScope(string $scope): string
    {
        $scope = strtolower(trim($scope));

        return match ($scope) {
            self::SCOPE_ALL,
            self::SCOPE_OTHER_PAGE => $scope,
            default => self::SCOPE_PUBLISHLAYER_CONTENT,
        };
    }

    private function pageviewBaseQuery(string $analyticsSiteId, string $scope): Builder
    {
        return $this->eventBaseQuery($analyticsSiteId, $scope, self::PAGEVIEW_EVENT_TYPES);
    }

    /**
     * @param  array<int, string>  $eventTypes
     */
    private function eventBaseQuery(string $analyticsSiteId, string $scope, array $eventTypes): Builder
    {
        $query = DB::table('analytics_events')
            ->where('analytics_site_id', $analyticsSiteId)
            ->whereIn('event_type', $eventTypes);

        return match ($scope) {
            self::SCOPE_ALL => $query,
            self::SCOPE_OTHER_PAGE => $query->where(function (Builder $builder): void {
                $builder->where('page_type', self::SCOPE_OTHER_PAGE)
                    ->orWhere(function (Builder $legacy): void {
                        $legacy->whereNull('page_type')->whereNull('content_id');
                    });
            }),
            default => $query->where(function (Builder $builder): void {
                $builder->where('page_type', self::SCOPE_PUBLISHLAYER_CONTENT)
                    ->orWhere(function (Builder $legacy): void {
                        $legacy->whereNull('page_type')->whereNotNull('content_id');
                    });
            }),
        };
    }

    /**
     * @param  array<int, string>  $eventTypes
     */
    private function pageKeyedEventQuery(
        string $analyticsSiteId,
        string $scope,
        array $eventTypes,
        bool $includeMetadata = true
    ): Builder {
        $baseQuery = $this->eventBaseQuery($analyticsSiteId, $scope, $eventTypes)
            ->selectRaw($this->normalizedPageKeyExpression() . ' as page_key')
            ->selectRaw('COALESCE(canonical_url, url, path) as resolved_url')
            ->addSelect('event_time');

        if ($includeMetadata) {
            $baseQuery->addSelect(['title', 'article_id', 'visitor_hash']);
        }

        return DB::query()->fromSub($baseQuery, 'analytics_event_pages');
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $trending
     * @return Collection<int,array<string,mixed>>
     */
    private function attachAdvancedMetrics(string $analyticsSiteId, Collection $trending): Collection
    {
        if ($trending->isEmpty()) {
            return $trending;
        }

        $keys = $trending
            ->pluck('page_key')
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(fn ($value): string => trim((string) $value))
            ->unique()
            ->values()
            ->all();

        if ($keys === []) {
            return $trending;
        }

        $contentMetrics = collect();
        if (Schema::hasTable('content_metrics')) {
            $contentMetrics = DB::table('content_metrics')
                ->where('analytics_site_id', $analyticsSiteId)
                ->whereIn('url_key', $keys)
                ->select([
                    'url',
                    'url_key',
                    'avg_scroll_depth',
                    'max_scroll_depth',
                    'avg_read_time',
                    'median_read_time',
                    'roi_score',
                    'updated_at',
                ])
                ->get()
                ->keyBy('url_key');
        }

        $liveScrollMetrics = collect();
        if (Schema::hasTable('page_scroll_events')) {
            $scrollBySession = DB::table('page_scroll_events')
                ->where('analytics_site_id', $analyticsSiteId)
                ->whereIn('url_key', $keys)
                ->selectRaw('url_key, session_id, MAX(depth) as session_max_depth')
                ->groupBy('url_key', 'session_id');

            $liveScrollMetrics = DB::query()
                ->fromSub($scrollBySession, 'session_depth')
                ->selectRaw('url_key, AVG(session_max_depth) as avg_depth, MAX(session_max_depth) as max_depth')
                ->groupBy('url_key')
                ->get()
                ->keyBy('url_key');
        }

        $liveReadMetrics = collect();
        if (Schema::hasTable('page_read_sessions')) {
            $readRows = DB::table('page_read_sessions')
                ->where('analytics_site_id', $analyticsSiteId)
                ->whereIn('url_key', $keys)
                ->select(['url_key', 'read_seconds'])
                ->get();

            $readStats = [];
            foreach ($readRows as $row) {
                $urlKey = trim((string) ($row->url_key ?? ''));
                if ($urlKey === '') {
                    continue;
                }

                if (! isset($readStats[$urlKey])) {
                    $readStats[$urlKey] = [];
                }

                $readStats[$urlKey][] = max(0, (int) ($row->read_seconds ?? 0));
            }

            foreach ($readStats as $urlKey => $seconds) {
                sort($seconds);
                $liveReadMetrics->put($urlKey, [
                    'avg' => round(array_sum($seconds) / max(count($seconds), 1), 2),
                    'median' => $this->median($seconds),
                ]);
            }
        }

        $aiMetrics = collect();
        if (Schema::hasTable('content_ai_visibility')) {
            $aiMetrics = DB::table('content_ai_visibility')
                ->where('analytics_site_id', $analyticsSiteId)
                ->whereIn('url_key', $keys)
                ->select(['url_key', 'llm_citations', 'ai_visibility_score', 'updated_at'])
                ->get()
                ->keyBy('url_key');
        }

        $aiSeoScoresByKey = collect();
        $aiSeoScoresByUrl = collect();
        if (Schema::hasTable('content_ai_seo_scores')) {
            $hasSiteAndKeyColumns = Schema::hasColumn('content_ai_seo_scores', 'analytics_site_id')
                && Schema::hasColumn('content_ai_seo_scores', 'url_key');

            if ($hasSiteAndKeyColumns) {
                $aiSeoScoresByKey = DB::table('content_ai_seo_scores')
                    ->where('analytics_site_id', $analyticsSiteId)
                    ->whereIn('url_key', $keys)
                    ->select(['url', 'url_key', 'ai_seo_score', 'calculated_at', 'content_metrics_updated_at', 'ai_visibility_updated_at'])
                    ->get()
                    ->keyBy('url_key');
            }

            $urls = $contentMetrics
                ->pluck('url')
                ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
                ->map(fn ($value): string => trim((string) $value))
                ->unique()
                ->values()
                ->all();

            if ($urls !== []) {
                $aiSeoScoresByUrl = DB::table('content_ai_seo_scores')
                    ->whereIn('url', $urls)
                    ->select(['url', 'ai_seo_score', 'calculated_at', 'content_metrics_updated_at', 'ai_visibility_updated_at'])
                    ->get()
                    ->keyBy('url');
            }
        }

        return $trending->map(function (array $row) use ($contentMetrics, $liveScrollMetrics, $liveReadMetrics, $aiMetrics, $aiSeoScoresByKey, $aiSeoScoresByUrl): array {
            $pageKey = trim((string) ($row['page_key'] ?? ''));
            $contentMetric = $contentMetrics->get($pageKey);
            $liveScroll = $liveScrollMetrics->get($pageKey);
            /** @var array{avg:float,median:float}|null $liveRead */
            $liveRead = $liveReadMetrics->get($pageKey);
            $aiMetric = $aiMetrics->get($pageKey);
            $resolvedUrl = trim((string) ($contentMetric->url ?? ''));
            $aiSeo = $aiSeoScoresByKey->get($pageKey);
            if (! $aiSeo && $resolvedUrl !== '') {
                $aiSeo = $aiSeoScoresByUrl->get($resolvedUrl);
            }

            if ($liveScroll) {
                $row['avg_scroll_depth'] = round((float) ($liveScroll->avg_depth ?? 0), 1);
                $row['max_scroll_depth'] = (int) ($liveScroll->max_depth ?? 0);
            } elseif (
                $contentMetric
                && ((float) ($contentMetric->avg_scroll_depth ?? 0) > 0 || (int) ($contentMetric->max_scroll_depth ?? 0) > 0)
            ) {
                $row['avg_scroll_depth'] = round((float) ($contentMetric->avg_scroll_depth ?? 0), 1);
                $row['max_scroll_depth'] = (int) ($contentMetric->max_scroll_depth ?? 0);
            } else {
                $row['avg_scroll_depth'] = null;
                $row['max_scroll_depth'] = null;
            }

            if ($liveRead !== null) {
                $row['avg_read_time'] = round((float) ($liveRead['avg'] ?? 0), 1);
                $row['median_read_time'] = round((float) ($liveRead['median'] ?? 0), 1);
            } elseif (
                $contentMetric
                && ((float) ($contentMetric->avg_read_time ?? 0) > 0 || (float) ($contentMetric->median_read_time ?? 0) > 0)
            ) {
                $row['avg_read_time'] = round((float) ($contentMetric->avg_read_time ?? 0), 1);
                $row['median_read_time'] = round((float) ($contentMetric->median_read_time ?? 0), 1);
            } else {
                $row['avg_read_time'] = null;
                $row['median_read_time'] = null;
            }

            $row['roi_score'] = $contentMetric ? round((float) ($contentMetric->roi_score ?? 0), 1) : null;
            $row['ai_visibility_score'] = $aiMetric ? round((float) ($aiMetric->ai_visibility_score ?? 0), 1) : null;
            $row['ai_seo_score_stale'] = $this->isAiSeoScoreStale($aiSeo, $contentMetric, $aiMetric);
            $row['ai_seo_score'] = ($aiSeo && ! $row['ai_seo_score_stale'])
                ? round((float) ($aiSeo->ai_seo_score ?? 0), 1)
                : null;
            $row['llm_citations'] = $aiMetric ? (int) ($aiMetric->llm_citations ?? 0) : 0;
            $row['is_ai_cited'] = $row['llm_citations'] > 0;

            return $row;
        });
    }

    /**
     * @param  array<int,int>  $values
     */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return (float) $values[$middle];
        }

        return round(((float) $values[$middle - 1] + (float) $values[$middle]) / 2, 2);
    }

    private function normalizedPageKeyExpression(): string
    {
        $normalizedPath = "CASE
            WHEN path IS NULL OR TRIM(path) = '' THEN '/'
            ELSE LOWER(path)
        END";

        $concatHostPath = DB::connection()->getDriverName() === 'sqlite'
            ? "LOWER(host) || {$normalizedPath}"
            : "CONCAT(LOWER(host), {$normalizedPath})";

        $hostWithPath = "CASE
            WHEN host IS NULL OR TRIM(host) = '' THEN NULL
            ELSE {$concatHostPath}
        END";

        return "COALESCE(NULLIF(url_key, ''), NULLIF({$hostWithPath}, ''), NULLIF({$normalizedPath}, ''))";
    }

    private function isAiSeoScoreStale(mixed $scoreRow, mixed $contentMetricRow, mixed $visibilityRow): bool
    {
        if (! $scoreRow) {
            return false;
        }

        $calculatedAt = $this->parseTimestamp(data_get($scoreRow, 'calculated_at'));
        if (! $calculatedAt) {
            return true;
        }

        $contentUpdatedAt = $this->latestTimestamp([
            data_get($scoreRow, 'content_metrics_updated_at'),
            data_get($contentMetricRow, 'updated_at'),
        ]);
        if ($contentUpdatedAt && $contentUpdatedAt->gt($calculatedAt)) {
            return true;
        }

        $visibilityUpdatedAt = $this->latestTimestamp([
            data_get($scoreRow, 'ai_visibility_updated_at'),
            data_get($visibilityRow, 'updated_at'),
        ]);

        return $visibilityUpdatedAt ? $visibilityUpdatedAt->gt($calculatedAt) : false;
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int,mixed>  $values
     */
    private function latestTimestamp(array $values): ?Carbon
    {
        $latest = null;

        foreach ($values as $value) {
            $parsed = $this->parseTimestamp($value);
            if (! $parsed) {
                continue;
            }

            if (! $latest || $parsed->gt($latest)) {
                $latest = $parsed;
            }
        }

        return $latest;
    }
}
