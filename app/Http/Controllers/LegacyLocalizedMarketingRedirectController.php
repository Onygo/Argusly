<?php

namespace App\Http\Controllers;

use App\Services\LanguageResolverService;
use App\Support\LocalizedMarketingUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LegacyLocalizedMarketingRedirectController extends Controller
{
    public function __construct(
        private readonly LanguageResolverService $languageResolver,
    ) {}

    public function route(Request $request): RedirectResponse
    {
        $routeName = trim((string) $request->route('marketing_route'));
        abort_if($routeName === '', 404);

        $locale = $this->resolveLocale($request);
        $parameters = $request->route()?->parameters() ?? [];
        unset($parameters['marketing_route'], $parameters['legacy_locale'], $parameters['locale']);

        $target = match ($routeName) {
            'public.product.capabilities' => LocalizedMarketingUrl::route('public.product.platform', [], $locale) . '#capabilities',
            'public.product.governance' => LocalizedMarketingUrl::route('public.product.platform', [], $locale) . '#governance',
            'public.product.intelligence' => LocalizedMarketingUrl::route('public.product.platform', [], $locale) . '#intelligence',
            default => LocalizedMarketingUrl::route($routeName, $parameters, $locale),
        };

        return redirect()->to($this->appendQueryString($target, $request), 301);
    }

    public function page(Request $request): RedirectResponse
    {
        $locale = $this->resolveLocale($request);

        return redirect()->to(
            $this->appendQueryString(LocalizedMarketingUrl::route('landing', [], $locale), $request),
            301
        );
    }

    private function resolveLocale(Request $request): string
    {
        $queryLocale = trim((string) $request->query('lang'));
        if ($this->languageResolver->isPlatformUiLocale($queryLocale)) {
            return strtolower($queryLocale);
        }

        $hint = trim((string) $request->route('legacy_locale'));

        if ($this->languageResolver->isPlatformUiLocale($hint)) {
            return strtolower($hint);
        }

        return $this->languageResolver->resolvePublicLocale($request);
    }

    private function appendQueryString(string $target, Request $request): string
    {
        $query = $request->query();
        unset($query['lang']);

        if ($query === []) {
            return $target;
        }

        return $target . (str_contains($target, '?') ? '&' : '?') . http_build_query($query);
    }
}
