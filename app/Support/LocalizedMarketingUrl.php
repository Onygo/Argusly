<?php

namespace App\Support;

use App\Models\MarketingPage;
use Illuminate\Http\Request;

class LocalizedMarketingUrl
{
    public static function supportsRoute(string $routeName): bool
    {
        return app(LocalizedMarketingUrlGenerator::class)->supportsRoute($routeName);
    }

    public static function route(string $routeName, array $parameters = [], ?string $locale = null, bool $absolute = true): string
    {
        return app(LocalizedMarketingUrlGenerator::class)->route($routeName, $parameters, $locale, $absolute);
    }

    public static function page(MarketingPage|string $page, ?string $locale = null, bool $absolute = true): string
    {
        return app(LocalizedMarketingUrlGenerator::class)->page($page, $locale, $absolute);
    }

    public static function hreflangsForRoute(string $routeName, array $parameters = []): array
    {
        return app(LocalizedMarketingUrlGenerator::class)->hreflangsForRoute($routeName, $parameters);
    }

    public static function hreflangsForPage(MarketingPage $page): array
    {
        return app(LocalizedMarketingUrlGenerator::class)->hreflangsForPage($page);
    }

    public static function switchLocaleUrl(Request $request, string $targetLocale): string
    {
        return app(LocalizedMarketingUrlGenerator::class)->switchLocaleUrl($request, $targetLocale);
    }
}
