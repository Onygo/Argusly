<?php

use App\Support\Analytics\AnalyticsUrlKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONTENT_EVENT_TYPES = ['page_view', 'pageview', 'engaged', 'read_through'];

    public function up(): void
    {
        $this->normalizeContentUrlKeys();
        $this->normalizeAnalyticsEventsAndMergeDuplicates();
        $this->normalizeRollupsAndMergeDuplicates();
    }

    public function down(): void
    {
        // Intentionally empty: this migration normalizes and merges existing data.
    }

    private function normalizeContentUrlKeys(): void
    {
        if (! Schema::hasTable('contents')) {
            return;
        }

        if (! Schema::hasColumn('contents', 'published_url')) {
            return;
        }

        $siteHosts = DB::table('client_sites')
            ->select(['id', 'base_url', 'site_url'])
            ->get()
            ->mapWithKeys(function ($site): array {
                $base = trim((string) ($site->base_url ?: $site->site_url));
                $host = AnalyticsUrlKey::hostFromUrl($base);

                return [(string) $site->id => $host];
            })
            ->all();

        DB::table('contents')
            ->select(['id', 'client_site_id', 'published_url', 'publish_url_key', 'canonical_url_key'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($siteHosts): void {
                foreach ($rows as $row) {
                    $publishedUrl = trim((string) ($row->published_url ?? ''));
                    $normalizedUrl = AnalyticsUrlKey::normalizeUrl($publishedUrl);
                    $publishKey = $normalizedUrl !== null ? AnalyticsUrlKey::fromUrl($normalizedUrl) : '';

                    $canonicalKey = $publishKey;
                    $siteHost = (string) ($siteHosts[(string) ($row->client_site_id ?? '')] ?? '');
                    if ($siteHost !== '' && $normalizedUrl !== null) {
                        $hostScoped = AnalyticsUrlKey::fromUrlUsingHost($normalizedUrl, $siteHost);
                        if ($hostScoped !== '') {
                            $canonicalKey = $hostScoped;
                        }
                    }

                    $storedPublish = $publishKey !== '' ? $publishKey : null;
                    $storedCanonical = $canonicalKey !== '' ? $canonicalKey : null;
                    $existingPublish = $row->publish_url_key !== null ? (string) $row->publish_url_key : null;
                    $existingCanonical = $row->canonical_url_key !== null ? (string) $row->canonical_url_key : null;

                    if ($existingPublish === $storedPublish && $existingCanonical === $storedCanonical) {
                        continue;
                    }

                    DB::table('contents')
                        ->where('id', (string) $row->id)
                        ->update([
                            'publish_url_key' => $storedPublish,
                            'canonical_url_key' => $storedCanonical,
                        ]);
                }
            }, 'id');
    }

    private function normalizeAnalyticsEventsAndMergeDuplicates(): void
    {
        if (! Schema::hasTable('analytics_events')) {
            return;
        }

        $siteToClientSite = [];
        $resolvedBySiteAndUrl = [];
        $resolvedBySiteAndArticle = [];

        DB::table('analytics_events')
            ->select([
                'id',
                'analytics_site_id',
                'event_type',
                'event_time',
                'url',
                'canonical_url',
                'host',
                'path',
                'title',
                'referrer',
                'article_id',
                'url_key',
                'content_id',
                'page_type',
            ])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$siteToClientSite, &$resolvedBySiteAndUrl, &$resolvedBySiteAndArticle): void {
                foreach ($rows as $row) {
                    $eventId = (int) $row->id;
                    $analyticsSiteId = trim((string) ($row->analytics_site_id ?? ''));
                    $eventType = $this->normalizeEventType((string) ($row->event_type ?? ''));
                    $normalizedUrl = $this->normalizePreferredUrl(
                        (string) ($row->canonical_url ?? ''),
                        (string) ($row->url ?? ''),
                        (string) ($row->host ?? ''),
                        (string) ($row->path ?? '/')
                    );

                    if ($analyticsSiteId === '' || $normalizedUrl === null) {
                        continue;
                    }

                    $normalizedPath = AnalyticsUrlKey::pathFromUrl($normalizedUrl);
                    $normalizedPath = $normalizedPath !== '' ? $normalizedPath : '/';
                    $urlKey = AnalyticsUrlKey::fromUrl($normalizedUrl);
                    $storedUrlKey = $urlKey !== '' ? $urlKey : null;
                    $canonicalUrlHash = hash('sha256', $normalizedUrl);
                    $timeBucket = intdiv(CarbonImmutable::parse((string) $row->event_time)->timestamp, 30);
                    $eventHash = hash('sha256', implode('|', [$analyticsSiteId, $canonicalUrlHash, $eventType, (string) $timeBucket]));

                    $articleId = trim((string) ($row->article_id ?? ''));
                    if ($articleId !== '' && ! Str::isUuid($articleId)) {
                        $articleId = '';
                    }

                    $contentId = $row->content_id !== null ? (string) $row->content_id : null;
                    $pageType = $row->page_type !== null ? (string) $row->page_type : null;

                    if (in_array($eventType, self::CONTENT_EVENT_TYPES, true)) {
                        $clientSiteId = $this->resolveClientSiteId($analyticsSiteId, $siteToClientSite);
                        if ($contentId === null && $clientSiteId !== '' && $urlKey !== '') {
                            $contentId = $this->resolveContentIdByUrl($clientSiteId, $urlKey, $resolvedBySiteAndUrl);
                        }

                        if ($contentId === null && $clientSiteId !== '' && $articleId !== '') {
                            $contentId = $this->resolveContentIdByArticle($clientSiteId, $articleId, $resolvedBySiteAndArticle);
                        }

                        $pageType = ($contentId !== null && $contentId !== '') || $articleId !== ''
                            ? 'argusly_content'
                            : 'other_page';
                    }

                    $duplicate = DB::table('analytics_events')
                        ->select(['id', 'content_id', 'page_type', 'article_id'])
                        ->where('event_hash', $eventHash)
                        ->where('id', '<>', $eventId)
                        ->first();

                    if ($duplicate) {
                        $updates = [
                            'event_type' => $eventType,
                            'url' => $normalizedUrl,
                            'canonical_url' => $normalizedUrl,
                            'canonical_url_hash' => $canonicalUrlHash,
                            'host' => AnalyticsUrlKey::hostFromUrl($normalizedUrl),
                            'path' => $normalizedPath,
                            'path_hash' => hash('sha256', $normalizedPath),
                            'url_key' => $storedUrlKey,
                            'event_hash' => $eventHash,
                        ];

                        $existingContentId = $duplicate->content_id !== null ? (string) $duplicate->content_id : null;
                        $existingPageType = $duplicate->page_type !== null ? (string) $duplicate->page_type : null;
                        $existingArticleId = $duplicate->article_id !== null ? (string) $duplicate->article_id : null;

                        if ($existingContentId === null && $contentId !== null) {
                            $updates['content_id'] = $contentId;
                        }

                        if (
                            ($existingPageType === null || $existingPageType === 'other_page')
                            && $pageType === 'argusly_content'
                        ) {
                            $updates['page_type'] = 'argusly_content';
                        }

                        if ($existingArticleId === null && $articleId !== '') {
                            $updates['article_id'] = $articleId;
                        }

                        DB::table('analytics_events')
                            ->where('id', (int) $duplicate->id)
                            ->update($updates);

                        DB::table('analytics_events')
                            ->where('id', $eventId)
                            ->delete();

                        continue;
                    }

                    DB::table('analytics_events')
                        ->where('id', $eventId)
                        ->update([
                            'event_type' => $eventType,
                            'url' => $normalizedUrl,
                            'canonical_url' => $normalizedUrl,
                            'canonical_url_hash' => $canonicalUrlHash,
                            'host' => AnalyticsUrlKey::hostFromUrl($normalizedUrl),
                            'path' => $normalizedPath,
                            'path_hash' => hash('sha256', $normalizedPath),
                            'url_key' => $storedUrlKey,
                            'content_id' => $contentId,
                            'page_type' => $pageType,
                            'event_hash' => $eventHash,
                        ]);
                }
            }, 'id');
    }

    private function normalizeRollupsAndMergeDuplicates(): void
    {
        if (! Schema::hasTable('analytics_rollups_daily')) {
            return;
        }

        DB::table('analytics_rollups_daily')
            ->select([
                'id',
                'analytics_site_id',
                'date',
                'path',
                'path_hash',
                'article_id',
                'title',
                'page_views',
                'unique_visitors',
                'scroll_50',
                'scroll_100',
                'heartbeats',
                'engaged_views',
                'total_time_seconds',
            ])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $rollupId = (int) $row->id;
                    $normalizedPath = AnalyticsUrlKey::normalizePathValue((string) ($row->path ?? '/'));
                    $normalizedPathHash = hash('sha256', $normalizedPath);

                    $duplicate = DB::table('analytics_rollups_daily')
                        ->where('analytics_site_id', (string) $row->analytics_site_id)
                        ->where('date', (string) $row->date)
                        ->where('path_hash', $normalizedPathHash)
                        ->where('id', '<>', $rollupId)
                        ->first([
                            'id',
                            'article_id',
                            'title',
                            'page_views',
                            'unique_visitors',
                            'scroll_50',
                            'scroll_100',
                            'heartbeats',
                            'engaged_views',
                            'total_time_seconds',
                        ]);

                    if ($duplicate) {
                        DB::table('analytics_rollups_daily')
                            ->where('id', (int) $duplicate->id)
                            ->update([
                                'path' => $normalizedPath,
                                'path_hash' => $normalizedPathHash,
                                'article_id' => $duplicate->article_id ?: $row->article_id,
                                'title' => $duplicate->title ?: $row->title,
                                'page_views' => (int) $duplicate->page_views + (int) $row->page_views,
                                'unique_visitors' => (int) $duplicate->unique_visitors + (int) $row->unique_visitors,
                                'scroll_50' => (int) $duplicate->scroll_50 + (int) $row->scroll_50,
                                'scroll_100' => (int) $duplicate->scroll_100 + (int) $row->scroll_100,
                                'heartbeats' => (int) $duplicate->heartbeats + (int) $row->heartbeats,
                                'engaged_views' => (int) $duplicate->engaged_views + (int) $row->engaged_views,
                                'total_time_seconds' => (int) $duplicate->total_time_seconds + (int) $row->total_time_seconds,
                                'updated_at' => now(),
                            ]);

                        DB::table('analytics_rollups_daily')
                            ->where('id', $rollupId)
                            ->delete();

                        continue;
                    }

                    DB::table('analytics_rollups_daily')
                        ->where('id', $rollupId)
                        ->update([
                            'path' => $normalizedPath,
                            'path_hash' => $normalizedPathHash,
                        ]);
                }
            }, 'id');
    }

    private function normalizePreferredUrl(string $canonicalUrl, string $url, string $host, string $path): ?string
    {
        $canonical = AnalyticsUrlKey::normalizeUrl($canonicalUrl);
        if ($canonical !== null) {
            return $canonical;
        }

        $direct = AnalyticsUrlKey::normalizeUrl($url);
        if ($direct !== null) {
            return $direct;
        }

        $host = trim($host);
        if ($host !== '') {
            return AnalyticsUrlKey::normalizeUrl('https://' . $host . '/' . ltrim($path, '/'));
        }

        return null;
    }

    /**
     * @param  array<string, string>  $cache
     */
    private function resolveClientSiteId(string $analyticsSiteId, array &$cache): string
    {
        if (array_key_exists($analyticsSiteId, $cache)) {
            return $cache[$analyticsSiteId];
        }

        $value = trim((string) DB::table('analytics_sites')
            ->where('id', $analyticsSiteId)
            ->value('client_site_id'));

        $cache[$analyticsSiteId] = $value;

        return $value;
    }

    /**
     * @param  array<string, string|null>  $cache
     */
    private function resolveContentIdByUrl(string $clientSiteId, string $urlKey, array &$cache): ?string
    {
        $cacheKey = $clientSiteId . '|' . $urlKey;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $resolved = DB::table('contents')
            ->where('client_site_id', $clientSiteId)
            ->where(function ($query) use ($urlKey): void {
                $query->where('publish_url_key', $urlKey)
                    ->orWhere('canonical_url_key', $urlKey);
            })
            ->value('id');

        $cache[$cacheKey] = is_string($resolved) && $resolved !== '' ? $resolved : null;

        return $cache[$cacheKey];
    }

    /**
     * @param  array<string, string|null>  $cache
     */
    private function resolveContentIdByArticle(string $clientSiteId, string $articleId, array &$cache): ?string
    {
        $cacheKey = $clientSiteId . '|' . $articleId;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $resolved = DB::table('contents')
            ->where('id', $articleId)
            ->where('client_site_id', $clientSiteId)
            ->value('id');

        $cache[$cacheKey] = is_string($resolved) && $resolved !== '' ? $resolved : null;

        return $cache[$cacheKey];
    }

    private function normalizeEventType(string $eventType): string
    {
        $normalized = strtolower(trim($eventType));

        return $normalized === 'pageview' ? 'page_view' : $normalized;
    }
};
