<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsSite;
use App\Models\Content;
use App\Support\Analytics\AnalyticsUrlKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ContentPerformanceInsightService
{
    /**
     * @param  Collection<int,Content>  $contents
     * @return array<string,array<string,mixed>>
     */
    public function forContents(Collection $contents): array
    {
        if ($contents->isEmpty()) {
            return [];
        }

        $contentById = $contents->keyBy(fn (Content $content): string => (string) $content->id);

        $siteIds = $contentById
            ->pluck('client_site_id')
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(fn ($value): string => trim((string) $value))
            ->unique()
            ->values();

        if ($siteIds->isEmpty()) {
            return $this->buildUnknownTrackingInsights($contentById);
        }

        $analyticsSites = AnalyticsSite::query()
            ->whereIn('client_site_id', $siteIds->all())
            ->get(['id', 'client_site_id', 'is_enabled', 'verified_at'])
            ->keyBy(fn (AnalyticsSite $site): string => (string) $site->client_site_id);

        $siteHosts = DB::table('client_sites')
            ->whereIn('id', $siteIds->all())
            ->select(['id', 'base_url', 'site_url'])
            ->get()
            ->mapWithKeys(static function ($row): array {
                $base = (string) ($row->base_url ?: $row->site_url);

                return [(string) $row->id => AnalyticsUrlKey::hostFromUrl($base)];
            });

        $keysByAnalyticsSite = [];
        $candidateKeysByContent = [];
        $publishedUrlsByContent = [];

        foreach ($contentById as $contentId => $content) {
            $publishedUrl = trim((string) ($content->published_url ?? ''));
            $publishedUrlsByContent[$contentId] = $publishedUrl;

            $candidateKeys = $this->candidateKeys(
                trim((string) ($content->canonical_url_key ?? '')),
                trim((string) ($content->publish_url_key ?? '')),
                $publishedUrl,
                (string) ($siteHosts->get((string) $content->client_site_id) ?? '')
            );

            $candidateKeysByContent[$contentId] = $candidateKeys;

            $analyticsSite = $analyticsSites->get((string) $content->client_site_id);
            if (! $analyticsSite || $candidateKeys === []) {
                continue;
            }

            $analyticsSiteId = (string) $analyticsSite->id;
            if (! isset($keysByAnalyticsSite[$analyticsSiteId])) {
                $keysByAnalyticsSite[$analyticsSiteId] = [];
            }

            foreach ($candidateKeys as $key) {
                $keysByAnalyticsSite[$analyticsSiteId][$key] = true;
            }
        }

        $contentMetrics = collect();
        $aiVisibility = collect();
        $aiSeoScoresBySiteAndKey = collect();
        $aiSeoScoresByUrl = collect();

        if ($keysByAnalyticsSite !== []) {
            $analyticsSiteIds = array_keys($keysByAnalyticsSite);
            $allKeys = collect($keysByAnalyticsSite)
                ->flatMap(static fn (array $keys): array => array_keys($keys))
                ->unique()
                ->values()
                ->all();

            if ($allKeys !== []) {
                if (Schema::hasTable('content_metrics')) {
                    $contentMetrics = DB::table('content_metrics')
                        ->whereIn('analytics_site_id', $analyticsSiteIds)
                        ->whereIn('url_key', $allKeys)
                        ->select(['analytics_site_id', 'url', 'url_key', 'roi_score', 'updated_at'])
                        ->get()
                        ->keyBy(fn ($row): string => $this->lookupKey((string) $row->analytics_site_id, (string) $row->url_key));
                }

                if (Schema::hasTable('content_ai_visibility')) {
                    $aiVisibility = DB::table('content_ai_visibility')
                        ->whereIn('analytics_site_id', $analyticsSiteIds)
                        ->whereIn('url_key', $allKeys)
                        ->select(['analytics_site_id', 'url_key', 'ai_visibility_score', 'llm_citations', 'updated_at'])
                        ->get()
                        ->keyBy(fn ($row): string => $this->lookupKey((string) $row->analytics_site_id, (string) $row->url_key));
                }

                if (Schema::hasTable('content_ai_seo_scores')) {
                    $hasSiteAndKeyColumns = Schema::hasColumn('content_ai_seo_scores', 'analytics_site_id')
                        && Schema::hasColumn('content_ai_seo_scores', 'url_key');

                    if ($hasSiteAndKeyColumns) {
                        $aiSeoScoresBySiteAndKey = DB::table('content_ai_seo_scores')
                            ->whereIn('analytics_site_id', $analyticsSiteIds)
                            ->whereIn('url_key', $allKeys)
                            ->select([
                                'analytics_site_id',
                                'url',
                                'url_key',
                                'ai_seo_score',
                                'calculated_at',
                                'content_metrics_updated_at',
                                'ai_visibility_updated_at',
                            ])
                            ->get()
                            ->keyBy(fn ($row): string => $this->lookupKey((string) $row->analytics_site_id, (string) $row->url_key));
                    }

                    $urls = collect($publishedUrlsByContent)
                        ->filter(fn ($value): bool => is_string($value) && $value !== '')
                        ->unique()
                        ->values()
                        ->all();

                    if ($urls !== []) {
                        $aiSeoScoresByUrl = DB::table('content_ai_seo_scores')
                            ->whereIn('url', $urls)
                            ->select([
                                'url',
                                'ai_seo_score',
                                'calculated_at',
                                'content_metrics_updated_at',
                                'ai_visibility_updated_at',
                            ])
                            ->get()
                            ->keyBy('url');
                    }
                }
            }
        }

        $insights = [];

        foreach ($contentById as $contentId => $content) {
            $analyticsSite = $analyticsSites->get((string) $content->client_site_id);
            $candidateKeys = $candidateKeysByContent[$contentId] ?? [];
            $publishedUrl = $publishedUrlsByContent[$contentId] ?? '';

            if (! $analyticsSite) {
                $insights[$contentId] = $this->buildInsight(
                    roiScore: null,
                    aiVisibilityScore: null,
                    aiSeoScore: null,
                    aiSeoStale: false,
                    roiUpdatedAt: null,
                    aiVisibilityUpdatedAt: null,
                    aiSeoUpdatedAt: null,
                    sourceUrlKey: null,
                    statusCode: 'tracking_not_configured',
                    statusMessage: 'Tracking is not configured for this site yet.'
                );
                continue;
            }

            if (! (bool) $analyticsSite->is_enabled) {
                $insights[$contentId] = $this->buildInsight(
                    roiScore: null,
                    aiVisibilityScore: null,
                    aiSeoScore: null,
                    aiSeoStale: false,
                    roiUpdatedAt: null,
                    aiVisibilityUpdatedAt: null,
                    aiSeoUpdatedAt: null,
                    sourceUrlKey: null,
                    statusCode: 'tracking_disabled',
                    statusMessage: 'Tracking is disabled for this site.'
                );
                continue;
            }

            if (! $analyticsSite->verified_at) {
                $insights[$contentId] = $this->buildInsight(
                    roiScore: null,
                    aiVisibilityScore: null,
                    aiSeoScore: null,
                    aiSeoStale: false,
                    roiUpdatedAt: null,
                    aiVisibilityUpdatedAt: null,
                    aiSeoUpdatedAt: null,
                    sourceUrlKey: null,
                    statusCode: 'tracking_pending_verification',
                    statusMessage: 'Waiting for analytics domain verification.'
                );
                continue;
            }

            if ($candidateKeys === []) {
                $insights[$contentId] = $this->buildInsight(
                    roiScore: null,
                    aiVisibilityScore: null,
                    aiSeoScore: null,
                    aiSeoStale: false,
                    roiUpdatedAt: null,
                    aiVisibilityUpdatedAt: null,
                    aiSeoUpdatedAt: null,
                    sourceUrlKey: null,
                    statusCode: 'not_published',
                    statusMessage: 'Publish this content to start tracking scores.'
                );
                continue;
            }

            $analyticsSiteId = (string) $analyticsSite->id;
            $resolvedKey = null;
            $contentMetricRow = null;
            $visibilityRow = null;
            $scoreRow = null;

            foreach ($candidateKeys as $candidateKey) {
                $lookup = $this->lookupKey($analyticsSiteId, $candidateKey);
                $candidateMetric = $contentMetrics->get($lookup);
                $candidateVisibility = $aiVisibility->get($lookup);
                $candidateScore = $aiSeoScoresBySiteAndKey->get($lookup);

                if ($candidateMetric || $candidateVisibility || $candidateScore) {
                    $resolvedKey = $candidateKey;
                    $contentMetricRow = $candidateMetric;
                    $visibilityRow = $candidateVisibility;
                    $scoreRow = $candidateScore;
                    break;
                }
            }

            if ($resolvedKey === null) {
                $resolvedKey = $candidateKeys[0] ?? null;
                if (is_string($resolvedKey) && $resolvedKey !== '') {
                    $lookup = $this->lookupKey($analyticsSiteId, $resolvedKey);
                    $contentMetricRow = $contentMetrics->get($lookup);
                    $visibilityRow = $aiVisibility->get($lookup);
                    $scoreRow = $aiSeoScoresBySiteAndKey->get($lookup);
                }
            }

            if (! $scoreRow && $publishedUrl !== '') {
                $scoreRow = $aiSeoScoresByUrl->get($publishedUrl);
            }

            $roiScore = $contentMetricRow ? $this->toNullableRounded((float) ($contentMetricRow->roi_score ?? 0)) : null;
            $aiVisibilityScore = $visibilityRow ? $this->toNullableRounded((float) ($visibilityRow->ai_visibility_score ?? 0)) : null;

            $aiSeoStale = $this->isAiSeoScoreStale($scoreRow, $contentMetricRow, $visibilityRow);
            $aiSeoScore = ($scoreRow && ! $aiSeoStale)
                ? $this->toNullableRounded((float) ($scoreRow->ai_seo_score ?? 0))
                : null;

            $roiUpdatedAt = $this->parseTimestamp(data_get($contentMetricRow, 'updated_at'));
            $aiVisibilityUpdatedAt = $this->latestTimestamp([
                data_get($visibilityRow, 'updated_at'),
                data_get($visibilityRow, 'last_checked_at'),
            ]);
            $aiSeoUpdatedAt = $this->parseTimestamp(data_get($scoreRow, 'calculated_at'));

            $statusCode = 'ready';
            $statusMessage = 'Scores are available from tracked analytics data.';

            if ($aiSeoStale) {
                $statusCode = 'score_pending_recalculation';
                $statusMessage = 'AI SEO Score is stale and pending recalculation.';
            } elseif ($roiScore === null && $aiVisibilityScore === null && $aiSeoScore === null) {
                $statusCode = 'waiting_for_data';
                $statusMessage = 'Waiting for tracking data from this page.';
            } elseif ($roiScore === null || $aiVisibilityScore === null || $aiSeoScore === null) {
                $statusCode = 'partial_data';
                $statusMessage = 'Partial score data is available.';
            }

            $insights[$contentId] = $this->buildInsight(
                roiScore: $roiScore,
                aiVisibilityScore: $aiVisibilityScore,
                aiSeoScore: $aiSeoScore,
                aiSeoStale: $aiSeoStale,
                roiUpdatedAt: $roiUpdatedAt,
                aiVisibilityUpdatedAt: $aiVisibilityUpdatedAt,
                aiSeoUpdatedAt: $aiSeoUpdatedAt,
                sourceUrlKey: $resolvedKey,
                statusCode: $statusCode,
                statusMessage: $statusMessage
            );
        }

        return $insights;
    }

    /**
     * @return array<string,mixed>
     */
    public function forContent(Content $content): array
    {
        $insights = $this->forContents(collect([$content]));

        return $insights[(string) $content->id] ?? $this->buildInsight(
            roiScore: null,
            aiVisibilityScore: null,
            aiSeoScore: null,
            aiSeoStale: false,
            roiUpdatedAt: null,
            aiVisibilityUpdatedAt: null,
            aiSeoUpdatedAt: null,
            sourceUrlKey: null,
            statusCode: 'waiting_for_data',
            statusMessage: 'Waiting for tracking data from this page.'
        );
    }

    /**
     * @param  array<string,array<string,mixed>>  $insights
     * @return array<string,mixed>
     */
    public function summarize(array $insights): array
    {
        $collection = collect($insights);

        $trackedContentCount = $collection->filter(static function (array $insight): bool {
            return in_array((string) ($insight['status_code'] ?? ''), ['ready', 'partial_data', 'score_pending_recalculation'], true);
        })->count();

        return [
            'content_roi' => $this->summarizeMetric($collection, 'roi_score', 'roi_updated_at'),
            'ai_visibility' => $this->summarizeMetric($collection, 'ai_visibility_score', 'ai_visibility_updated_at'),
            'ai_seo_score' => $this->summarizeMetric($collection, 'ai_seo_score', 'ai_seo_updated_at'),
            'tracked_content_count' => $trackedContentCount,
            'total_content_count' => $collection->count(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function summarizeMetric(Collection $insights, string $metricKey, string $updatedAtKey): array
    {
        $rowsWithMetric = $insights->filter(static fn (array $row): bool => is_numeric($row[$metricKey] ?? null));
        $samples = $rowsWithMetric->count();

        $value = null;
        if ($samples > 0) {
            $value = round((float) $rowsWithMetric->avg(static fn (array $row): float => (float) $row[$metricKey]), 1);
        }

        $updatedAt = $this->latestTimestamp(
            $insights->pluck($updatedAtKey)->all()
        );

        return [
            'value' => $value,
            'samples' => $samples,
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * @param  Collection<string,Content>  $contentById
     * @return array<string,array<string,mixed>>
     */
    private function buildUnknownTrackingInsights(Collection $contentById): array
    {
        $insights = [];

        foreach ($contentById as $contentId => $unusedContent) {
            $insights[$contentId] = $this->buildInsight(
                roiScore: null,
                aiVisibilityScore: null,
                aiSeoScore: null,
                aiSeoStale: false,
                roiUpdatedAt: null,
                aiVisibilityUpdatedAt: null,
                aiSeoUpdatedAt: null,
                sourceUrlKey: null,
                statusCode: 'tracking_not_configured',
                statusMessage: 'Tracking is not configured for this site yet.'
            );
        }

        return $insights;
    }

    /**
     * @return array<int,string>
     */
    private function candidateKeys(
        string $canonicalUrlKey,
        string $publishUrlKey,
        string $publishedUrl,
        string $siteHost
    ): array {
        $keys = [];

        if ($canonicalUrlKey !== '') {
            $keys[] = $canonicalUrlKey;
        }

        if ($publishUrlKey !== '') {
            $keys[] = $publishUrlKey;
        }

        if ($publishedUrl !== '') {
            if ($siteHost !== '') {
                $derivedWithSiteHost = AnalyticsUrlKey::fromUrlUsingHost($publishedUrl, $siteHost);
                if ($derivedWithSiteHost !== '') {
                    $keys[] = $derivedWithSiteHost;
                }
            }

            $derived = AnalyticsUrlKey::fromUrl($publishedUrl);
            if ($derived !== '') {
                $keys[] = $derived;
            }
        }

        return collect($keys)
            ->filter(static fn (string $value): bool => trim($value) !== '')
            ->map(static fn (string $value): string => trim($value))
            ->unique()
            ->values()
            ->all();
    }

    private function lookupKey(string $analyticsSiteId, string $urlKey): string
    {
        return trim($analyticsSiteId) . '|' . trim($urlKey);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildInsight(
        ?float $roiScore,
        ?float $aiVisibilityScore,
        ?float $aiSeoScore,
        bool $aiSeoStale,
        ?Carbon $roiUpdatedAt,
        ?Carbon $aiVisibilityUpdatedAt,
        ?Carbon $aiSeoUpdatedAt,
        ?string $sourceUrlKey,
        string $statusCode,
        string $statusMessage,
    ): array {
        return [
            'roi_score' => $roiScore,
            'ai_visibility_score' => $aiVisibilityScore,
            'ai_seo_score' => $aiSeoScore,
            'ai_seo_score_stale' => $aiSeoStale,
            'roi_updated_at' => $roiUpdatedAt,
            'ai_visibility_updated_at' => $aiVisibilityUpdatedAt,
            'ai_seo_updated_at' => $aiSeoUpdatedAt,
            'last_updated_at' => $this->latestTimestamp([$roiUpdatedAt, $aiVisibilityUpdatedAt, $aiSeoUpdatedAt]),
            'source_url_key' => $sourceUrlKey,
            'status_code' => $statusCode,
            'status_message' => $statusMessage,
        ];
    }

    private function toNullableRounded(float $value): ?float
    {
        if (! is_finite($value)) {
            return null;
        }

        return round($value, 1);
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
