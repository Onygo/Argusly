<?php

namespace App\Http\Controllers;

use App\Support\LocalizedMarketingUrl;
use App\Support\MarketingRouteSegments;
use Illuminate\Support\Arr;
use Illuminate\View\View;

class PublicMarketController extends Controller
{
    public function __invoke(string $market): View
    {
        $locale = (string) app()->getLocale();
        $markets = (array) config('argusly_markets.pages', []);
        $page = $this->localizedMarket((array) ($markets[$market] ?? []), $locale);

        abort_if($page === [], 404);

        $routeName = 'public.markets.' . $market;
        $marketLinks = collect($markets)
            ->map(fn (array $item): array => $this->localizedMarket($item, $locale))
            ->map(fn (array $item, string $key): array => [
                'key' => $key,
                'label' => (string) ($item['nav_label'] ?? $item['label'] ?? $key),
                'description' => (string) ($item['description'] ?? ''),
                'url' => LocalizedMarketingUrl::route('public.markets.' . $key, [], $locale),
            ])
            ->values()
            ->all();

        return view('public.market', [
            'publicLang' => $locale,
            'marketKey' => $market,
            'page' => $page,
            'metaTitle' => (string) Arr::get($page, 'meta_title'),
            'metaDescription' => (string) Arr::get($page, 'meta_description'),
            'canonicalUrl' => LocalizedMarketingUrl::route($routeName, [], $locale),
            'hreflangUrls' => collect(app(MarketingRouteSegments::class)->locales())
                ->mapWithKeys(fn (string $hreflang): array => [
                    $hreflang => LocalizedMarketingUrl::route($routeName, [], $hreflang),
                ])
                ->all(),
            'contactCta' => LocalizedMarketingUrl::route('public.company.contact', [
                'subject' => (string) ($page['demo_cta'] ?? 'Market demo'),
            ], $locale) . '#contact-form',
            'aiVisibilityUrl' => LocalizedMarketingUrl::route('public.solutions.ai-visibility', [], $locale),
            'competitiveUrl' => LocalizedMarketingUrl::route('public.solutions.competitive-intelligence', [], $locale),
            'opportunityUrl' => LocalizedMarketingUrl::route('public.solutions.opportunity-intelligence', [], $locale),
            'agenticUrl' => LocalizedMarketingUrl::route('public.agentic-marketing', [], $locale),
            'marketLinks' => $marketLinks,
        ]);
    }

    /**
     * @param  array<string, mixed>  $market
     * @return array<string, mixed>
     */
    private function localizedMarket(array $market, string $locale): array
    {
        $localized = (array) ($market['locales'][$locale] ?? []);
        unset($market['locales']);

        return array_replace_recursive($market, $localized);
    }
}
