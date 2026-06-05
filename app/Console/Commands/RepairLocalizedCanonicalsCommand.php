<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\ContentVersion;
use App\Models\MarketingBlogRedirect;
use App\Services\Content\ContentCacheInvalidationService;
use App\Services\Content\LocalizedContentSlugService;
use App\Services\Marketing\MarketingBlogRedirectService;
use App\Services\Publication\ContentPublicationStateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairLocalizedCanonicalsCommand extends Command
{
    protected $signature = 'content:repair-localized-canonicals
        {--dry-run : Preview changes without applying (default behavior)}
        {--fix : Actually apply the fixes}
        {--fix-canonical : Also repair canonical URLs on content}
        {--content-id= : Only process a specific content ID}
        {--site-id= : Only process content on a specific site}
        {--locale= : Only process a specific locale}
        {--force-slug-regenerate : Force regeneration of locale-specific slugs from localized titles}';

    protected $description = 'Repair localized publication slugs, canonicals, and invalid cross-locale redirects.';

    public function handle(
        ContentPublicationStateService $publicationState,
        MarketingBlogRedirectService $redirectService,
        LocalizedContentSlugService $slugs,
        ContentCacheInvalidationService $cacheInvalidation,
    ): int {
        $fix = (bool) $this->option('fix');
        $fixCanonical = (bool) $this->option('fix-canonical');
        $contentId = $this->option('content-id') ? (string) $this->option('content-id') : null;
        $siteId = $this->option('site-id') ? (string) $this->option('site-id') : null;
        $locale = $this->option('locale') ? strtolower(trim((string) $this->option('locale'))) : null;
        $forceSlugRegenerate = (bool) $this->option('force-slug-regenerate');

        $this->info($fix ? 'Running in FIX mode.' : 'Running in DRY-RUN mode (use --fix to apply changes).');
        $this->newLine();

        $report = [
            'scanned' => 0,
            'affected' => 0,
            'repaired' => 0,
            'skipped' => 0,
            'locale_slugs_regenerated' => 0,
            'redirects_created' => 0,
            'invalid_cross_locale_redirects_removed' => 0,
            'canonical_urls_fixed' => 0,
            'items' => [],
        ];

        $contents = Content::query()
            ->with(['currentVersion', 'translationSourceContent.currentVersion'])
            ->where('type', 'article')
            ->when($contentId, fn ($query) => $query->where('id', $contentId))
            ->when($siteId, fn ($query) => $query->where('client_site_id', $siteId))
            ->when($locale, fn ($query) => $query->where('language', $locale))
            ->orderBy('id')
            ->get();

        $report['scanned'] = $contents->count();

        foreach ($contents as $content) {
            $currentSlug = $slugs->persistedSlug($content);
            $expectedSlug = $this->uniqueSlugForContent($content, $slugs->expectedSlug($content), $currentSlug);
            $needsSlugRepair = $slugs->needsLocaleRepair($content, $forceSlugRegenerate);
            $canonicalUrl = $redirectService->blogUrl($content->localeCode(), $expectedSlug);
            $needsCanonicalRepair = $fixCanonical && trim((string) $content->seo_canonical) !== $canonicalUrl;

            if (! $needsSlugRepair && ! $needsCanonicalRepair) {
                $report['skipped']++;
                continue;
            }

            $report['affected']++;
            $report['items'][] = [
                'reason' => $needsSlugRepair ? 'wrong_locale_slug' : 'canonical_mismatch',
                'locale' => $content->localeCode(),
                'old' => $currentSlug,
                'new' => $expectedSlug,
                'content' => (string) $content->id,
            ];

            if (! $fix) {
                continue;
            }

            DB::transaction(function () use (
                $content,
                $currentSlug,
                $expectedSlug,
                $canonicalUrl,
                $needsSlugRepair,
                $needsCanonicalRepair,
                $redirectService,
                $cacheInvalidation,
                &$report
            ): void {
                /** @var Content $locked */
                $locked = Content::query()->lockForUpdate()->findOrFail($content->id);
                $updates = [];

                if ($needsSlugRepair) {
                    $updates['publish_url_key'] = $expectedSlug;
                    $updates['canonical_url_key'] = $expectedSlug;
                }

                if ($needsCanonicalRepair || $needsSlugRepair) {
                    $updates['seo_canonical'] = $canonicalUrl;

                    $publishedUrl = trim((string) ($locked->published_url ?? ''));
                    if ($publishedUrl === '' || $this->isBlogUrlForSlug($publishedUrl, $currentSlug, $redirectService)) {
                        $updates['published_url'] = $canonicalUrl;
                    }
                }

                if ($updates !== []) {
                    $locked->forceFill($updates)->save();
                }

                if ($needsSlugRepair) {
                    $this->syncVersionSlugMeta($locked, $expectedSlug);

                    if ($currentSlug !== '' && $currentSlug !== $expectedSlug && $this->ensureSameLocaleRedirect($redirectService, $locked, $currentSlug, $expectedSlug)) {
                        $report['redirects_created']++;
                    }

                    $report['locale_slugs_regenerated']++;
                }

                if ($needsCanonicalRepair || $needsSlugRepair) {
                    $report['canonical_urls_fixed']++;
                }

                $report['repaired']++;
                $cacheInvalidation->invalidateContent($locked, 'content.localized_canonical_repaired');
            });
        }

        $this->repairInvalidCrossLocaleRedirects(
            $publicationState,
            $redirectService,
            $fixCanonical,
            $fix,
            $contentId,
            $siteId,
            $locale,
            $report
        );

        $this->outputReport($report, $fix);

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $report
     */
    private function repairInvalidCrossLocaleRedirects(
        ContentPublicationStateService $publicationState,
        MarketingBlogRedirectService $redirectService,
        bool $fixCanonical,
        bool $fix,
        ?string $contentId,
        ?string $siteId,
        ?string $locale,
        array &$report,
    ): void {
        $query = MarketingBlogRedirect::query()
            ->with('targetContent.localizedVariants')
            ->where('is_active', true)
            ->where('redirect_kind', 'legacy_locale_mismatch')
            ->whereColumn('source_locale', '!=', 'target_locale')
            ->when($contentId, fn ($redirectQuery) => $redirectQuery->where('target_content_id', $contentId))
            ->when($locale, fn ($redirectQuery) => $redirectQuery->where('source_locale', $locale));

        $redirects = $query->get();

        if ($siteId) {
            $redirects = $redirects->filter(fn (MarketingBlogRedirect $redirect): bool => (string) $redirect->targetContent?->client_site_id === $siteId);
        }

        $this->info(sprintf('Found %d cross-locale redirects to scan.', $redirects->count()));
        $this->newLine();

        foreach ($redirects as $redirect) {
            $sourceContent = $redirect->targetContent;
            if (! $sourceContent) {
                continue;
            }

            $sourceLocale = (string) $redirect->source_locale;
            $variant = $sourceContent->localizedVariantFor($sourceLocale);
            if (! $variant || ! $publicationState->isPublished($variant)) {
                continue;
            }

            $report['affected']++;
            $report['items'][] = [
                'reason' => 'invalid_cross_locale_redirect',
                'locale' => $sourceLocale,
                'old' => (string) $redirect->source_path,
                'new' => (string) $redirect->target_path,
                'content' => (string) $variant->id,
            ];

            if (! $fix) {
                continue;
            }

            DB::transaction(function () use ($redirect, $variant, $sourceLocale, $redirectService, $fixCanonical, &$report): void {
                $meta = is_array($redirect->meta) ? $redirect->meta : [];
                $meta['superseded_reason'] = 'repair_command';
                $meta['superseded_at'] = now()->toIso8601String();
                $meta['superseded_by_content_id'] = (string) $variant->id;

                $redirect->forceFill([
                    'is_active' => false,
                    'meta' => $meta,
                ])->save();

                $report['repaired']++;
                $report['invalid_cross_locale_redirects_removed']++;

                if ($fixCanonical) {
                    $this->repairVariantCanonical($variant, $sourceLocale, $redirectService, $report);
                }
            });
        }
    }

    private function uniqueSlugForContent(Content $content, string $baseSlug, string $currentSlug = ''): string
    {
        $baseSlug = $baseSlug !== '' ? $baseSlug : 'post';
        $slug = $baseSlug;
        $suffix = 2;

        while (
            Content::query()
                ->where('id', '!=', (string) $content->id)
                ->where('language', $content->localeCode())
                ->where('publish_url_key', $slug)
                ->when($content->workspace_id, fn ($query) => $query->where('workspace_id', $content->workspace_id))
                ->when($content->client_site_id, fn ($query) => $query->where('client_site_id', $content->client_site_id))
                ->exists()
        ) {
            if ($slug === $currentSlug) {
                return $slug;
            }

            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    private function syncVersionSlugMeta(Content $content, string $slug): void
    {
        ContentVersion::query()
            ->where('content_id', (string) $content->id)
            ->where('id', (string) $content->current_version_id)
            ->get()
            ->each(function (ContentVersion $version) use ($slug): void {
                $meta = is_array($version->meta) ? $version->meta : [];
                $meta['slug'] = $slug;
                data_set($meta, 'seo.slug', $slug);
                $version->forceFill(['meta' => $meta])->save();
            });

        ContentRevision::query()
            ->where('content_id', (string) $content->id)
            ->where('is_active', true)
            ->get()
            ->each(function (ContentRevision $revision) use ($slug): void {
                $meta = is_array($revision->meta) ? $revision->meta : [];
                $meta['slug'] = $slug;
                data_set($meta, 'seo.slug', $slug);
                $revision->forceFill(['meta' => $meta])->save();
            });
    }

    private function ensureSameLocaleRedirect(
        MarketingBlogRedirectService $redirectService,
        Content $content,
        string $oldSlug,
        string $newSlug,
    ): bool {
        $locale = $content->localeCode();
        $payload = [
            'source_path' => $redirectService->blogPath($locale, $oldSlug),
            'source_locale' => $locale,
            'source_slug' => $oldSlug,
            'target_path' => $redirectService->blogPath($locale, $newSlug),
            'target_locale' => $locale,
            'target_slug' => $newSlug,
            'target_content_id' => (string) $content->id,
            'redirect_kind' => 'localized_slug_repair',
            'is_active' => true,
            'meta' => [
                'reason' => 'localized_slug_repair',
                'repaired_at' => now()->toIso8601String(),
            ],
        ];

        $existing = MarketingBlogRedirect::query()
            ->where('source_path', $payload['source_path'])
            ->first();

        $changed = ! $existing
            || $existing->target_path !== $payload['target_path']
            || $existing->target_locale !== $payload['target_locale']
            || $existing->target_slug !== $payload['target_slug']
            || ! $existing->is_active;

        MarketingBlogRedirect::query()->updateOrCreate(
            ['source_path' => $payload['source_path']],
            $payload
        );

        return $changed;
    }

    private function isBlogUrlForSlug(string $url, string $slug, MarketingBlogRedirectService $redirectService): bool
    {
        return $slug !== '' && $redirectService->resolveBlogRouteLocale($url, $slug) !== null;
    }

    /**
     * @param array<string,mixed> $report
     */
    private function repairVariantCanonical(
        Content $variant,
        string $locale,
        MarketingBlogRedirectService $redirectService,
        array &$report
    ): void {
        $slug = trim((string) $variant->publish_url_key);
        if ($slug === '') {
            return;
        }

        $expectedCanonicalUrl = $redirectService->blogUrl($locale, $slug);

        if (trim((string) $variant->seo_canonical) !== $expectedCanonicalUrl) {
            $variant->forceFill([
                'seo_canonical' => $expectedCanonicalUrl,
            ])->save();

            $report['canonical_urls_fixed']++;
        }
    }

    /**
     * @param array<string,mixed> $report
     */
    private function outputReport(array $report, bool $fix): void
    {
        $this->newLine();
        $this->info('=== Report ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Scanned', $report['scanned']],
                ['Affected', $report['affected']],
                ['Repaired', $report['repaired']],
                ['Skipped', $report['skipped']],
                ['Locale slugs regenerated', $report['locale_slugs_regenerated']],
                ['Redirects created', $report['redirects_created']],
                ['Invalid cross locale redirects removed', $report['invalid_cross_locale_redirects_removed']],
                ['Canonical URLs fixed', $report['canonical_urls_fixed']],
            ]
        );

        if ($report['affected'] > 0 && count($report['items']) > 0) {
            $this->newLine();
            $this->info('=== Affected Records ===');
            $this->table(
                ['Reason', 'Locale', 'Old', 'New', 'Content'],
                collect($report['items'])->map(fn (array $item): array => [
                    (string) ($item['reason'] ?? ''),
                    strtoupper((string) ($item['locale'] ?? '')),
                    (string) ($item['old'] ?? ''),
                    (string) ($item['new'] ?? ''),
                    (string) ($item['content'] ?? ''),
                ])->all()
            );
        }

        $this->newLine();

        if ($fix) {
            $this->info(sprintf(
                'Completed. Regenerated %d locale slugs, created %d redirects, removed %d invalid cross-locale redirects, fixed %d canonical URLs.',
                $report['locale_slugs_regenerated'],
                $report['redirects_created'],
                $report['invalid_cross_locale_redirects_removed'],
                $report['canonical_urls_fixed']
            ));

            return;
        }

        if ($report['affected'] > 0) {
            $this->warn(sprintf(
                'Dry run only. Found %d affected records. Re-run with --fix to apply changes.',
                $report['affected']
            ));

            return;
        }

        $this->info('No localized canonical issues found.');
    }
}
