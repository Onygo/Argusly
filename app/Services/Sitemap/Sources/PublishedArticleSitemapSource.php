<?php

namespace App\Services\Sitemap\Sources;

use App\Exceptions\PublicBlogSourceUnavailableException;
use App\Services\PublicBlog\PublicBlogService;
use App\Services\Sitemap\Contracts\SitemapSource;
use App\Support\LocalizedMarketingUrl;
use App\Support\MarketingRouteSegments;
use Carbon\CarbonImmutable;

class PublishedArticleSitemapSource implements SitemapSource
{
    public function __construct(
        private readonly PublicBlogService $blog,
        private readonly MarketingRouteSegments $segments,
    ) {}

    public function name(): string
    {
        return 'articles';
    }

    public function enabled(): bool
    {
        return (bool) config('sitemap.enabled', true);
    }

    public function urls(?string $locale = null): array
    {
        try {
            $locales = $locale !== null && $this->segments->isSupportedLocale($locale)
                ? [$this->segments->resolveLocale($locale)]
                : $this->segments->locales();

            return collect($locales)
                ->flatMap(function (string $locale): array {
                    return collect($this->blog->latestPosts(
                        (int) config('argusly_connector.public_blog.max_posts', config('argusly.public_blog.max_posts', 300)),
                        $locale
                    ))
                        ->map(fn (array $post): array => $this->mapPost($post, $locale))
                        ->filter()
                        ->values()
                        ->all();
                })
                ->unique('loc')
                ->sortBy('loc')
                ->values()
                ->all();
        } catch (PublicBlogSourceUnavailableException) {
            return [];
        }
    }

    /**
     * @param  array<string,mixed>  $post
     * @return array<string,mixed>|null
     */
    private function mapPost(array $post, string $locale): ?array
    {
        $slug = trim((string) ($post['slug'] ?? ''));
        if ($slug === '') {
            return null;
        }

        $alternates = collect($this->blog->alternateLocaleUrls($post))
            ->map(fn (string $href, string $hreflang): array => ['hreflang' => $hreflang, 'href' => $href])
            ->values()
            ->all();

        return [
            'loc' => $this->blog->publicUrl($post, $locale),
            'lastmod' => $this->resolveLastmod($post),
            'alternates' => $alternates,
            'images' => $this->images($post),
        ];
    }

    /**
     * @param  array<string,mixed>  $post
     * @return list<array{loc:string,title:string}>
     */
    private function images(array $post): array
    {
        $image = trim((string) ($post['featured_image'] ?? ''));
        if ($image === '') {
            return [];
        }

        return [[
            'loc' => $image,
            'title' => trim((string) ($post['featured_image_alt'] ?? $post['title'] ?? '')),
        ]];
    }

    /**
     * @param  array<string,mixed>  $post
     */
    private function resolveLastmod(array $post): ?string
    {
        foreach ([
            $post['translation_generated_at'] ?? null,
            $post['translation_source_updated_at'] ?? null,
            $post['published_at'] ?? null,
        ] as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            try {
                return CarbonImmutable::parse($candidate)->toAtomString();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
