<?php

namespace App\Console\Commands;

use App\Services\Analytics\AnalyticsContentResolver;
use App\Support\Analytics\AnalyticsUrlKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillAnalyticsPageClassificationCommand extends Command
{
    protected $signature = 'analytics:backfill-page-classification
        {--site= : Optional analytics_site_id}
        {--chunk=1000 : Batch size}
        {--dry-run : Compute changes without writing}';

    protected $description = 'Backfill analytics event url_key/content_id/page_type classification for page views.';

    public function __construct(
        private AnalyticsContentResolver $resolver
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $siteFilter = trim((string) $this->option('site'));
        $chunkSize = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        [$contentProcessed, $contentChanged] = $this->syncContentUrlKeys($siteFilter, $chunkSize, $dryRun);

        $query = DB::table('analytics_events')
            ->select(['id', 'analytics_site_id', 'event_type', 'url', 'canonical_url', 'article_id', 'url_key', 'content_id', 'page_type'])
            ->whereIn('event_type', ['page_view', 'pageview'])
            ->orderBy('id');

        if ($siteFilter !== '') {
            $query->where('analytics_site_id', $siteFilter);
        }

        $processed = 0;
        $changed = 0;
        $siteToClientSite = [];
        $resolvedBySiteAndUrl = [];

        $query->chunkById($chunkSize, function ($rows) use (
            &$processed,
            &$changed,
            $dryRun,
            &$siteToClientSite,
            &$resolvedBySiteAndUrl
        ): void {
            $updates = [];

            foreach ($rows as $row) {
                $processed++;

                $canonicalUrl = trim((string) ($row->canonical_url ?? ''));
                $url = trim((string) ($row->url ?? ''));
                $eventUrl = AnalyticsUrlKey::normalizeUrl($canonicalUrl) ?? AnalyticsUrlKey::normalizeUrl($url) ?? '';
                $urlKey = AnalyticsUrlKey::fromUrl($eventUrl);
                $storedUrlKey = $urlKey !== '' ? $urlKey : null;

                $analyticsSiteId = trim((string) ($row->analytics_site_id ?? ''));
                if (! array_key_exists($analyticsSiteId, $siteToClientSite)) {
                    $siteToClientSite[$analyticsSiteId] = $analyticsSiteId !== ''
                        ? trim((string) DB::table('analytics_sites')->where('id', $analyticsSiteId)->value('client_site_id'))
                        : '';
                }

                $clientSiteId = $siteToClientSite[$analyticsSiteId];
                $contentId = null;
                if ($clientSiteId !== '' && $urlKey !== '') {
                    $cacheKey = $clientSiteId . '|' . $urlKey;
                    if (! array_key_exists($cacheKey, $resolvedBySiteAndUrl)) {
                        $resolvedBySiteAndUrl[$cacheKey] = $this->resolver->resolve($clientSiteId, $urlKey);
                    }
                    $contentId = $resolvedBySiteAndUrl[$cacheKey];
                }

                $articleId = trim((string) ($row->article_id ?? ''));
                if ($contentId === null && $clientSiteId !== '' && $articleId !== '') {
                    $cacheKey = $clientSiteId . '|article|' . $articleId;
                    if (! array_key_exists($cacheKey, $resolvedBySiteAndUrl)) {
                        $resolvedBySiteAndUrl[$cacheKey] = DB::table('contents')
                            ->where('id', $articleId)
                            ->where('client_site_id', $clientSiteId)
                            ->value('id');
                    }
                    $resolvedByArticle = $resolvedBySiteAndUrl[$cacheKey];
                    if (is_string($resolvedByArticle) && $resolvedByArticle !== '') {
                        $contentId = $resolvedByArticle;
                    }
                }

                $pageType = (is_string($contentId) && $contentId !== '') || $articleId !== ''
                    ? 'argusly_content'
                    : 'other_page';
                $existingUrlKey = $row->url_key !== null ? (string) $row->url_key : null;
                $existingContentId = $row->content_id !== null ? (string) $row->content_id : null;
                $existingPageType = $row->page_type !== null ? (string) $row->page_type : null;

                if (
                    $existingUrlKey !== $storedUrlKey
                    || $existingContentId !== $contentId
                    || $existingPageType !== $pageType
                ) {
                    $changed++;
                    $updates[] = [
                        'id' => (int) $row->id,
                        'url_key' => $storedUrlKey,
                        'content_id' => $contentId,
                        'page_type' => $pageType,
                    ];
                }
            }

            if (! $dryRun && $updates !== []) {
                foreach ($updates as $update) {
                    DB::table('analytics_events')
                        ->where('id', (int) $update['id'])
                        ->update([
                            'url_key' => $update['url_key'],
                            'content_id' => $update['content_id'],
                            'page_type' => $update['page_type'],
                        ]);
                }
            }
        }, 'id');

        $mode = $dryRun ? 'dry-run' : 'write';
        $this->info("Processed {$contentProcessed} contents ({$mode}).");
        $this->info("Contents requiring URL key update: {$contentChanged}.");
        $this->info("Processed {$processed} page_view events ({$mode}).");
        $this->info("Rows requiring update: {$changed}.");

        return Command::SUCCESS;
    }

    /**
     * @return array{int,int} [processed, changed]
     */
    private function syncContentUrlKeys(string $analyticsSiteId, int $chunkSize, bool $dryRun): array
    {
        $clientSiteHosts = DB::table('client_sites')
            ->select(['id', 'base_url', 'site_url'])
            ->get()
            ->mapWithKeys(function ($row): array {
                $siteUrl = trim((string) ($row->base_url ?: $row->site_url));
                $host = AnalyticsUrlKey::hostFromUrl($siteUrl);

                return [(string) $row->id => $host];
            })
            ->all();

        $query = DB::table('contents')
            ->select(['id', 'client_site_id', 'published_url', 'publish_url_key', 'canonical_url_key'])
            ->orderBy('id');

        if ($analyticsSiteId !== '') {
            $clientSiteId = trim((string) DB::table('analytics_sites')->where('id', $analyticsSiteId)->value('client_site_id'));
            if ($clientSiteId === '') {
                return [0, 0];
            }
            $query->where('client_site_id', $clientSiteId);
        } else {
            $query->whereNotNull('client_site_id');
        }

        $processed = 0;
        $changed = 0;

        $query->chunkById($chunkSize, function ($rows) use (&$processed, &$changed, $dryRun, $clientSiteHosts): void {
            foreach ($rows as $row) {
                $processed++;

                $publishedUrl = trim((string) ($row->published_url ?? ''));
                $publishKey = AnalyticsUrlKey::fromUrl($publishedUrl);
                $siteHost = (string) ($clientSiteHosts[(string) ($row->client_site_id ?? '')] ?? '');
                $canonicalKey = $publishKey;
                if ($siteHost !== '' && $publishedUrl !== '') {
                    $hostKey = AnalyticsUrlKey::fromUrlUsingHost($publishedUrl, $siteHost);
                    if ($hostKey !== '') {
                        $canonicalKey = $hostKey;
                    }
                }

                $storedPublish = $publishKey !== '' ? $publishKey : null;
                $storedCanonical = $canonicalKey !== '' ? $canonicalKey : null;
                $existingPublish = $row->publish_url_key !== null ? (string) $row->publish_url_key : null;
                $existingCanonical = $row->canonical_url_key !== null ? (string) $row->canonical_url_key : null;

                if ($existingPublish !== $storedPublish || $existingCanonical !== $storedCanonical) {
                    $changed++;

                    if (! $dryRun) {
                        DB::table('contents')
                            ->where('id', (string) $row->id)
                            ->update([
                                'publish_url_key' => $storedPublish,
                                'canonical_url_key' => $storedCanonical,
                            ]);
                    }
                }
            }
        }, 'id');

        return [$processed, $changed];
    }
}
