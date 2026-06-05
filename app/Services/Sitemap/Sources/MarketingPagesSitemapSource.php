<?php

namespace App\Services\Sitemap\Sources;

use App\Models\MarketingPage;
use App\Services\Sitemap\Contracts\SitemapSource;
use App\Support\LocalizedMarketingUrl;

class MarketingPagesSitemapSource implements SitemapSource
{
    public function name(): string
    {
        return 'marketing-pages';
    }

    public function enabled(): bool
    {
        return (bool) config('sitemap.enabled', true)
            && (bool) config('sitemap.include_topics', true);
    }

    public function urls(?string $locale = null): array
    {
        return MarketingPage::query()
            ->where('is_active', true)
            ->with('translations')
            ->orderBy('sort_order')
            ->get()
            ->flatMap(function (MarketingPage $page) use ($locale): array {
                $hreflangs = collect(LocalizedMarketingUrl::hreflangsForPage($page));
                $alternates = $hreflangs
                    ->map(fn (string $href, string $locale): array => ['hreflang' => $locale, 'href' => $href])
                    ->values()
                    ->all();

                return $page->translations
                    ->filter(fn ($translation): bool => $locale === null || (string) $translation->locale === $locale)
                    ->map(function ($translation) use ($page, $alternates): array {
                        return [
                            'loc' => LocalizedMarketingUrl::page($page, $translation->locale),
                            'lastmod' => optional($translation->updated_at)->toAtomString(),
                            'alternates' => $alternates,
                        ];
                    })
                    ->all();
            })
            ->unique('loc')
            ->values()
            ->all();
    }
}
