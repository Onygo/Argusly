<?php

namespace App\Services\Stats;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ContentMetricsCalculator
{
    private const PAGEVIEW_EVENT_TYPES = ['page_view', 'pageview'];
    private const DEFAULT_ESTIMATED_READ_SECONDS = 180.0;

    public function recalculate(?string $analyticsSiteId = null): int
    {
        if (! $this->tablesAvailable()) {
            return 0;
        }

        $siteQuery = DB::table('analytics_sites')->select(['id', 'client_site_id']);
        if (is_string($analyticsSiteId) && $analyticsSiteId !== '') {
            $siteQuery->where('id', $analyticsSiteId);
        }

        $totalUpserts = 0;

        foreach ($siteQuery->get() as $site) {
            $siteId = (string) ($site->id ?? '');
            $clientSiteId = (string) ($site->client_site_id ?? '');
            if ($siteId === '') {
                continue;
            }

            $rows = $this->buildRowsForSite($siteId, $clientSiteId);

            if ($rows === []) {
                DB::table('content_metrics')->where('analytics_site_id', $siteId)->delete();

                continue;
            }

            DB::table('content_metrics')->upsert(
                $rows,
                ['analytics_site_id', 'url_key'],
                [
                    'url',
                    'avg_scroll_depth',
                    'max_scroll_depth',
                    'avg_read_time',
                    'median_read_time',
                    'engaged_rate',
                    'read_through_rate',
                    'estimated_read_time',
                    'roi_score',
                    'updated_at',
                ]
            );

            $totalUpserts += count($rows);
            $activeKeys = collect($rows)->pluck('url_key')->filter()->values()->all();
            if ($activeKeys !== []) {
                DB::table('content_metrics')
                    ->where('analytics_site_id', $siteId)
                    ->whereNotIn('url_key', $activeKeys)
                    ->delete();
            }
        }

        return $totalUpserts;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function buildRowsForSite(string $analyticsSiteId, string $clientSiteId): array
    {
        $pageKeyExpression = $this->normalizedEventUrlKeyExpression();

        // Use subquery pattern to compute url_key once, avoiding MySQL ONLY_FULL_GROUP_BY issues
        // with column references inside expressions.
        $pageviewBase = DB::table('analytics_events')
            ->where('analytics_site_id', $analyticsSiteId)
            ->whereIn('event_type', self::PAGEVIEW_EVENT_TYPES)
            ->selectRaw($pageKeyExpression . ' as url_key')
            ->selectRaw('COALESCE(canonical_url, url, path) as resolved_url');

        $pageviewRows = DB::query()
            ->fromSub($pageviewBase, 'pv')
            ->whereNotNull('url_key')
            ->select('url_key')
            ->selectRaw('MAX(resolved_url) as url, COUNT(*) as views')
            ->groupBy('url_key')
            ->get();

        $engagedBase = DB::table('analytics_events')
            ->where('analytics_site_id', $analyticsSiteId)
            ->where('event_type', 'engaged')
            ->selectRaw($pageKeyExpression . ' as url_key');

        $engagedByKey = DB::query()
            ->fromSub($engagedBase, 'eng')
            ->whereNotNull('url_key')
            ->select('url_key')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('url_key')
            ->pluck('total', 'url_key');

        $readThroughBase = DB::table('analytics_events')
            ->where('analytics_site_id', $analyticsSiteId)
            ->where('event_type', 'read_through')
            ->selectRaw($pageKeyExpression . ' as url_key');

        $readThroughByKey = DB::query()
            ->fromSub($readThroughBase, 'rt')
            ->whereNotNull('url_key')
            ->select('url_key')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('url_key')
            ->pluck('total', 'url_key');

        $scrollBySession = DB::table('page_scroll_events')
            ->where('analytics_site_id', $analyticsSiteId)
            ->selectRaw('url_key, session_id, MAX(url) as url, MAX(depth) as session_max_depth')
            ->groupBy('url_key', 'session_id');

        $scrollRows = DB::query()
            ->fromSub($scrollBySession, 'session_depth')
            ->selectRaw('url_key, MAX(url) as url, AVG(session_max_depth) as avg_depth, MAX(session_max_depth) as max_depth')
            ->groupBy('url_key')
            ->get()
            ->keyBy('url_key');

        $readRows = DB::table('page_read_sessions')
            ->where('analytics_site_id', $analyticsSiteId)
            ->select(['url_key', 'url', 'read_seconds'])
            ->get();

        $readStats = [];
        foreach ($readRows as $row) {
            $urlKey = trim((string) ($row->url_key ?? ''));
            if ($urlKey === '') {
                continue;
            }

            if (! isset($readStats[$urlKey])) {
                $readStats[$urlKey] = [
                    'url' => (string) ($row->url ?? ''),
                    'seconds' => [],
                ];
            }

            $readStats[$urlKey]['seconds'][] = max(0, (int) ($row->read_seconds ?? 0));
            if ($readStats[$urlKey]['url'] === '') {
                $readStats[$urlKey]['url'] = (string) ($row->url ?? '');
            }
        }

        $wordCounts = $this->resolveWordCounts($clientSiteId, $pageviewRows->pluck('url_key')->filter()->values()->all());

        $allUrlKeys = collect()
            ->merge($pageviewRows->pluck('url_key')->filter())
            ->merge(array_keys($readStats))
            ->merge($scrollRows->keys()->all())
            ->unique()
            ->values()
            ->all();

        $pageviewMap = $pageviewRows->keyBy('url_key');
        $now = now();
        $rows = [];

        foreach ($allUrlKeys as $urlKey) {
            $urlKey = trim((string) $urlKey);
            if ($urlKey === '') {
                continue;
            }

            $views = (int) ($pageviewMap[$urlKey]->views ?? 0);
            $engaged = (int) ($engagedByKey->get($urlKey, 0) ?? 0);
            $readThrough = (int) ($readThroughByKey->get($urlKey, 0) ?? 0);

            $engagedRate = $views > 0 ? round($engaged / $views, 4) : 0.0;
            $readThroughRate = $views > 0 ? round($readThrough / $views, 4) : 0.0;

            $scroll = $scrollRows->get($urlKey);
            $avgScrollDepth = $scroll ? round((float) ($scroll->avg_depth ?? 0), 2) : 0.0;
            $maxScrollDepth = $scroll ? (int) ($scroll->max_depth ?? 0) : 0;

            $readEntry = $readStats[$urlKey] ?? ['url' => '', 'seconds' => []];
            $readSeconds = (array) ($readEntry['seconds'] ?? []);
            sort($readSeconds);
            $avgReadTime = $readSeconds !== [] ? round(array_sum($readSeconds) / count($readSeconds), 2) : 0.0;
            $medianReadTime = $this->median($readSeconds);

            $wordCount = (int) ($wordCounts[$urlKey] ?? 0);
            $estimatedReadTime = $wordCount > 0
                ? round(($wordCount / 200) * 60, 2)
                : self::DEFAULT_ESTIMATED_READ_SECONDS;

            $readTimeRatio = $estimatedReadTime > 0
                ? min(1.0, round($avgReadTime / $estimatedReadTime, 4))
                : 0.0;

            $roiScore = round(((
                ($engagedRate * 0.4)
                + (($avgScrollDepth / 100) * 0.3)
                + ($readThroughRate * 0.2)
                + ($readTimeRatio * 0.1)
            ) * 100), 2);

            $resolvedUrl = trim((string) ($pageviewMap[$urlKey]->url ?? ''));
            if ($resolvedUrl === '') {
                $resolvedUrl = trim((string) ($scroll?->url ?? ''));
            }
            if ($resolvedUrl === '') {
                $resolvedUrl = trim((string) ($readEntry['url'] ?? ''));
            }
            if ($resolvedUrl === '') {
                $resolvedUrl = 'https://' . ltrim($urlKey, '/');
            }

            $rows[] = [
                'analytics_site_id' => $analyticsSiteId,
                'url' => $resolvedUrl,
                'url_key' => $urlKey,
                'avg_scroll_depth' => $avgScrollDepth,
                'max_scroll_depth' => max(0, min(100, $maxScrollDepth)),
                'avg_read_time' => $avgReadTime,
                'median_read_time' => $medianReadTime,
                'engaged_rate' => $engagedRate,
                'read_through_rate' => $readThroughRate,
                'estimated_read_time' => $estimatedReadTime,
                'roi_score' => max(0.0, min(100.0, $roiScore)),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int,string>  $urlKeys
     * @return array<string,int>
     */
    private function resolveWordCounts(string $clientSiteId, array $urlKeys): array
    {
        if ($clientSiteId === '' || $urlKeys === []) {
            return [];
        }

        $rows = DB::table('contents')
            ->where('client_site_id', $clientSiteId)
            ->where(function ($query) use ($urlKeys): void {
                $query->whereIn('publish_url_key', $urlKeys)
                    ->orWhereIn('canonical_url_key', $urlKeys);
            })
            ->select(['publish_url_key', 'canonical_url_key', 'actual_word_count'])
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $wordCount = max(0, (int) ($row->actual_word_count ?? 0));
            foreach (['publish_url_key', 'canonical_url_key'] as $column) {
                $key = trim((string) ($row->{$column} ?? ''));
                if ($key === '') {
                    continue;
                }
                $result[$key] = max($result[$key] ?? 0, $wordCount);
            }
        }

        return $result;
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

    private function tablesAvailable(): bool
    {
        return Schema::hasTable('analytics_sites')
            && Schema::hasTable('analytics_events')
            && Schema::hasTable('page_scroll_events')
            && Schema::hasTable('page_read_sessions')
            && Schema::hasTable('content_metrics');
    }

    private function normalizedEventUrlKeyExpression(): string
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
}
