<?php

namespace App\Http\Middleware;

use App\Services\LanguageResolverService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class SetPublicLocale
{
    public function __construct(
        protected LanguageResolverService $languageResolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $routeLocale = $request->route('locale');
        $pathLocale = $request->segment(1);

        $locale = match (true) {
            is_string($routeLocale) && $this->languageResolver->isPlatformUiLocale($routeLocale) => strtolower($routeLocale),
            is_string($pathLocale) && $this->languageResolver->isPlatformUiLocale($pathLocale) => strtolower($pathLocale),
            default => $this->languageResolver->resolvePublicLocale($request),
        };

        $queryLang = $request->query('lang');
        if ($routeLocale === null
            && (! is_string($pathLocale) || ! $this->languageResolver->isPlatformUiLocale($pathLocale))
            && is_string($queryLang)
            && $this->languageResolver->isPlatformUiLocale($queryLang)) {
            $locale = strtolower($queryLang);
            Cookie::queue(cookie('pl_locale', $locale, 60 * 24 * 365));
        }

        if (
            (is_string($routeLocale) && $this->languageResolver->isPlatformUiLocale($routeLocale))
            || (is_string($pathLocale) && $this->languageResolver->isPlatformUiLocale($pathLocale))
        ) {
            Cookie::queue(cookie('pl_locale', $locale, 60 * 24 * 365));
        }

        app()->setLocale($locale);
        view()->share('publicLang', $locale);
        view()->share('locale', $locale);

        return $next($request);
    }
}
