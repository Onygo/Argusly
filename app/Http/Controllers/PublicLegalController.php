<?php

namespace App\Http\Controllers;

use App\Support\LocalizedMarketingUrl;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Illuminate\Support\Str;

class PublicLegalController extends Controller
{
    /**
     * @var array<string,array{route:string,key:string,view:string,title_key:string,description_key:string}>
     */
    private const LEGAL_PAGES = [
        'privacy' => [
            'route' => 'public.legal.privacy',
            'key' => 'legal.privacy',
            'view' => 'public.legal.privacy',
            'title_key' => 'public.legal.meta.privacy_title',
            'description_key' => 'public.legal.meta.privacy_description',
        ],
        'terms' => [
            'route' => 'public.legal.terms',
            'key' => 'legal.terms',
            'view' => 'public.legal.terms',
            'title_key' => 'public.legal.meta.terms_title',
            'description_key' => 'public.legal.meta.terms_description',
        ],
        'security' => [
            'route' => 'public.legal.security',
            'key' => 'legal.security',
            'view' => 'public.legal.security',
            'title_key' => 'public.legal.meta.security_title',
            'description_key' => 'public.legal.meta.security_description',
        ],
        'ai-transparency' => [
            'route' => 'public.legal.ai-transparency',
            'key' => 'legal.ai-transparency',
            'view' => 'public.legal.ai-transparency',
            'title_key' => 'public.legal.meta.ai_transparency_title',
            'description_key' => 'public.legal.meta.ai_transparency_description',
        ],
        'cookies' => [
            'route' => 'public.legal.cookies',
            'key' => 'legal.cookies',
            'view' => 'public.legal.cookies',
            'title_key' => 'public.legal.meta.cookies_title',
            'description_key' => 'public.legal.meta.cookies_description',
        ],
        'subprocessors' => [
            'route' => 'public.legal.subprocessors',
            'key' => 'legal.subprocessors',
            'view' => 'public.legal.subprocessors',
            'title_key' => 'public.legal.meta.subprocessors_title',
            'description_key' => 'public.legal.meta.subprocessors_description',
        ],
    ];

    public function hub(): View
    {
        $locale = (string) app()->getLocale();

        $cards = collect((array) __('public.legal.hub.cards'))
            ->map(function (array $card) use ($locale): array {
                $routeName = (string) ($card['route'] ?? '');

                return [
                    'title' => (string) ($card['title'] ?? ''),
                    'description' => (string) ($card['description'] ?? ''),
                    'link_label' => (string) ($card['link_label'] ?? ''),
                    'url' => $routeName !== '' ? $this->localizedRoute($routeName, [], $locale) : '#',
                ];
            })
            ->values()
            ->all();

        return view('public.legal.hub', [
            'metaTitle' => __('public.legal.meta.hub_title'),
            'metaDescription' => __('public.legal.meta.hub_description'),
            'canonicalUrl' => $this->localizedRoute('public.legal.index', [], $locale),
            'hreflangUrls' => LocalizedMarketingUrl::hreflangsForRoute('public.legal.index'),
            'ogType' => 'website',
            'heroTitle' => __('public.legal.hub.hero_title'),
            'heroSubtitle' => __('public.legal.hub.hero_subtitle'),
            'activeLegal' => 'hub',
            'legalSidebarItems' => $this->legalSidebarItems($locale),
            'hubCards' => $cards,
            'contactUrl' => $this->localizedRoute('public.contact', [], $locale),
        ]);
    }

    public function show(string $page): View
    {
        $locale = (string) app()->getLocale();
        $config = self::LEGAL_PAGES[$page] ?? null;
        abort_unless($config !== null, 404);

        /** @var array<string,mixed> $pages */
        $pages = trans('public.pages');
        $pageKey = $config['key'];
        abort_unless(isset($pages[$pageKey]), 404);

        $payload = (array) $pages[$pageKey];
        $document = [
            'heading' => (string) ($payload['heading'] ?? ''),
            'intro' => (string) ($payload['intro'] ?? ''),
            'sections' => (array) ($payload['sections'] ?? []),
            'articles' => (array) ($payload['articles'] ?? []),
        ];

        return view($config['view'], [
            'document' => $document,
            'metaTitle' => __($config['title_key']),
            'metaDescription' => __($config['description_key']),
            'canonicalUrl' => $this->localizedRoute($config['route'], [], $locale),
            'hreflangUrls' => LocalizedMarketingUrl::hreflangsForRoute($config['route']),
            'ogType' => 'article',
            'heroTitle' => __('public.legal.page_hero_title', ['page' => __('public.footer.' . str_replace('-', '_', $page))]),
            'heroSubtitle' => __('public.legal.page_hero_subtitle'),
            'activeLegal' => $page,
            'legalSidebarItems' => $this->legalSidebarItems($locale),
            'lastUpdated' => __('public.legal.last_updated.' . $page),
            'subprocessors' => $page === 'subprocessors' ? $this->subprocessors($locale) : [],
            'relatedLinks' => $this->relatedLinks($page, $locale),
        ]);
    }

