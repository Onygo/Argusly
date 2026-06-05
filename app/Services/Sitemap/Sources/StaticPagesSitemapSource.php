<?php

namespace App\Services\Sitemap\Sources;

use App\Services\Sitemap\Contracts\SitemapSource;
use App\Services\Sitemap\SitemapUrlResolver;
use App\Services\Sitemap\StaticPageRegistry;
use App\Support\LocalizedMarketingUrl;

class StaticPagesSitemapSource implements SitemapSource
{
    public function __construct(
        private readonly StaticPageRegistry $registry,
        private readonly SitemapUrlResolver $urlResolver,
    ) {}

    public function name(): string
    {
        return 'static';
    }

    public function enabled(): bool
    {
        return (bool) config('sitemap.enabled', true)
            && (bool) config('sitemap.include_static', true);
    }

    public function urls(?string $locale = null): array
    {
        return collect($this->registry->routeNames())
            ->flatMap(function (string $routeName) use ($locale): array {
                if (! LocalizedMarketingUrl::supportsRoute($routeName)) {
                    $resolved = $this->urlResolver->resolveStaticRoute($routeName);

                    return $resolved !== null ? [$resolved] : [];
                }

                $hreflangs = collect(LocalizedMarketingUrl::hreflangsForRoute($routeName));
                $alternates = $hreflangs
                    ->map(fn (string $href, string $locale): array => ['hreflang' => $locale, 'href' => $href])
                    ->values()
                    ->all();

                $urls = $locale !== null ? $hreflangs->only([$locale]) : $hreflangs;

                return $urls
                    ->map(fn (string $href): array => [
                        'loc' => $href,
                        'lastmod' => null,
                        'alternates' => $alternates,
                    ])
                    ->values()
                    ->all();
            })
            ->filter()
            ->unique('loc')
            ->sortBy('loc')
            ->values()
            ->all();
    }
}
