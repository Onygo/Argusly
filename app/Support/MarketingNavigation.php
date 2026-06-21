<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MarketingPageTranslation;
use App\Models\MarketingPage;

/**
 * Centralized marketing navigation definitions.
 *
 * Provides a single source of truth for header, footer, and CTA navigation
 * items with visibility rules for early access and full marketing modes.
 */
final class MarketingNavigation
{
    /**
     * Navigation sections.
     */
    public const SECTION_HEADER = 'header';

    public const SECTION_FOOTER_PRODUCT = 'footer_product';

    public const SECTION_FOOTER_COMPANY = 'footer_company';

    public const SECTION_FOOTER_LEGAL = 'footer_legal';

    public const SECTION_FOOTER_RESOURCES = 'footer_resources';

    /**
     * Get header navigation items for the current mode.
     *
     * @return array<int, array{label: string, route: string, route_params?: array<string, mixed>}>
     */
    public static function headerItems(): array
    {
        $items = [];

        if (EarlyAccess::showEarlyAccessCTA()) {
            // Early access mode: minimal navigation
            $items[] = [
                'label' => __('public.footer.about'),
                'route' => 'public.company.about',
            ];
            $items[] = [
                'label' => __('public.footer.contact'),
                'route' => 'public.company.contact',
                'anchor' => '#contact-form',
            ];
        } else {
            if (EarlyAccess::pricingEnabled()) {
                $items[] = [
                    'label' => __('public.nav.pricing'),
                    'route' => 'pricing',
                ];
            }
        }

        return $items;
    }

    /**
     * @return array<int, array{label: string, route: string, route_params?: array<string, mixed>, anchor?: string, description: string}>
     */
    public static function platformItems(): array
    {
        return [
            [
                'label' => __('public.platform_nav.overview'),
                'route' => 'public.product.platform',
                'description' => __('public.platform_nav.overview_description'),
            ],
            [
                'label' => __('public.platform_nav.how_it_works'),
                'route' => 'landing',
                'anchor' => '#how',
                'description' => __('public.platform_nav.how_it_works_description'),
            ],
            [
                'label' => __('public.platform_nav.ai_visibility'),
                'route' => 'public.solutions.ai-visibility',
                'description' => __('public.platform_nav.ai_visibility_description'),
            ],
            [
                'label' => __('public.platform_nav.opportunity_intelligence'),
                'route' => 'public.solutions.opportunity-intelligence',
                'description' => __('public.platform_nav.opportunity_intelligence_description'),
            ],
            [
                'label' => __('public.platform_nav.autonomous_marketing'),
                'route' => 'public.agentic-marketing',
                'description' => __('public.platform_nav.autonomous_marketing_description'),
            ],
            [
                'label' => __('public.platform_nav.integrations'),
                'route' => 'public.product.platform',
                'anchor' => '#capabilities',
                'description' => __('public.platform_nav.integrations_description'),
            ],
            [
                'label' => __('public.platform_nav.governance_security'),
                'route' => 'public.product.platform',
                'anchor' => '#governance',
                'description' => __('public.platform_nav.governance_security_description'),
            ],
        ];
    }

    /**
     * @return array{label: string, page_key: string, description: string}|null
     */
    public static function resourceHubItem(): ?array
    {
        return null;
    }

    /**
     * @return array<int, array{label: string, page_key?: string, route?: string}>
     */
    public static function solutionItems(): array
    {
        return [
            [
                'label' => __('public.solutions.discover_opportunities'),
                'route' => 'public.solutions.opportunity-intelligence',
                'description' => __('public.solutions.opportunity_intelligence_description'),
            ],
            [
                'label' => __('public.solutions.increase_ai_visibility'),
                'route' => 'public.solutions.ai-visibility',
                'description' => __('public.solutions.ai_visibility_description'),
            ],
            [
                'label' => __('public.solutions.competitive_insight'),
                'route' => 'public.solutions.competitive-intelligence',
                'description' => __('public.solutions.competitive_intelligence_description'),
            ],
            [
                'label' => __('public.solutions.organize_marketing_autonomously'),
                'route' => 'public.agentic-marketing',
                'description' => __('public.solutions.agentic_marketing_description'),
            ],
        ];
    }

