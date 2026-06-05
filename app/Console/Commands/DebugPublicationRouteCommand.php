<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\MarketingBlogRedirect;
use App\Services\Content\LocalizedContentSlugService;
use App\Services\Publication\ContentPublicationStateService;
use App\Services\PublicBlog\PublicBlogService;
use App\Support\LocalizedMarketingUrl;
use Illuminate\Console\Command;

class DebugPublicationRouteCommand extends Command
{
    protected $signature = 'content:debug-publication-route {--content-id= : Content UUID to inspect}';

    protected $description = 'Inspect public blog route resolution for a localized content publication.';

    public function handle(
        ContentPublicationStateService $publicationState,
        LocalizedContentSlugService $slugs,
        PublicBlogService $blog,
    ): int {
        $contentId = trim((string) $this->option('content-id'));

        if ($contentId === '') {
            $this->error('Provide --content-id=<uuid>.');

            return self::FAILURE;
        }

        $content = Content::query()
            ->with(['currentVersion', 'publications', 'clientSite'])
            ->find($contentId);

        if (! $content instanceof Content) {
            $this->error("Content not found: {$contentId}");

            return self::FAILURE;
        }

        $publication = $publicationState->resolveCanonicalPublication(
            $content,
            provider: ContentPublication::PROVIDER_LARAVEL,
        );

        $locale = $content->localeCode();
        $draftSlug = $slugs->persistedSlug($content);
        $publicationSlug = $this->slugFromPublication($publication);
        $liveSlug = $draftSlug ?: $publicationSlug;
        $canonicalPath = (string) parse_url((string) ($content->seo_canonical ?? ''), PHP_URL_PATH);
        $routePath = LocalizedMarketingUrl::route('public.blog.show', ['slug' => $liveSlug], $locale, false);
        $resolved = $liveSlug !== '' ? $blog->getPostBySlug($liveSlug, $locale) : null;
        $redirects = $this->redirectRows($content, $locale, $liveSlug);
        $mismatches = $this->mismatches($content, $publication, $resolved, $liveSlug, $canonicalPath);

        $this->info('Target content');
        $this->table(['Field', 'Value'], [
            ['content id', (string) $content->id],
            ['locale', $locale],
            ['current draft slug', $draftSlug],
            ['current live slug', $liveSlug],
            ['publication remote slug', $publicationSlug],
            ['canonical path', $canonicalPath],
            ['publication status', $publication ? (string) $publication->delivery_status : 'none'],
            ['remote state', $publication ? (string) ($publication->remote_status ?? '') : 'none'],
            ['latest revision status', (string) $content->status],
            ['current version', (string) ($content->currentVersion?->type ?? 'none')],
            ['public route path', $routePath],
        ]);

        $this->info('Route mapping entry used by the public site');
        if (is_array($resolved)) {
            $this->table(['Field', 'Value'], [
                ['resolved', 'yes'],
                ['resolved content id', (string) ($resolved['id'] ?? '')],
                ['resolved locale', (string) ($resolved['locale'] ?? '')],
                ['resolved slug', (string) ($resolved['slug'] ?? '')],
                ['resolved title', (string) ($resolved['title'] ?? '')],
            ]);
        } else {
            $this->warn('No public blog mapping entry resolved for this locale/slug.');
        }

        $this->info('Redirect entries');
        if ($redirects === []) {
            $this->line('None');
        } else {
            $this->table(
                ['Source', 'Target', 'Source locale', 'Target locale', 'Active'],
                $redirects
            );
        }

        $this->info('Resolver checks');
        $this->table(['Check', 'Result'], [
            ['public resolver can resolve slug', is_array($resolved) ? 'yes' : 'no'],
            ['route exists on public domain', is_array($resolved) ? 'yes' : 'no'],
            ['content_publications vs route mapping mismatch', $mismatches === [] ? 'no' : 'yes'],
        ]);

        if ($mismatches !== []) {
            $this->warn('Mismatches');
            foreach ($mismatches as $mismatch) {
                $this->line('- '.$mismatch);
            }
        }

        return self::SUCCESS;
    }

    private function slugFromPublication(?ContentPublication $publication): string
    {
        if (! $publication instanceof ContentPublication) {
            return '';
        }

        $path = (string) parse_url((string) ($publication->remote_url ?? ''), PHP_URL_PATH);

        return trim((string) basename($path), '/');
    }

    /**
     * @return array<int,array<int,string>>
     */
    private function redirectRows(Content $content, string $locale, string $slug): array
    {
        return MarketingBlogRedirect::query()
            ->where(function ($query) use ($content, $locale, $slug): void {
                $query->where('target_content_id', (string) $content->id)
                    ->orWhere(function ($sourceQuery) use ($locale, $slug): void {
                        $sourceQuery->where('source_locale', $locale)
                            ->where('source_slug', $slug);
                    });
            })
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->map(fn (MarketingBlogRedirect $redirect): array => [
                (string) $redirect->source_path,
                (string) $redirect->target_path,
                (string) $redirect->source_locale,
                (string) $redirect->target_locale,
                $redirect->is_active ? 'yes' : 'no',
            ])
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function mismatches(Content $content, ?ContentPublication $publication, ?array $resolved, string $liveSlug, string $canonicalPath): array
    {
        $mismatches = [];

        if ($publication instanceof ContentPublication
            && (string) $publication->delivery_status === ContentPublication::STATUS_DELIVERED
            && ! is_array($resolved)) {
            $mismatches[] = 'publication_delivered_but_public_resolver_misses_slug';
        }

        if (is_array($resolved) && (string) ($resolved['id'] ?? '') !== (string) $content->id) {
            $mismatches[] = 'resolver_slug_points_to_different_content';
        }

        $publicationSlug = $this->slugFromPublication($publication);

        if ($canonicalPath !== '' && $liveSlug !== '' && ! str_ends_with($canonicalPath, '/'.$liveSlug)) {
            $mismatches[] = 'canonical_path_slug_differs_from_live_slug';
        }

        if ($publicationSlug !== '' && $liveSlug !== '' && $publicationSlug !== $liveSlug) {
            $mismatches[] = 'publication_remote_url_slug_differs_from_content_live_slug';
        }

        if ((string) $content->status !== 'published' && $publication instanceof ContentPublication && (string) $publication->delivery_status === ContentPublication::STATUS_DELIVERED) {
            $mismatches[] = 'latest_revision_status_differs_from_live_publication_state';
        }

        return $mismatches;
    }
}