    /**
     * @return array<int,array{key:string,label:string,url:string}>
     */
    private function legalSidebarItems(string $locale): array
    {
        return [
            [
                'key' => 'hub',
                'label' => __('public.footer.legal_hub'),
                'url' => $this->localizedRoute('public.legal.index', [], $locale),
            ],
            [
                'key' => 'privacy',
                'label' => __('public.footer.privacy'),
                'url' => $this->localizedRoute('public.legal.privacy', [], $locale),
            ],
            [
                'key' => 'terms',
                'label' => __('public.footer.terms'),
                'url' => $this->localizedRoute('public.legal.terms', [], $locale),
            ],
            [
                'key' => 'security',
                'label' => __('public.footer.security'),
                'url' => $this->localizedRoute('public.legal.security', [], $locale),
            ],
            [
                'key' => 'ai-transparency',
                'label' => __('public.footer.ai_transparency'),
                'url' => $this->localizedRoute('public.legal.ai-transparency', [], $locale),
            ],
            [
                'key' => 'cookies',
                'label' => __('public.footer.cookies'),
                'url' => $this->localizedRoute('public.legal.cookies', [], $locale),
            ],
            [
                'key' => 'subprocessors',
                'label' => __('public.footer.subprocessors'),
                'url' => $this->localizedRoute('public.legal.subprocessors', [], $locale),
            ],
        ];
    }

    /**
     * @return array<int,array{label:string,url:string}>
     */
    private function relatedLinks(string $page, string $locale): array
    {
        $map = [
            'privacy' => ['cookies', 'subprocessors', 'security'],
            'security' => ['privacy', 'subprocessors', 'ai-transparency'],
            'ai-transparency' => ['privacy', 'security', 'subprocessors'],
            'cookies' => ['privacy'],
            'terms' => ['privacy'],
            'subprocessors' => ['privacy', 'security'],
        ];

        return collect($map[$page] ?? [])
            ->map(function (string $item) use ($locale): array {
                return [
                    'label' => __('public.footer.' . str_replace('-', '_', $item)),
                    'url' => $this->localizedRoute('public.legal.' . $item, [], $locale),
                ];
            })
            ->values()
            ->all();
    }

    private function localizedRoute(string $name, array $params, string $locale): string
    {
        return LocalizedMarketingUrl::route($name, $params, $locale);
    }

    /**
     * @return array<int,array<string,string|null>>
     */
    private function subprocessors(string $locale): array
    {
        $providers = config('legal.subprocessors.providers', []);

        if (! is_array($providers)) {
            return [];
        }

        return collect($providers)
            ->filter(fn ($provider) => is_array($provider))
            ->map(function (array $provider) use ($locale): array {
                return [
                    'name' => $this->stringValue($provider['name'] ?? null),
                    'legal_entity' => $this->stringValue($provider['legal_entity'] ?? null),
                    'service_category' => $this->subprocessorTranslation('categories', $provider['service_category'] ?? null),
                    'purpose' => $this->subprocessorTranslation('purposes', $provider['purpose'] ?? null),
                    'location' => $this->subprocessorTranslation('locations', $provider['location'] ?? null),
                    'website' => $this->stringValue($provider['website'] ?? null),
                    'privacy_url' => $this->nullableString($provider['privacy_url'] ?? null),
                    'dpa_url' => $this->nullableString($provider['dpa_url'] ?? null),
                    'registered_address' => $this->nullableString($provider['registered_address'] ?? null),
                    'last_updated' => $this->formatSubprocessorDate($provider['last_updated'] ?? null, $locale),
                ];
            })
            ->sortBy(fn (array $provider): string => Str::lower((string) ($provider['name'] ?? '')))
            ->values()
            ->all();
    }

    private function subprocessorTranslation(string $group, mixed $key): string
    {
        $value = $this->stringValue($key);

        if ($value === '') {
            return '';
        }

        $translationKey = sprintf('public.legal.subprocessors.%s.%s', $group, $value);
        $translated = __($translationKey);

        return $translated !== $translationKey ? $translated : $value;
    }

    private function formatSubprocessorDate(mixed $value, string $locale): string
    {
        $date = $this->nullableString($value);

        if ($date === null) {
            return '';
        }

        $format = $locale === 'nl' ? 'j F Y' : 'F j, Y';

        return CarbonImmutable::parse($date)->locale($locale)->translatedFormat($format);
    }

    private function stringValue(mixed $value): string
    {
        return trim((string) $value);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = $this->stringValue($value);

        return $value !== '' ? $value : null;
    }
}