    /**
     * @return array<int, array{label: string, route: string, description: string}>
     */
    public static function marketItems(): array
    {
        $locale = (string) app()->getLocale();

        return collect((array) config('argusly_markets.pages', []))
            ->map(fn (array $market): array => self::localizedMarket($market, $locale))
            ->filter(fn (array $market): bool => (bool) ($market['nav_primary'] ?? true))
            ->sortBy(fn (array $market): int => (int) ($market['nav_order'] ?? 999))
            ->map(fn (array $market, string $key): array => [
                'label' => (string) ($market['nav_label'] ?? $market['label'] ?? $key),
                'route' => 'public.markets.' . $key,
                'description' => (string) ($market['description'] ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{label: string, page_key?: string, route?: string}>
     */
    public static function resourceItems(): array
    {
        return collect([
            [
                'label' => __('public.resources.ai_visibility_agentic_marketing'),
                'page_key' => 'ai_visibility_agentic_marketing',
            ],
            [
                'label' => __('public.nav.blog'),
                'route' => 'public.blog.index',
            ],
            [
                'label' => __('public.resources.ai_search_geo'),
                'page_key' => 'ai_search',
            ],
        ])
            ->filter(fn (array $item): bool => isset($item['route']) || self::resourcePageExists((string) $item['page_key']))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function resourcePageKeys(): array
    {
        $hubItem = self::resourceHubItem();

        return [
            ...($hubItem !== null ? ['ai_search'] : []),
            ...collect(self::resourceItems())->pluck('page_key')->filter()->all(),
        ];
    }

    /**
     * @return array<int, array{label: string, page_key: string}>
     */
    public static function footerResourceItems(): array
    {
        return self::resourceItems();
    }

    /**
     * Get the primary CTA configuration for header.
     *
     * @return array{label: string, route: string, route_params?: array<string, mixed>, anchor?: string}
     */
    public static function headerPrimaryCTA(): array
    {
        if (EarlyAccess::showEarlyAccessCTA()) {
            return [
                'label' => __('public.nav.early_access'),
                'route' => 'public.early-access.show',
                'route_params' => ['intent' => 'early_access'],
            ];
        }

        return [
            'label' => __('public.nav.ai_visibility_scan'),
            'route' => 'public.company.contact',
            'route_params' => ['subject' => 'walkthrough'],
            'anchor' => '#contact-form',
        ];
    }

    /**
     * Get footer product column items for the current mode.
     *
     * @return array<int, array{label: string, route: string, route_params?: array<string, mixed>}>
     */
    public static function footerProductItems(): array
    {
        if (EarlyAccess::showEarlyAccessCTA()) {
            // Early access mode: only show early access link
            return [
                [
                    'label' => __('public.nav.early_access'),
                    'route' => 'public.early-access.show',
                ],
            ];
        }

        // Full marketing mode
        $items = [
            [
                'label' => __('public.platform_nav.overview'),
                'route' => 'public.product.platform',
            ],
            [
                'label' => __('public.solutions.opportunity_intelligence'),
                'route' => 'public.solutions.opportunity-intelligence',
            ],
            [
                'label' => __('public.nav.markets'),
                'route' => 'public.markets.it-services-saas',
            ],
        ];

        if (EarlyAccess::pricingEnabled()) {
            $items[] = [
                'label' => __('public.nav.pricing'),
                'route' => 'pricing',
            ];
        }

        return $items;
    }

    /**
     * Get footer company column items for the current mode.
     *
     * @return array<int, array{label: string, route: string, route_params?: array<string, mixed>, anchor?: string}>
     */
    public static function footerCompanyItems(): array
    {
        $items = [
            [
                'label' => __('public.footer.about'),
                'route' => 'public.company.about',
            ],
        ];

        if (EarlyAccess::showEarlyAccessCTA()) {
            // In early access mode, add contact and sign in
            $items[] = [
                'label' => __('public.footer.contact'),
                'route' => 'public.company.contact',
                'anchor' => '#contact-form',
            ];
            $items[] = [
                'label' => __('public.nav.sign_in'),
                'route' => 'login',
            ];
        } else {
            // Full marketing mode
            $items[] = [
                'label' => __('public.footer.contact'),
                'route' => 'public.company.contact',
                'anchor' => '#contact-form',
            ];
            $items[] = [
                'label' => __('public.footer.roadmap'),
                'route' => 'public.company.roadmap',
            ];
        }

        return $items;
    }

    /**
     * Get footer legal column items (always the same in all modes).
     *
     * @return array<int, array{label: string, route: string}>
     */
    public static function footerLegalItems(): array
    {
        return [
            [
                'label' => __('public.footer.legal_hub'),
                'route' => 'public.legal.index',
            ],
            [
                'label' => __('public.footer.privacy'),
                'route' => 'public.legal.privacy',
            ],
            [
                'label' => __('public.footer.terms'),
                'route' => 'public.legal.terms',
            ],
            [
                'label' => __('public.footer.security'),
                'route' => 'public.legal.security',
            ],
            [
                'label' => __('public.footer.cookies'),
                'route' => 'public.legal.cookies',
            ],
            [
                'label' => __('public.footer.subprocessors'),
                'route' => 'public.legal.subprocessors',
            ],
        ];
    }

    public static function currentMarketingPageKey(): ?string
    {
        $route = request()->route();
        if ($route === null) {
            return null;
        }

        $segments = app(MarketingRouteSegments::class);
        $logicalRoute = $segments->logicalRouteName((string) $route->getName());

        if ($logicalRoute !== 'public.marketing-pages.show' && $logicalRoute !== 'public.marketing-pages.section.show') {
            return null;
        }

        $currentLocale = $segments->resolveLocale((string) ($route->parameter('locale') ?: app()->getLocale()));
        $slug = trim((string) $route->parameter('slug'));
        $section = trim((string) ($route->parameter('section') ?: $route->parameter('sectionSlug')));

        $translation = MarketingPageTranslation::query()
            ->with('marketingPage:id,key')
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

        return $translation?->marketingPage?->key;
    }

    /**
     * Get footer tagline for the current mode.
     */
    public static function footerTagline(): string
    {
        return __('public.footer.tagline');
    }

    /**
     * Get footer early access note (only shown in early access mode).
     */
    public static function footerEarlyAccessNote(): ?string
    {
        if (! EarlyAccess::showEarlyAccessCTA()) {
            return null;
        }

        return __('public.footer.early_access_note');
    }

    /**
     * Get homepage primary CTA configuration.
     *
     * @return array{label: string, route: string, route_params?: array<string, mixed>, anchor?: string}
     */
    public static function homepagePrimaryCTA(): array
    {
        if (EarlyAccess::showEarlyAccessCTA()) {
            return [
                'label' => __('public.nav.early_access'),
                'route' => 'public.early-access.show',
                'route_params' => ['intent' => 'early_access'],
            ];
        }

        return [
            'label' => __('public.landing.hero_primary'),
            'route' => 'public.company.contact',
            'anchor' => '#contact-form',
        ];
    }

    /**
     * Get homepage secondary CTA configuration.
     *
     * @return array{label: string, route?: string, href?: string}
     */
    public static function homepageSecondaryCTA(): array
    {
        if (EarlyAccess::showEarlyAccessCTA()) {
            return [
                'label' => __('public.footer.contact'),
                'route' => 'public.company.contact',
                'anchor' => '#contact-form',
            ];
        }

        return [
            'label' => __('public.landing.hero_secondary'),
            'href' => '#how',
        ];
    }

    /**
     * Get bottom CTA section configuration for landing page.
     *
     * @return array{primary: array{label: string, route: string, route_params?: array<string, mixed>, anchor?: string}, secondary: array{label: string, route: string, route_params?: array<string, mixed>, anchor?: string}}
     */
    public static function landingBottomCTA(): array
    {
        if (EarlyAccess::showEarlyAccessCTA()) {
            return [
                'primary' => [
                    'label' => __('public.nav.early_access'),
                    'route' => 'public.early-access.show',
                    'route_params' => ['intent' => 'early_access'],
                ],
                'secondary' => [
                    'label' => __('public.landing.contact_us'),
                    'route' => 'public.company.contact',
                    'anchor' => '#contact-form',
                ],
            ];
        }

        return [
            'primary' => [
                'label' => __('public.landing.cta_view'),
                'route' => 'pricing',
            ],
            'secondary' => [
                'label' => __('public.landing.contact_us'),
                'route' => 'public.contact',
                'route_params' => ['subject' => 'maatwerk-enterprise'],
                'anchor' => '#contact-form',
            ],
        ];
    }

    /**
     * Build a URL from navigation item config.
     *
     * @param  array{route: string, route_params?: array<string, mixed>, anchor?: string}  $item
     */
    public static function buildUrl(array $item): string
    {
        if (isset($item['page_key'])) {
            if (! self::resourcePageExists((string) $item['page_key'])) {
                return LocalizedMarketingUrl::route('landing');
            }

            return LocalizedMarketingUrl::page((string) $item['page_key']);
        }

        $routeName = (string) ($item['route'] ?? '');
        $routeParams = $item['route_params'] ?? [];
        $url = LocalizedMarketingUrl::supportsRoute($routeName)
            ? LocalizedMarketingUrl::route($routeName, $routeParams)
            : route($routeName, $routeParams);

        if (isset($item['anchor'])) {
            $url .= $item['anchor'];
        }

        return $url;
    }

    private static function localizedMarket(array $market, string $locale): array
    {
        $localized = (array) ($market['locales'][$locale] ?? []);
        unset($market['locales']);

        return array_replace_recursive($market, $localized);
    }

    private static function resourcePageExists(string $pageKey): bool
    {
        static $cache = [];

        if (! array_key_exists($pageKey, $cache)) {
            $cache[$pageKey] = MarketingPage::query()
                ->where('key', $pageKey)
                ->exists();
        }

        return $cache[$pageKey];
    }
}
