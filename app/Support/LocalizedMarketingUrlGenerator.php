<?php

namespace App\Support;

use App\Models\MarketingPage;
use App\Models\MarketingPageTranslation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class LocalizedMarketingUrlGenerator
{
    public function __construct(
        private readonly MarketingRouteSegments $segments,
    ) {}

    public function supportsRoute(string $routeName): bool
    {
        $canonical = $this->segments->canonicalRouteName($routeName);

        return $this->segments->localizedRouteExists($canonical);
    }

    public function route(string $routeName, array $parameters = [], ?string $locale = null, bool $absolute = true): string
    {
        $resolvedLocale = $this->segments->resolveLocale($locale);
        $canonicalRoute = $this->segments->canonicalRouteName($routeName);
        $localizedName = $this->segments->localizedRouteName($canonicalRoute, $resolvedLocale);

        $parameters = $this->normalizeParameters($parameters);

        if ($this->isCurrentTestRoute() && Route::has('test.' . $localizedName)) {
            return route('test.' . $localizedName, $parameters, $absolute);
        }

        if (Route::has($localizedName)) {
            return route($localizedName, $parameters, $absolute);
        }

        if (Route::has('test.' . $localizedName)) {
            return route('test.' . $localizedName, $parameters, $absolute);
        }

        throw new \InvalidArgumentException(sprintf('Localized marketing route [%s] does not exist.', $localizedName));
    }

    public function page(MarketingPage|string $page, ?string $locale = null, bool $absolute = true): string
    {
        $page = is_string($page)
            ? MarketingPage::query()->where('key', $page)->with('translations')->firstOrFail()
            : $page->loadMissing('translations');

        $translation = $page->translationOrFail($this->segments->resolveLocale($locale));

        if (trim((string) $translation->canonical_path) !== '') {
            return $absolute ? url((string) $translation->canonical_path) : (string) $translation->canonical_path;
        }

        if ($page->section !== null && trim((string) $page->section) !== '') {
            return $this->route('public.marketing-pages.section.show', [
                'sectionSlug' => $this->segments->segment((string) $page->section, $translation->locale),
                'slug' => $translation->slug,
            ], $translation->locale, $absolute);
        }

        return $this->route('public.marketing-pages.show', [
            'slug' => $translation->slug,
        ], $translation->locale, $absolute);
    }

    public function hreflangsForRoute(string $routeName, array $parameters = []): array
    {
        return collect($this->segments->locales())
            ->mapWithKeys(fn (string $locale): array => [
                $locale => $this->route($routeName, $parameters, $locale),
            ])
            ->all();
    }

    public function hreflangsForPage(MarketingPage $page): array
    {
        $page->loadMissing('translations');

        return collect($this->segments->locales())
            ->filter(fn (string $locale): bool => $page->translation($locale) instanceof MarketingPageTranslation)
            ->mapWithKeys(fn (string $locale): array => [
                $locale => $this->page($page, $locale),
            ])
            ->all();
    }

    public function switchLocaleUrl(Request $request, string $targetLocale): string
    {
        $targetLocale = $this->segments->resolveLocale($targetLocale);
        $logicalRoute = $this->segments->logicalRouteName($request->route()?->getName());

        if (in_array($logicalRoute, ['public.marketing-pages.show', 'public.marketing-pages.markdown', 'public.marketing-pages.section.show'], true)) {
            $currentLocale = $this->segments->resolveLocale(
                (string) ($request->route('locale') ?: app()->getLocale())
            );
            $slug = trim((string) $request->route('slug'));
            $section = trim((string) $request->route('section') ?: (string) $request->route('sectionSlug'));

            $translation = MarketingPageTranslation::query()
                ->with('marketingPage.translations')
                ->where('locale', $currentLocale)
                ->where('slug', $slug)
                ->whereHas('marketingPage', function ($query) use ($section): void {
                    if ($section === '') {
                        $query->whereNull('section');

                        return;
                    }

                    $query->where('section', $section);
                })
                ->first();

            if ($translation?->marketingPage instanceof MarketingPage) {
                return $this->page($translation->marketingPage, $targetLocale);
            }
        }

        if ($logicalRoute === null) {
            return $this->route('landing', [], $targetLocale);
        }

        $parameters = $this->normalizeParameters($request->route()?->parameters() ?? []);

        return $this->route($logicalRoute, $parameters, $targetLocale);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function normalizeParameters(array $parameters): array
    {
        unset($parameters['locale']);

        if (($parameters['section'] ?? null) !== null) {
            unset($parameters['section']);
        }

        if (($parameters['sectionSlug'] ?? null) !== null) {
            unset($parameters['sectionSlug']);
        }

        return $parameters;
    }

    private function isCurrentTestRoute(): bool
    {
        if (! app()->bound('request')) {
            return false;
        }

        $routeName = (string) request()->route()?->getName();

        return str_starts_with($routeName, 'test.');
    }
}
