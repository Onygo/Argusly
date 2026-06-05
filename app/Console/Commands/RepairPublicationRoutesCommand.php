<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\MarketingBlogRedirect;
use App\Services\Content\ContentCacheInvalidationService;
use App\Services\Content\LocalizedContentSlugService;
use App\Services\Publication\ContentPublicationStateService;
use App\Services\PublicBlog\PublicBlogService;
use App\Support\LocalizedMarketingUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairPublicationRoutesCommand extends Command
{
    protected $signature = 'content:repair-publication-routes
        {--dry-run : Preview changes without applying them}
        {--content-id= : Only process a specific content ID}
        {--site-id= : Only process content for a specific site}
        {--locale= : Only process a specific locale}
        {--rebuild-live-routes : Rebuild public route/cache mappings}
        {--rebuild-redirects : Rebuild same-locale redirects after canonical route resolves}';

    protected $description = 'Repair public blog route mappings for localized Laravel publications.';

    public function handle(
        ContentPublicationStateService $publicationState,
        LocalizedContentSlugService $slugs,
        ContentCacheInvalidationService $cacheInvalidation,
        PublicBlogService $blog,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $rebuildLiveRoutes = (bool) $this->option('rebuild-live-routes');
        $rebuildRedirects = (bool) $this->option('rebuild-redirects');
        $contentId = $this->option('content-id') ? trim((string) $this->option('content-id')) : null;
        $siteId = $this->option('site-id') ? trim((string) $this->option('site-id')) : null;
        $locale = $this->option('locale') ? strtolower(trim((string) $this->option('locale'))) : null;

        $this->info($dryRun ? 'Running in DRY-RUN mode.' : 'Running in REPAIR mode.');

        $report = [
            'scanned' => 0,
            'affected' => 0,
            'repaired' => 0,
            'skipped' => 0,
            'live_routes_rebuilt' => 0,
            'redirects_rebuilt' => 0,
            'publication_urls_synced' => 0,
            'stale_published_flags_corrected' => 0,
            'resolver_verified' => 0,
        ];

        $contents = Content::query()
            ->with(['currentVersion', 'publications', 'clientSite'])
            ->where('type', 'article')
            ->when($contentId, fn ($query) => $query->where('id', $contentId))
            ->when($siteId, fn ($query) => $query->where('client_site_id', $siteId))
            ->when($locale, fn ($query) => $query->where('language', $locale))
            ->whereHas('publications', function ($query): void {
                $query->where('provider', ContentPublication::PROVIDER_LARAVEL)
                    ->where('delivery_status', ContentPublication::STATUS_DELIVERED);
            })
            ->orderBy('id')
            ->get();

        $report['scanned'] = $contents->count();

        foreach ($contents as $content) {
            $publication = $publicationState->resolveCanonicalPublication(
                $content,
                provider: ContentPublication::PROVIDER_LARAVEL,
            );

            if (! $publication instanceof ContentPublication) {
                $report['skipped']++;
                continue;
            }

            $contentLocale = $content->localeCode();
            $liveSlug = $slugs->persistedSlug($content) ?: $this->slugFromPublication($publication);
            $before = $liveSlug !== '' ? $blog->getPostBySlug($liveSlug, $contentLocale) : null;
            $needsRouteRebuild = ! is_array($before) || (string) ($before['id'] ?? '') !== (string) $content->id;
            $needsRedirectRebuild = $this->sameLocaleRedirectsNeedingLiveTarget($content, $contentLocale, $liveSlug)->isNotEmpty();

            if (! $needsRouteRebuild && ! $needsRedirectRebuild) {
                $report['skipped']++;
                continue;
            }

            $report['affected']++;
            $this->line(sprintf(
                '- %s [%s] slug=%s route=%s redirects=%s',
                (string) $content->id,
                $contentLocale,
                $liveSlug,
                $needsRouteRebuild ? 'missing' : 'ok',
                $needsRedirectRebuild ? 'pending' : 'ok',
            ));

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use (
                $content,
                $publication,
                $contentLocale,
                $liveSlug,
                $needsRouteRebuild,
                $needsRedirectRebuild,
                $rebuildLiveRoutes,
                $rebuildRedirects,
                $cacheInvalidation,
                $blog,
                &$report
            ): void {
                if ($needsRouteRebuild && $rebuildLiveRoutes) {
                    $cacheInvalidation->invalidateContent($content, 'content.publication_routes_rebuilt');
                    $report['live_routes_rebuilt']++;
                }

                $after = $liveSlug !== '' ? $blog->getPostBySlug($liveSlug, $contentLocale) : null;
                $routeIsLive = is_array($after) && (string) ($after['id'] ?? '') === (string) $content->id;

                if ($routeIsLive) {
                    $report['resolver_verified']++;

                    $expectedUrl = trim((string) ($content->published_url ?: $content->seo_canonical));
                    if ($expectedUrl !== '' && trim((string) ($publication->remote_url ?? '')) !== $expectedUrl) {
                        $publication->forceFill(['remote_url' => $expectedUrl])->save();
                        $report['publication_urls_synced']++;
                    }

                    if ($needsRedirectRebuild && $rebuildRedirects) {
                        $report['redirects_rebuilt'] += $this->activateSameLocaleRedirects($content, $contentLocale, $liveSlug);
                    }
                } elseif ($needsRouteRebuild && $rebuildLiveRoutes) {
                    $meta = is_array($publication->meta) ? $publication->meta : [];
                    $meta['route_repair_failed_at'] = now()->toIso8601String();
                    $meta['route_repair_failed_slug'] = $liveSlug;

                    $publication->forceFill([
                        'delivery_status' => ContentPublication::STATUS_MISSING_REMOTE,
                        'last_error_at' => now(),
                        'last_error_code' => 'public_route_unresolved',
                        'last_error_message' => 'Public blog resolver could not resolve the delivered localized slug.',
                        'meta' => $meta,
                    ])->save();

                    $report['stale_published_flags_corrected']++;
                }

                $report['repaired']++;
            });
        }

        $this->newLine();
        $this->table(['Metric', 'Count'], collect($report)->map(fn ($value, string $key): array => [$key, (string) $value])->values()->all());

        return self::SUCCESS;
    }

    private function slugFromPublication(ContentPublication $publication): string
    {
        $path = (string) parse_url((string) ($publication->remote_url ?? ''), PHP_URL_PATH);

        return trim((string) basename($path), '/');
    }

    private function sameLocaleRedirectsNeedingLiveTarget(Content $content, string $locale, string $liveSlug)
    {
        return MarketingBlogRedirect::query()
            ->where('target_content_id', (string) $content->id)
            ->where('source_locale', $locale)
            ->where('target_locale', $locale)
            ->where('target_slug', $liveSlug)
            ->where('is_active', false)
            ->get();
    }

    private function activateSameLocaleRedirects(Content $content, string $locale, string $liveSlug): int
    {
        $targetPath = LocalizedMarketingUrl::route('public.blog.show', ['slug' => $liveSlug], $locale, false);

        return MarketingBlogRedirect::query()
            ->where('target_content_id', (string) $content->id)
            ->where('source_locale', $locale)
            ->where('target_locale', $locale)
            ->where('target_slug', $liveSlug)
            ->where('is_active', false)
            ->update([
                'target_path' => $targetPath,
                'is_active' => true,
                'updated_at' => now(),
            ]);
    }
}
