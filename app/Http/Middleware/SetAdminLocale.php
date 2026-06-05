<?php

namespace App\Http\Middleware;

use App\Services\LanguageResolverService;
use App\Support\RuntimeHtmlTranslator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class SetAdminLocale
{
    public function __construct(
        private readonly LanguageResolverService $languageResolver,
        private readonly RuntimeHtmlTranslator $htmlTranslator,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->languageResolver->resolveUiLocale($request, 'app');
        $queryLang = $request->query('lang');

        if (is_string($queryLang) && $this->languageResolver->isPlatformUiLocale($queryLang)) {
            $locale = strtolower($queryLang);
            Cookie::queue(cookie('pl_locale', $locale, 60 * 24 * 365));
        }

        $request->session()->put('app_lang', $locale);
        app()->setLocale($locale);
        view()->share('adminLang', $locale);
        view()->share('locale', $locale);

        $response = $next($request);

        if ($locale !== 'nl' || ! $this->htmlTranslator->shouldTranslate($response)) {
            return $response;
        }

        $translations = trans('admin.runtime');
        if (! is_array($translations) || $translations === []) {
            return $response;
        }

        return $this->htmlTranslator->translateResponse($response, $translations);
    }
}
