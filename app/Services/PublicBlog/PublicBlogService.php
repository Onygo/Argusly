<?php

namespace App\Services\PublicBlog;

use App\Enums\SupportedLanguage;
use App\Contracts\PublicBlogSource;
use App\Exceptions\PublicBlogSourceUnavailableException;
use App\Models\Content;
use App\Models\ContentImage;
use App\Models\ContentPublication;
use App\Models\MarketingBlogRedirect;
use App\Services\Content\ContentCacheInvalidationService;
use App\Services\Publication\ContentPublicationStateService;
use App\Services\Seo\CanonicalUrlService;
use App\Support\LocalizedMarketingUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Cache\TaggableStore;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Support\SafeMarkdownRenderer;
use Throwable;

class PublicBlogService
{
    private const CACHE_TTL_MINUTES = 15;
    private const CACHE_VERSION = 'v4';

    public function __construct(
        private readonly PublicBlogSource $source,
        private readonly MarketingBlogSourceScope $sourceScope,
        private readonly CanonicalUrlService $canonicals,
        private readonly SafeMarkdownRenderer $markdownRenderer,
    ) {
    }

    public function listPublishedPosts(int $page = 1, int $perPage = 12, array $filters = [], ?string $locale = null): LengthAwarePaginator
    {
        $locale = $this->normalizeLocale($locale);
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $tag = trim((string) ($filters['tag'] ?? ''));
        $category = trim((string) ($filters['category'] ?? ''));

        $localPaginator = $this->listPublishedPostsFromLocal($locale, $page, $perPage, $tag, $category);
        if ($localPaginator instanceof LengthAwarePaginator) {
            return $localPaginator;
        }

        $payload = $this->remember(
            $this->listCacheKey($locale, $page, $perPage, $tag, $category),
            $this->localeCacheTags($locale),
            function () use ($locale, $page, $perPage, $tag, $category): array {
                $posts = $this->filteredPosts($locale, $tag, $category);
                $total = $posts->count();
                $items = $posts->forPage($page, $perPage)->values()->all();

                return [
                    'items' => $items,
                    'total' => $total,
                ];
            }
        );

        return new LengthAwarePaginator(
            $payload['items'] ?? [],
            (int) ($payload['total'] ?? 0),
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'query' => request()->query(),
            ]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getPostBySlug(string $slug, ?string $locale = null): ?array
    {
        $locale = $this->normalizeLocale($locale);
        $slug = Str::slug($slug);

        return $this->remember(
            $this->postCacheKey($locale, $slug),
            $this->localeCacheTags($locale),
            function () use ($locale, $slug): ?array {
                return $this->postsCollection($locale)
                    ->first(function (array $post) use ($slug): bool {
                        return (string) ($post['slug'] ?? '') === $slug;
                    });
            }
        );
    }

    public function legacyRedirectUrlForSlug(string $slug, ?string $locale = null): ?string
    {
        $locale = $this->normalizeLocale($locale);
        $slug = Str::slug($slug);

        return $this->remember(
            $this->redirectCacheKey($locale, $slug),
            $this->localeCacheTags($locale),
            function () use ($locale, $slug): ?string {
                $redirect = MarketingBlogRedirect::query()
                    ->active()
                    ->forSource($locale, $slug)
                    ->first();

                if (! $redirect) {
                    return null;
                }

                // Skip cross-locale redirects if a published translation exists for this locale.
                // Same-locale redirects (old slug → new slug) always work.
                if ($redirect->source_locale !== $redirect->target_locale
                    && $redirect->target_content_id
                    && $this->hasPublishedVariantForLocale($redirect->target_content_id, $locale)) {
                    return null;
                }

                return (string) $redirect->target_path;
            }
        );
    }

    /**
     * Check if a content family has a published variant for the given locale.
     * Used to determine if a cross-locale redirect should be skipped.
     */
    private function hasPublishedVariantForLocale(string $contentId, string $locale): bool
    {
        return Cache::remember(
            sprintf('redirect_locale_check.%s.%s', $contentId, $locale),
            now()->addMinutes(5),
            function () use ($contentId, $locale): bool {
                $content = Content::find($contentId);
                if (! $content) {
                    return false;
                }

                $variant = $content->localizedVariantFor($locale);
                if (! $variant) {
                    return false;
                }

                return app(ContentPublicationStateService::class)->isPublished($variant);
            }
        );
    }

    /**
     * @return array<int,string>
     */
    public function listTags(?string $locale = null): array
    {
        $locale = $this->normalizeLocale($locale);

        $localTags = $this->listTaxonomyFromLocal($locale, 'tags');
        if ($localTags !== null) {
            return $localTags;
        }

        /** @var array<int,string> $tags */
        $tags = $this->remember(
            $this->taxonomyCacheKey('tags', $locale),
            $this->localeCacheTags($locale),
            function () use ($locale): array {
                return $this->postsCollection($locale)
                    ->flatMap(fn (array $post) => $post['tags'] ?? [])
                    ->map(fn ($entry) => trim((string) $entry))
                    ->filter()
                    ->unique(fn (string $entry) => Str::slug($entry))
                    ->sort()
                    ->values()
                    ->all();
            }
        );

        return $tags;
    }

    /**
     * @return array<int,string>
     */
    public function listCategories(?string $locale = null): array
    {
        $locale = $this->normalizeLocale($locale);

        $localCategories = $this->listTaxonomyFromLocal($locale, 'categories');
        if ($localCategories !== null) {
            return $localCategories;
        }

        /** @var array<int,string> $categories */
        $categories = $this->remember(
            $this->taxonomyCacheKey('categories', $locale),
            $this->localeCacheTags($locale),
            function () use ($locale): array {
                return $this->postsCollection($locale)
                    ->flatMap(fn (array $post) => $post['categories'] ?? [])
                    ->map(fn ($entry) => trim((string) $entry))
                    ->filter()
                    ->unique(fn (string $entry) => Str::slug($entry))
                    ->sort()
                    ->values()
                    ->all();
            }
        );

        return $categories;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function latestPosts(int $limit = 20, ?string $locale = null): array
    {
        $locale = $this->normalizeLocale($locale);
        $limit = max(1, min(100, $limit));

        $localQuery = $this->baseLocalPublishedQuery($locale);
        if ($localQuery instanceof Builder && (clone $localQuery)->limit(1)->exists()) {
            return $this->deduplicatePublicCards($localQuery
                ->get()
                ->map(fn (Content $content): array => $this->mapLocalCard($content))
                ->filter(fn (array $post): bool => $this->isEligiblePublicPost($post))
                ->values())
                ->take($limit)
                ->values()
                ->all();
        }

        return $this->deduplicatePublicCards($this->postsCollection($locale))
            ->take($limit)
            ->values()
            ->all();
    }

    public function publicUrl(array $post, ?string $locale = null): string
    {
        $resolvedLocale = $this->normalizeLocale($locale ?: (string) ($post['locale'] ?? ''));
        $slug = trim((string) ($post['slug'] ?? ''));

        return $this->canonicals->publicBlogCanonical($slug, $resolvedLocale);
    }

    public function localizedUrlForLinkedSlug(string $slug, ?string $locale = null): ?string
    {
        $locale = $this->normalizeLocale($locale);
        $slug = Str::slug($slug);

        if ($slug === '') {
            return null;
        }

        $sameLocalePost = $this->getPostBySlug($slug, $locale);
        if (is_array($sameLocalePost)) {
            return $this->publicUrl($sameLocalePost, $locale);
        }

        foreach (SupportedLanguage::values() as $candidateLocale) {
            $post = $this->getPostBySlug($slug, $candidateLocale);
            if (! is_array($post)) {
                continue;
            }

            $variant = (array) data_get($post, 'localized_variants.' . $locale, []);
            $variantSlug = Str::slug((string) ($variant['slug'] ?? ''));

            return $variantSlug !== ''
                ? $this->canonicals->publicBlogCanonical($variantSlug, $locale)
                : null;
        }

        return null;
    }

    public function xmlCacheScopeSegment(): string
    {
        return $this->scopeCacheKeySegment();
    }

    public function xmlLocaleVersion(string $locale): int
    {
        return $this->localeVersionToken($this->normalizeLocale($locale));
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<string,string>
     */
    public function alternateLocaleUrls(array $post): array
    {
        return $this->canonicals->publicBlogAlternates($post, $this->normalizeLocale((string) ($post['locale'] ?? '')));
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function filteredPosts(string $locale, string $tag = '', string $category = ''): Collection
    {
        $posts = $this->postsCollection($locale);

        if ($tag !== '') {
            $tagSlug = Str::slug($tag);
            $posts = $posts->filter(function (array $post) use ($tagSlug): bool {
                $tags = collect((array) ($post['tags'] ?? []))->map(fn ($item) => Str::slug((string) $item));

                return $tags->contains($tagSlug);
            })->values();
        }

        if ($category !== '') {
            $categorySlug = Str::slug($category);
            $posts = $posts->filter(function (array $post) use ($categorySlug): bool {
                $categories = collect((array) ($post['categories'] ?? []))->map(fn ($item) => Str::slug((string) $item));

                return $categories->contains($categorySlug);
            })->values();
        }

        return $posts;
    }

    private function listPublishedPostsFromLocal(string $locale, int $page, int $perPage, string $tag, string $category): ?LengthAwarePaginator
    {
        $query = $this->baseLocalPublishedQuery($locale);
        if (! $query instanceof Builder) {
            return null;
        }

        if (! (clone $query)->limit(1)->exists()) {
            return null;
        }

        $payload = $this->remember(
            $this->listCacheKey($locale, $page, $perPage, $tag, $category),
            $this->localeCacheTags($locale),
            function () use ($query, $page, $perPage, $tag, $category): array {
                $working = clone $query;

                if ($category !== '') {
                    $working->where('public_blog_category', $category);
                }

                if ($tag !== '') {
                    $working->whereJsonContains('public_blog_tags', $tag);
                }

                $items = $this->deduplicatePublicCards($working
                    ->get()
                    ->map(fn (Content $content): array => $this->mapLocalCard($content))
                    ->filter(fn (array $post): bool => $this->isEligiblePublicPost($post))
                    ->values());
                $total = $items->count();

                return [
                    'items' => $items->forPage($page, $perPage)->values()->all(),
                    'total' => $total,
                ];
            }
        );

        return new LengthAwarePaginator(
            $payload['items'] ?? [],
            (int) ($payload['total'] ?? 0),
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'query' => request()->query(),
            ]
        );
    }

    /**
     * @return array<int,string>|null
     */
    private function listTaxonomyFromLocal(string $locale, string $type): ?array
    {
        $query = $this->baseLocalPublishedQuery($locale);
        if (! $query instanceof Builder) {
            return null;
        }

        if (! (clone $query)->limit(1)->exists()) {
            return null;
        }

        /** @var array<int,string> $taxonomy */
        $taxonomy = $this->remember(
            $this->taxonomyCacheKey($type, $locale),
            $this->localeCacheTags($locale),
            function () use ($query, $type): array {
                $working = clone $query;

                if ($type === 'categories') {
                    return $working
                        ->whereNotNull('public_blog_category')
                        ->pluck('public_blog_category')
                        ->map(fn ($entry): string => trim((string) $entry))
                        ->filter()
                        ->unique(fn (string $entry): string => Str::lower($entry))
                        ->sort()
                        ->values()
                        ->all();
                }

                $tags = [];
                $working
                    ->select(['id', 'public_blog_tags'])
                    ->reorder('id')
                    ->chunkById(200, function ($rows) use (&$tags): void {
                        foreach ($rows as $row) {
                            foreach ((array) ($row->public_blog_tags ?? []) as $tag) {
                                $tag = trim((string) $tag);
                                if ($tag !== '') {
                                    $tags[] = $tag;
                                }
                            }
                        }
                    });

                return collect($tags)
                    ->unique(fn (string $entry): string => Str::lower($entry))
                    ->sort()
                    ->values()
                    ->all();
            }
        );

        return $taxonomy;
    }

    private function baseLocalPublishedQuery(string $locale): ?Builder
    {
        if (! $this->supportsLocalPerformancePath()) {
            return null;
        }

        $scope = $this->sourceScope->resolve();
        $scopeColumn = $scope ? $this->sourceScope->localColumnForMode($scope['mode']) : null;
        if (! $scope || ! $scopeColumn) {
            return null;
        }

        return Content::query()
            ->with(['currentVersion' => fn ($versionQuery) => $versionQuery->select([
                'content_versions.id',
                'content_versions.content_id',
                'content_versions.meta',
            ]), 'featuredImage' => fn ($imageQuery) => $imageQuery->select([
                'content_images.id',
                'content_images.workspace_id',
                'content_images.content_id',
                'content_images.type',
                'content_images.source',
                'content_images.image_path',
                'content_images.image_url',
                'content_images.alt_text',
                'content_images.original_path',
                'content_images.medium_path',
                'content_images.thumbnail_path',
                'content_images.original_webp_path',
                'content_images.medium_webp_path',
                'content_images.thumbnail_webp_path',
                'content_images.width',
                'content_images.height',
                'content_images.status',
                'content_images.is_active',
                'content_images.display_on_website',
                'content_images.display_as_featured_image',
                'content_images.use_as_meta_image',
                'content_images.use_as_social_image',
                'content_images.use_for_linkedin',
            ]), 'ogImage' => fn ($imageQuery) => $imageQuery->select([
                'content_images.id',
                'content_images.content_id',
                'content_images.type',
                'content_images.image_path',
                'content_images.image_url',
                'content_images.original_path',
                'content_images.medium_path',
                'content_images.original_webp_path',
                'content_images.medium_webp_path',
                'content_images.status',
                'content_images.is_active',
                'content_images.use_as_meta_image',
                'content_images.created_at',
            ])])
            ->withExists([
                'images as has_managed_featured_image_history' => fn ($imageQuery) => $imageQuery
                    ->withTrashed()
                    ->where('content_images.type', 'featured'),
            ])
            ->select([
                'id',
                'current_version_id',
                'title',
                'language',
                'publish_url_key',
                'canonical_url_key',
                'published_url',
                'first_published_at',
                'public_blog_excerpt',
                'public_blog_reading_time_minutes',
                'public_blog_author',
                'public_blog_category',
                'public_blog_tags',
                'public_blog_featured_image_url',
                'public_blog_featured_image_width',
                'public_blog_featured_image_height',
                'seo_og_image',
                'created_at',
                'updated_at',
            ])
            ->where($scopeColumn, $scope['id'])
            ->where('type', 'article')
            ->where('language', $locale)
            ->where('status', 'published')
            ->where('publish_status', 'published')
            ->where(function ($publicationQuery) use ($locale): void {
                $publicationQuery
                    ->whereDoesntHave('publications')
                    ->orWhereHas('publications', function ($query) use ($locale): void {
                        $query
                            ->where('provider', ContentPublication::PROVIDER_LARAVEL)
                            ->where('delivery_status', ContentPublication::STATUS_DELIVERED)
                            ->where(function ($statusQuery): void {
                                $statusQuery
                                    ->where('remote_status', ContentPublication::REMOTE_PUBLISHED)
                                    ->orWhereNull('remote_status');
                            })
                            ->where(function ($localeQuery) use ($locale): void {
                                $localeQuery
                                    ->whereNull('locale')
                                    ->orWhere('locale', '')
                                    ->orWhere('locale', $locale);
                            });
                    });
            })
            ->where(function ($publishedAtQuery): void {
                $publishedAtQuery
                    ->whereNull('first_published_at')
                    ->orWhere('first_published_at', '<=', now());
            })
            ->where(function ($slugQuery): void {
                $slugQuery
                    ->whereNotNull('publish_url_key')
                    ->where('publish_url_key', '<>', '')
                    ->orWhere(function ($canonicalQuery): void {
                        $canonicalQuery
                            ->whereNotNull('canonical_url_key')
                            ->where('canonical_url_key', '<>', '');
                    })
                    ->orWhere(function ($publishedUrlQuery): void {
                        $publishedUrlQuery
                            ->whereNotNull('published_url')
                            ->where('published_url', '<>', '');
                    })
                    ->orWhere(function ($titleQuery): void {
                        $titleQuery
                            ->whereNotNull('title')
                            ->where('title', '<>', '');
                    });
            })
            ->orderByRaw('COALESCE(first_published_at, updated_at, created_at) DESC')
            ->orderByDesc('id');
    }

    private function supportsLocalPerformancePath(): bool
    {
        return Schema::hasTable('contents')
            && Schema::hasColumns('contents', [
                'language',
                'publish_url_key',
                'first_published_at',
                'public_blog_excerpt',
                'public_blog_reading_time_minutes',
                'public_blog_author',
                'public_blog_category',
                'public_blog_tags',
                'public_blog_featured_image_url',
                'public_blog_featured_image_width',
                'public_blog_featured_image_height',
            ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function mapLocalCard(Content $content): array
    {
        $versionMeta = is_array($content->currentVersion?->meta) ? $content->currentVersion->meta : [];
        $title = trim((string) data_get($versionMeta, 'title', '')) ?: (string) $content->title;
        $slug = trim((string) ($content->publish_url_key
            ?: $content->canonical_url_key
            ?: $this->slugFromUrl((string) $content->published_url)
            ?: data_get($versionMeta, 'slug', '')
            ?: Str::slug($title)));
        $locale = $this->normalizeLocale($content->localeCode());
        $publishedAt = $this->localPublishedAt($content, $versionMeta);
        $hasManagedFeaturedImageHistory = (bool) $content->getAttribute('has_managed_featured_image_history');
        $activeFeaturedImageUrl = $content->featuredImage?->bestUrlForUsage(ContentImage::USAGE_WEBSITE) ?? '';
        $featuredImage = $this->normalizePublicImageUrl($hasManagedFeaturedImageHistory
            ? $activeFeaturedImageUrl
            : $this->firstNonEmpty([$activeFeaturedImageUrl, $content->public_blog_featured_image_url]));

        return [
            'id' => (string) $content->id,
            'slug' => $slug,
            'url' => $this->canonicals->publicBlogCanonical($slug, $locale),
            'title' => $title,
            'excerpt' => trim((string) ($content->public_blog_excerpt ?? '')) ?: trim((string) data_get($versionMeta, 'excerpt', '')),
            'featured_image' => $featuredImage,
            'featured_image_alt' => trim((string) ($content->featuredImage?->alt_text ?? '')) ?: $title,
            'featured_image_width' => $content->public_blog_featured_image_width ?: $content->featuredImage?->width,
            'featured_image_height' => $content->public_blog_featured_image_height ?: $content->featuredImage?->height,
            'seo_og_image' => $this->normalizePublicImageUrl($this->firstNonEmpty([
                $content->ogImage?->bestUrlForUsage(ContentImage::USAGE_META),
                $content->seo_og_image,
            ])),
            'author' => trim((string) ($content->public_blog_author ?? '')),
            'published_at' => $publishedAt?->toIso8601String(),
            'published_at_ts' => $publishedAt?->timestamp ?? 0,
            'published_date' => $publishedAt?->format('Y-m-d'),
            'reading_time' => max(1, (int) ($content->public_blog_reading_time_minutes ?? 1)),
            'tags' => collect((array) ($content->public_blog_tags ?? []))
                ->map(fn ($tag): string => trim((string) $tag))
                ->filter()
                ->values()
                ->all(),
            'categories' => array_values(array_filter([trim((string) ($content->public_blog_category ?? ''))])),
            'category' => trim((string) ($content->public_blog_category ?? '')),
            'locale' => $locale,
        ];
    }

    private function slugFromUrl(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $slug = trim((string) basename($path), '/');

        return $slug !== '' ? Str::slug($slug) : '';
    }

    /**
     * @param array<string,mixed> $versionMeta
     */
    private function localPublishedAt(Content $content, array $versionMeta): ?Carbon
    {
        $candidates = [
            $content->first_published_at,
            data_get($versionMeta, 'published_at'),
            data_get($versionMeta, 'publish_at'),
            data_get($versionMeta, 'date_published'),
            $content->updated_at,
            $content->created_at,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate instanceof Carbon) {
                return $candidate;
            }

            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            try {
                return Carbon::parse($candidate);
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function postsCollection(string $locale): Collection
    {
        $all = $this->remember(
            sprintf('public_blog.posts.%s.%s', self::CACHE_VERSION, $this->scopeCacheKeySegment()),
            $this->scopeBaseCacheTags(),
            function (): array {
                try {
                    $raw = $this->source->fetchPublishedPosts();
                } catch (PublicBlogSourceUnavailableException $e) {
                    throw $e;
                }

                return collect($raw)
                    ->map(fn ($post) => $this->normalizePost((array) $post))
                    ->filter(fn ($post) => is_array($post) && $this->isEligiblePublicPost($post))
                    ->pipe(fn (Collection $posts): Collection => $this->deduplicateLocalizedPosts($posts))
                    ->pipe(fn (Collection $posts): Collection => $this->deduplicatePublicCards($posts))
                    ->pipe(fn (Collection $posts): Collection => $this->attachLocalizedVariants($posts))
                    ->sortByDesc(function (array $post): int {
                        return (int) ($post['published_at_ts'] ?? 0);
                    })
                    ->values()
                    ->all();
            }
        );

        return collect($all)
            ->filter(function (array $post) use ($locale): bool {
                $postLocale = strtolower(trim((string) ($post['locale'] ?? '')));
                return $postLocale === $locale;
            })
            ->values();
    }

    /**
     * @param Collection<int,array<string,mixed>> $posts
     * @return Collection<int,array<string,mixed>>
     */
    private function deduplicatePublicCards(Collection $posts): Collection
    {
        return $posts
            ->unique(fn (array $post): string => $this->publicTitleKey((string) ($post['title'] ?? '')) ?: 'id:' . (string) ($post['id'] ?? ''))
            ->values();
    }

    private function publicTitleKey(string $title): string
    {
        $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;

        return Str::lower(trim($title));
    }

    /**
     * @param array<int,mixed> $candidates
     */
    private function firstNonEmpty(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $value = trim((string) ($candidate ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private function normalizePost(array $post): array
    {
        $slug = Str::slug((string) ($post['slug'] ?? ''));
        $title = trim((string) ($post['title'] ?? 'Untitled'));
        $excerpt = trim((string) ($post['excerpt'] ?? ''));
        $contentRaw = (string) ($post['content'] ?? '');
        $format = strtolower(trim((string) ($post['content_format'] ?? 'html')));
        $contentHtml = $format === 'markdown' ? $this->safeMarkdown($contentRaw, $post) : $contentRaw;
        $safeHtml = $this->sanitizeHtml($contentHtml);
        $plainText = trim(strip_tags($safeHtml));

        if ($excerpt === '') {
            $excerpt = (string) Str::limit($plainText, 220, '…');
        }

        $publishedAtString = trim((string) ($post['published_at'] ?? ''));
        $publishedAt = null;
        if ($publishedAtString !== '') {
            try {
                $publishedAt = Carbon::parse($publishedAtString);
            } catch (\Throwable) {
                $publishedAt = null;
            }
        }

        $updatedAtString = trim((string) ($post['updated_at'] ?? ($post['publication_delivered_at'] ?? '')));
        $updatedAt = null;
        if ($updatedAtString !== '') {
            try {
                $updatedAt = Carbon::parse($updatedAtString);
            } catch (\Throwable) {
                $updatedAt = null;
            }
        }

        $wordCount = max(1, (int) str_word_count($plainText));
        $readingTime = max(1, (int) ceil($wordCount / 220));
        $locale = SupportedLanguage::tryFromString((string) ($post['locale'] ?? ''))?->value
            ?? (string) config('marketing_routing.default_locale', 'en');
        $slugSource = $slug !== '' ? $slug : Str::slug($title);
        $fallbackUrl = LocalizedMarketingUrl::route('public.blog.show', ['slug' => $slugSource], $locale);
        $publicUrl = $this->canonicals->normalizeOrFallback(
            trim((string) ($post['canonical_url'] ?? '')),
            $fallbackUrl
        );

        return [
            'id' => (string) ($post['id'] ?? ''),
            'translation_group' => trim((string) ($post['translation_group'] ?? ($post['translation_source_content_id'] ?? ($post['id'] ?? '')))),
            'slug' => $slug,
            'title' => $title,
            'excerpt' => $excerpt,
            'content_raw' => $contentRaw,
            'content_format' => $format,
            'content_markdown' => $format === 'markdown' ? $contentRaw : '',
            'content_html' => $safeHtml,
            'answer_blocks' => $this->normalizeAnswerBlocks($post['answer_blocks'] ?? []),
            'faq_schema' => $this->normalizeSchema($post['faq_schema'] ?? null),
            'featured_image' => $this->normalizePublicImageUrl((string) ($post['featured_image'] ?? '')),
            'featured_image_alt' => trim((string) ($post['featured_image_alt'] ?? ($post['featured_image_alt_text'] ?? ''))),
            'featured_image_width' => isset($post['featured_image_width']) ? (int) $post['featured_image_width'] : null,
            'featured_image_height' => isset($post['featured_image_height']) ? (int) $post['featured_image_height'] : null,
            'author' => trim((string) ($post['author'] ?? '')),
            'published_at' => $publishedAt?->toIso8601String(),
            'published_at_ts' => $publishedAt?->timestamp ?? 0,
            'published_date' => $publishedAt?->format('Y-m-d'),
            'reading_time' => $readingTime,
            'tags' => $this->normalizeTerms((array) ($post['tags'] ?? [])),
            'categories' => $this->normalizeTerms((array) ($post['categories'] ?? [])),
            'category' => $this->normalizeTerms((array) ($post['categories'] ?? []))[0] ?? '',
            'locale' => $locale,
            'meta_description' => trim((string) ($post['meta_description'] ?? ($post['seo_meta_description'] ?? ''))),
            'seo_title' => trim((string) ($post['seo_title'] ?? '')),
            'seo_meta_description' => trim((string) ($post['seo_meta_description'] ?? '')),
            'seo_og_title' => trim((string) ($post['seo_og_title'] ?? '')),
            'seo_og_description' => trim((string) ($post['seo_og_description'] ?? '')),
            'seo_og_image' => $this->normalizePublicImageUrl((string) ($post['seo_og_image'] ?? '')),
            'seo_twitter_title' => trim((string) ($post['seo_twitter_title'] ?? '')),
            'seo_twitter_description' => trim((string) ($post['seo_twitter_description'] ?? '')),
            'status' => trim((string) ($post['status'] ?? 'published')),
            'publish_status' => trim((string) ($post['publish_status'] ?? 'published')),
            'publication_id' => trim((string) ($post['publication_id'] ?? '')),
            'publication_delivered_at' => trim((string) ($post['publication_delivered_at'] ?? '')),
            'is_source_locale' => (bool) ($post['is_source_locale'] ?? false),
            'translation_source_locale' => trim((string) ($post['translation_source_locale'] ?? '')),
            'translation_generated_at' => trim((string) ($post['translation_generated_at'] ?? '')),
            'translation_source_updated_at' => trim((string) ($post['translation_source_updated_at'] ?? '')),
            'robots_index' => $post['robots_index'] ?? true,
            'robots_follow' => $post['robots_follow'] ?? true,
            'canonical_url' => $publicUrl,
            'updated_at' => $updatedAt?->toIso8601String(),
            'updated_at_ts' => $updatedAt?->timestamp ?? 0,
        ];
    }

    /**
     * @param array<string,mixed> $post
     */
    private function safeMarkdown(string $markdown, array $post): string
    {
        try {
            return $this->markdownRenderer->render($markdown);
        } catch (Throwable $exception) {
            Log::warning('public_blog.markdown_failed', [
                'content_id' => (string) ($post['id'] ?? ''),
                'slug' => (string) ($post['slug'] ?? ''),
                'locale' => (string) ($post['locale'] ?? ''),
                'message' => $exception->getMessage(),
            ]);

            return '<p>' . e($markdown) . '</p>';
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function normalizeAnswerBlocks(mixed $payload): array
    {
        $decoded = $this->decodeJsonPayload($payload);
        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->filter(fn ($item): bool => is_array($item)
                && trim((string) ($item['question'] ?? '')) !== ''
                && trim((string) ($item['answer'] ?? '')) !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function normalizeSchema(mixed $payload): ?array
    {
        $decoded = $this->decodeJsonPayload($payload);

        return is_array($decoded) && $decoded !== [] ? $decoded : null;
    }

    private function decodeJsonPayload(mixed $payload): mixed
    {
        if (is_array($payload) || $payload === null) {
            return $payload;
        }

        if (! is_string($payload) || trim($payload) === '') {
            return null;
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<int,mixed> $terms
     * @return array<int,string>
     */
    private function normalizeTerms(array $terms): array
    {
        return collect($terms)
            ->map(fn ($term) => trim((string) $term))
            ->filter()
            ->unique(fn (string $term) => Str::slug($term))
            ->values()
            ->all();
    }

    private function sanitizeHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $html = preg_replace('/<\s*(script|style|iframe|object|embed|form|input|button|textarea|select|meta|link)\b[^>]*>.*?<\s*\/\s*\\1\s*>/is', '', $html) ?? '';
        $html = preg_replace('/<\s*(script|style|iframe|object|embed|form|input|button|textarea|select|meta|link)\b[^>]*\/?>/is', '', $html) ?? '';
        $html = preg_replace('/\son\w+\s*=\s*(\"[^\"]*\"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s(href|src)\s*=\s*([\'"])\s*javascript:[^\'"]*\\2/i', '', $html) ?? '';

        return trim($html);
    }

    private function normalizePublicImageUrl(string $url): string
    {
        $url = trim($url);

        return $url !== '' ? ContentImage::publicUrlForStorageValue($url) : '';
    }

    private function normalizeLocale(?string $locale): string
    {
        return SupportedLanguage::fromStringOrDefault($locale ?: app()->getLocale())->value;
    }

    /**
     * @param  array<string,mixed>  $post
     */
    private function isEligiblePublicPost(array $post): bool
    {
        $slug = trim((string) ($post['slug'] ?? ''));
        $title = trim((string) ($post['title'] ?? ''));
        $locale = trim((string) ($post['locale'] ?? ''));
        $status = strtolower(trim((string) ($post['status'] ?? '')));
        $publishStatus = strtolower(trim((string) ($post['publish_status'] ?? '')));
        $publishedAtTs = (int) ($post['published_at_ts'] ?? 0);
        $hasDeliveredPublication = trim((string) ($post['publication_id'] ?? '')) !== ''
            || trim((string) ($post['publication_delivered_at'] ?? '')) !== '';

        if ($slug === '' || $title === '' || $locale === '') {
            return false;
        }

        if (! in_array($status, ['published', ''], true) && ! $hasDeliveredPublication) {
            return false;
        }

        if (! in_array($publishStatus, ['published', ''], true) && ! $hasDeliveredPublication) {
            return false;
        }

        if ($publishedAtTs <= 0 || $publishedAtTs > now()->timestamp) {
            return false;
        }

        return true;
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $posts
     * @return Collection<int,array<string,mixed>>
     */
    private function deduplicateLocalizedPosts(Collection $posts): Collection
    {
        return $posts
            ->groupBy(function (array $post): string {
                $locale = trim((string) ($post['locale'] ?? ''));
                $group = trim((string) ($post['translation_group'] ?? ''));
                $canonical = trim((string) ($post['canonical_url'] ?? ''));
                $slug = trim((string) ($post['slug'] ?? ''));

                return implode(':', [
                    $locale !== '' ? $locale : 'unknown',
                    $group !== '' ? $group : ($canonical !== '' ? sha1($canonical) : $slug),
                ]);
            })
            ->map(function (Collection $variants): array {
                return $variants
                    ->sortByDesc(function (array $post): array {
                        return [
                            (int) ($post['published_at_ts'] ?? 0),
                            (int) ($post['updated_at_ts'] ?? 0),
                            trim((string) ($post['canonical_url'] ?? '')) !== '' ? 1 : 0,
                            trim((string) ($post['id'] ?? '')),
                        ];
                    })
                    ->first();
            })
            ->values();
    }

    private function isSafePublicUrl(?string $url, string $locale): bool
    {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = '/' . ltrim((string) parse_url($url, PHP_URL_PATH), '/');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        if (str_contains($path, '/admin/') || str_contains($path, '/app/')) {
            return false;
        }

        return preg_match('#^/' . preg_quote($locale, '#') . '(/|$)#', $path) === 1;
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $posts
     * @return Collection<int,array<string,mixed>>
     */
    private function attachLocalizedVariants(Collection $posts): Collection
    {
        $groups = $posts
            ->groupBy(fn (array $post): string => (string) ($post['translation_group'] ?: $post['id']));

        return $posts->map(function (array $post) use ($groups): array {
            $groupKey = (string) ($post['translation_group'] ?: $post['id']);
            $variants = collect($groups->get($groupKey, collect()))
                ->filter(fn (array $variant): bool => trim((string) ($variant['locale'] ?? '')) !== '')
                ->mapWithKeys(fn (array $variant): array => [
                    (string) $variant['locale'] => [
                        'id' => (string) ($variant['id'] ?? ''),
                        'slug' => (string) ($variant['slug'] ?? ''),
                        'title' => (string) ($variant['title'] ?? ''),
                        'locale' => (string) ($variant['locale'] ?? ''),
                    ],
                ])
                ->all();

            $post['available_locales'] = array_keys($variants);
            $post['localized_variants'] = $variants;

            return $post;
        });
    }

    private function listCacheKey(string $locale, int $page, int $perPage, string $tag, string $category): string
    {
        $localeVersion = $this->localeVersionToken($locale);

        return sprintf(
            'public_blog.list.%s.%s.lv_%d.%s.page_%d.per_%d.tag_%s.category_%s',
            self::CACHE_VERSION,
            $this->scopeCacheKeySegment(),
            $localeVersion,
            $locale,
            $page,
            $perPage,
            Str::slug($tag) ?: 'all',
            Str::slug($category) ?: 'all'
        );
    }

    private function postCacheKey(string $locale, string $slug): string
    {
        return sprintf(
            'public_blog.post.%s.%s.lv_%d.%s.%s',
            self::CACHE_VERSION,
            $this->scopeCacheKeySegment(),
            $this->localeVersionToken($locale),
            $locale,
            Str::slug($slug)
        );
    }

    private function taxonomyCacheKey(string $type, string $locale): string
    {
        return sprintf(
            'public_blog.taxonomy.%s.%s.lv_%d.%s.%s',
            self::CACHE_VERSION,
            $this->scopeCacheKeySegment(),
            $this->localeVersionToken($locale),
            $type,
            $locale
        );
    }

    private function redirectCacheKey(string $locale, string $slug): string
    {
        return sprintf(
            'public_blog.redirect.%s.%s.lv_%d.%s.%s',
            self::CACHE_VERSION,
            $this->scopeCacheKeySegment(),
            $this->localeVersionToken($locale),
            $locale,
            Str::slug($slug)
        );
    }

    private function scopeCacheKeySegment(): string
    {
        $scope = $this->sourceScope->resolve();
        if (! $scope) {
            return 'unconfigured';
        }

        return $scope['mode'] . '_' . md5($scope['id']);
    }

    /**
     * @return array<int,string>
     */
    private function scopeBaseCacheTags(): array
    {
        return [
            'public_blog',
            ContentCacheInvalidationService::publicBlogBaseTag($this->scopeCacheKeySegment()),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function localeCacheTags(string $locale): array
    {
        return [
            'public_blog',
            ContentCacheInvalidationService::publicBlogLocaleTag($this->scopeCacheKeySegment(), $locale),
        ];
    }

    private function scopeVersionToken(): int
    {
        return max(1, (int) Cache::get(
            ContentCacheInvalidationService::publicBlogScopeVersionKey($this->scopeCacheKeySegment()),
            1
        ));
    }

    private function localeVersionToken(string $locale): int
    {
        return max(1, (int) Cache::get(
            ContentCacheInvalidationService::publicBlogLocaleVersionKey($this->scopeCacheKeySegment(), $locale),
            1
        ));
    }

    /**
     * @template T
     * @param callable():T $callback
     * @param  array<int,string>  $tags
     * @return T
     */
    private function remember(string $key, array $tags, callable $callback)
    {
        $versionedKey = $this->versionedKey($key, $tags);

        if ($this->supportsTags() && $tags !== []) {
            return Cache::tags($tags)->remember($versionedKey, now()->addMinutes(self::CACHE_TTL_MINUTES), $callback);
        }

        return Cache::remember($versionedKey, now()->addMinutes(self::CACHE_TTL_MINUTES), $callback);
    }

    private function supportsTags(): bool
    {
        return Cache::getStore() instanceof TaggableStore;
    }

    /**
     * Keep the shared post source pool scope-versioned, while locale-derived
     * caches roll only when that locale is invalidated.
     *
     * @param  array<int,string>  $tags
     */
    private function versionedKey(string $key, array $tags): string
    {
        $scopeBaseTag = ContentCacheInvalidationService::publicBlogBaseTag($this->scopeCacheKeySegment());

        if (in_array($scopeBaseTag, $tags, true)) {
            return $key . '.sv_' . $this->scopeVersionToken();
        }

        return $key;
    }
}
