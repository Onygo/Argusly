<header class="sticky top-0 z-50 border-b border-border bg-surface/95 backdrop-blur">
    @php
        use App\Support\MarketingNavigation;
        use App\Support\EarlyAccess;
        use App\Support\LocaleHelper;
        use App\Support\LocalizedMarketingUrl;
        use App\Support\MarketingRouteSegments;

        $lang = (string) ($publicLang ?? app()->getLocale());
        $switchToNl = LocalizedMarketingUrl::switchLocaleUrl(request(), 'nl');
        $switchToEn = LocalizedMarketingUrl::switchLocaleUrl(request(), 'en');
        $availableSwitchUrls = is_array($localeSwitchUrls ?? null) && ($localeSwitchUrls ?? []) !== []
            ? LocaleHelper::visibleLocaleUrls($localeSwitchUrls)
            : ['nl' => $switchToNl, 'en' => $switchToEn];
        $navItems = MarketingNavigation::headerItems();
        $platformItems = MarketingNavigation::platformItems();
        $solutionItems = MarketingNavigation::solutionItems();
        $marketItems = MarketingNavigation::marketItems();
        $resourceHubItem = MarketingNavigation::resourceHubItem();
        $resourceItems = MarketingNavigation::resourceItems();
        $resourcePageKeys = MarketingNavigation::resourcePageKeys();
        $showResourcesNav = $resourceHubItem !== null || $resourceItems !== [];
        $primaryCta = MarketingNavigation::headerPrimaryCTA();
        $isEarlyAccess = EarlyAccess::enabled();
        $routeSegments = app(MarketingRouteSegments::class);
        $currentRoute = $routeSegments->logicalRouteName((string) request()->route()?->getName()) ?? '';
        $currentMarketingPageKey = MarketingNavigation::currentMarketingPageKey();
        $resourcesActive = in_array($currentMarketingPageKey, $resourcePageKeys, true) || str_starts_with($currentRoute, 'public.blog.');
        $platformActive = in_array($currentRoute, [
            'public.product.overview',
            'public.product.platform',
            'public.product.capabilities',
            'public.product.governance',
            'public.product.intelligence',
        ], true);
        $solutionsActive = str_starts_with($currentRoute, 'public.solutions.') || $currentRoute === 'public.agentic-marketing';
        $marketsActive = str_starts_with($currentRoute, 'public.markets.');

        $isItemActive = static function (array $item) use ($currentRoute, $currentMarketingPageKey): bool {
            if (isset($item['page_key'])) {
                return $currentMarketingPageKey === $item['page_key'];
            }

            return match ($item['route']) {
                'public.product.platform' => in_array($currentRoute, [
                    'public.product.platform',
                    'public.product.capabilities',
                    'public.product.governance',
                    'public.product.intelligence',
                ], true),
                'public.product.overview' => $currentRoute === 'public.product.overview',
                'public.blog.index' => str_starts_with($currentRoute, 'public.blog.'),
                'pricing' => $currentRoute === 'pricing',
                default => $currentRoute === $item['route'],
            };
        };
    @endphp
    <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3 sm:px-6">
        <a href="{{ LocalizedMarketingUrl::route('landing') }}" class="inline-flex items-center gap-3">
            <x-brand-logo text-class="pl-brand-logo-text text-[17px] text-textPrimary" />
        </a>

        <nav class="hidden items-center gap-4 text-sm text-textMuted lg:gap-5 md:flex">
            <details class="group relative">
                <summary class="flex list-none cursor-pointer items-center gap-1 rounded-md px-1 py-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-publicPrimarySoftRing [&::-webkit-details-marker]:hidden {{ $platformActive ? 'text-textPrimary' : 'hover:text-textPrimary' }}">
                    <span>{{ __('public.nav.platform') }}</span>
                    <svg class="h-4 w-4 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" aria-hidden="true">
                        <path d="M6 8l4 4 4-4" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"></path>
                    </svg>
                </summary>

                <div class="absolute left-1/2 top-full z-20 mt-3 w-[25rem] -translate-x-1/2 pl-public-card p-3">
                    <div class="grid gap-1">
                        @foreach ($platformItems as $item)
                            @php($isActive = $isItemActive($item))
                            <a href="{{ MarketingNavigation::buildUrl($item) }}" class="rounded-md px-3 py-3 text-sm transition-colors {{ $isActive ? 'bg-surfaceMuted text-textPrimary' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                                <span class="block font-semibold">{{ $item['label'] }}</span>
                                <span class="mt-1 block text-xs leading-5 text-textMuted">{{ $item['description'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            </details>

            <details class="group relative">
                <summary class="flex list-none cursor-pointer items-center gap-1 rounded-md px-1 py-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-publicPrimarySoftRing [&::-webkit-details-marker]:hidden {{ $solutionsActive ? 'text-textPrimary' : 'hover:text-textPrimary' }}">
                    <span>{{ __('public.nav.solutions') }}</span>
                    <svg class="h-4 w-4 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" aria-hidden="true">
                        <path d="M6 8l4 4 4-4" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"></path>
                    </svg>
                </summary>

                <div class="absolute left-1/2 top-full z-20 mt-3 w-[24rem] -translate-x-1/2 pl-public-card p-3">
                    <div class="grid gap-1">
                        @foreach ($solutionItems as $item)
                            @php($isActive = $isItemActive($item))
                            <a href="{{ MarketingNavigation::buildUrl($item) }}" class="rounded-md px-3 py-3 text-sm transition-colors {{ $isActive ? 'bg-surfaceMuted text-textPrimary' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                                <span class="block font-semibold">{{ $item['label'] }}</span>
                                <span class="mt-1 block text-xs leading-5 text-textMuted">{{ $item['description'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            </details>

            <details class="group relative">
                <summary class="flex list-none cursor-pointer items-center gap-1 rounded-md px-1 py-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-publicPrimarySoftRing [&::-webkit-details-marker]:hidden {{ $marketsActive ? 'text-textPrimary' : 'hover:text-textPrimary' }}">
                    <span>{{ __('public.nav.markets') }}</span>
                    <svg class="h-4 w-4 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" aria-hidden="true">
                        <path d="M6 8l4 4 4-4" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"></path>
                    </svg>
                </summary>

                <div class="absolute left-1/2 top-full z-20 mt-3 w-[24rem] -translate-x-1/2 pl-public-card p-3">
                    <div class="grid gap-1">
                        @foreach ($marketItems as $item)
                            @php($isActive = $isItemActive($item))
                            <a href="{{ MarketingNavigation::buildUrl($item) }}" class="rounded-md px-3 py-3 text-sm transition-colors {{ $isActive ? 'bg-surfaceMuted text-textPrimary' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                                <span class="block font-semibold">{{ $item['label'] }}</span>
                                <span class="mt-1 block text-xs leading-5 text-textMuted">{{ $item['description'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            </details>

            @if ($showResourcesNav)
                <details class="group relative">
                    <summary class="flex list-none cursor-pointer items-center gap-1 rounded-md px-1 py-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-publicPrimarySoftRing [&::-webkit-details-marker]:hidden {{ $resourcesActive ? 'text-textPrimary' : 'hover:text-textPrimary' }}">
                        <span>{{ __('public.nav.resources') }}</span>
                        <svg class="h-4 w-4 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" aria-hidden="true">
                            <path d="M6 8l4 4 4-4" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"></path>
                        </svg>
                    </summary>

                    <div class="absolute left-1/2 top-full z-20 mt-3 w-[22rem] -translate-x-1/2 pl-public-card p-3">
                        @if ($resourceHubItem !== null)
                            <a href="{{ MarketingNavigation::buildUrl($resourceHubItem) }}" class="block rounded-md border border-publicPrimary/12 bg-surfaceSubtle px-4 py-4 transition-colors hover:bg-surface">
                                <span class="block pl-public-eyebrow">{{ __('public.nav.resources') }}</span>
                                <span class="mt-2 block text-sm font-semibold text-textPrimary">{{ $resourceHubItem['label'] }}</span>
                                <span class="mt-1 block text-sm leading-6 text-textSecondary">{{ $resourceHubItem['description'] }}</span>
                            </a>
                        @endif

                        <div class="{{ $resourceHubItem !== null ? 'mt-3' : '' }} grid gap-1">
                            @foreach ($resourceItems as $item)
                                @php($isActive = $isItemActive($item))
                                <a href="{{ MarketingNavigation::buildUrl($item) }}" class="rounded-md px-3 py-2 text-sm transition-colors {{ $isActive ? 'bg-surfaceMuted text-textPrimary' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                                    {{ $item['label'] }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                </details>
            @endif

            @foreach ($navItems as $item)
                @php($isActive = $isItemActive($item))
                <a href="{{ MarketingNavigation::buildUrl($item) }}" class="{{ $isActive ? 'text-textPrimary' : 'hover:text-textPrimary' }}">{{ $item['label'] }}</a>
            @endforeach
        </nav>

        <div class="flex items-center gap-2">
            <details class="group relative hidden md:block">
                <summary class="flex list-none cursor-pointer items-center gap-1.5 rounded-md px-2.5 py-2 text-sm font-medium uppercase text-textSecondary transition-colors hover:bg-surfaceMuted hover:text-textPrimary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-publicPrimarySoftRing [&::-webkit-details-marker]:hidden">
                    <span>{{ strtoupper($lang) }}</span>
                    <svg class="h-4 w-4 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" aria-hidden="true">
                        <path d="M6 8l4 4 4-4" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"></path>
                    </svg>
                </summary>

                <div class="absolute right-0 top-full z-20 mt-3 min-w-24 rounded-xl border border-border bg-surface p-1.5 shadow-sm">
                    @foreach ($routeSegments->locales() as $switchLocale)
                        @continue(! isset($availableSwitchUrls[$switchLocale]))
                        <a href="{{ $availableSwitchUrls[$switchLocale] }}" class="flex items-center justify-between rounded-lg px-3 py-2 text-sm font-medium uppercase transition-colors {{ $lang === $switchLocale ? 'bg-surfaceMuted text-textPrimary' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                            {{ strtoupper($switchLocale) }}
                        </a>
                    @endforeach
                </div>
            </details>
            <div class="inline-flex items-center gap-2 rounded-full border border-border bg-surface p-1.5 text-sm md:hidden">
                @foreach ($routeSegments->locales() as $switchLocale)
                    @continue(! isset($availableSwitchUrls[$switchLocale]))
                    <a href="{{ $availableSwitchUrls[$switchLocale] }}" class="inline-flex h-9 min-w-11 items-center justify-center rounded-full px-3 font-medium transition-colors {{ $lang === $switchLocale ? 'bg-[#3157ff] text-textInverse' : 'text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        {{ strtoupper($switchLocale) }}
                    </a>
                @endforeach
            </div>
            <button
                type="button"
                class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-border bg-surface text-textSecondary hover:bg-surfaceMuted md:hidden"
                aria-expanded="false"
                aria-controls="public-mobile-menu"
                aria-label="Toggle menu"
                data-mobile-nav-toggle
            >
                <svg data-mobile-nav-icon-open class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                    <path d="M3 6h18M3 12h18M3 18h18" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
                </svg>
                <svg data-mobile-nav-icon-close class="hidden h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                    <path d="M6 6l12 12M18 6l-12 12" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
                </svg>
            </button>
            <a href="{{ route('login') }}" class="hidden items-center rounded-md px-2.5 py-2 text-sm text-textSecondary transition-colors hover:bg-surfaceMuted md:inline-flex">
                {{ __('public.nav.sign_in') }}
            </a>
            <a href="{{ MarketingNavigation::buildUrl($primaryCta) }}" class="group hidden items-center justify-center gap-3 rounded-full bg-[#080d16] px-5 py-3 text-sm font-semibold text-white transition-colors hover:bg-black md:inline-flex">
                {{ $primaryCta['label'] }}
                <i data-lucide="arrow-right" class="h-4 w-4 transition-transform duration-200 ease-out group-hover:translate-x-0.5" aria-hidden="true"></i>
            </a>
        </div>
    </div>

    <div id="public-mobile-menu" class="hidden border-t border-border bg-surface md:hidden" data-mobile-nav-menu>
        <nav class="mx-auto flex max-w-6xl flex-col gap-1 px-4 py-3 text-sm text-textMuted sm:px-6">
            <details class="group rounded-md">
                <summary class="flex list-none items-center justify-between rounded-md px-3 py-2 font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-publicPrimarySoftRing [&::-webkit-details-marker]:hidden {{ $platformActive ? 'bg-surfaceMuted text-textPrimary' : 'hover:bg-surfaceMuted hover:text-textPrimary' }}">
                    <span>{{ __('public.nav.platform') }}</span>
                    <svg class="h-4 w-4 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" aria-hidden="true">
                        <path d="M6 8l4 4 4-4" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"></path>
                    </svg>
                </summary>
                <div class="mt-1 space-y-1 px-3 pb-2">
                    @foreach ($platformItems as $item)
                        @php($isActive = $isItemActive($item))
                        <a href="{{ MarketingNavigation::buildUrl($item) }}" class="block rounded-md px-3 py-2 {{ $isActive ? 'bg-surfaceMuted text-textPrimary' : 'hover:bg-surfaceMuted hover:text-textPrimary' }}">
                            <span class="block font-semibold">{{ $item['label'] }}</span>
                            <span class="mt-1 block text-xs leading-5 text-textMuted">{{ $item['description'] }}</span>
                        </a>
                    @endforeach
                </div>
            </details>

            <details class="group rounded-md">
                <summary class="flex list-none items-center justify-between rounded-md px-3 py-2 font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-publicPrimarySoftRing [&::-webkit-details-marker]:hidden {{ $solutionsActive ? 'bg-surfaceMuted text-textPrimary' : 'hover:bg-surfaceMuted hover:text-textPrimary' }}">
                    <span>{{ __('public.nav.solutions') }}</span>
                    <svg class="h-4 w-4 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" aria-hidden="true">
                        <path d="M6 8l4 4 4-4" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"></path>
                    </svg>
                </summary>
                <div class="mt-1 space-y-1 px-3 pb-2">
                    @foreach ($solutionItems as $item)
                        @php($isActive = $isItemActive($item))
                        <a href="{{ MarketingNavigation::buildUrl($item) }}" class="block rounded-md px-3 py-2 {{ $isActive ? 'bg-surfaceMuted text-textPrimary' : 'hover:bg-surfaceMuted hover:text-textPrimary' }}">
                            <span class="block font-semibold">{{ $item['label'] }}</span>
                            <span class="mt-1 block text-xs leading-5 text-textMuted">{{ $item['description'] }}</span>
                        </a>
                    @endforeach
                </div>
            </details>

            <details class="group rounded-md">
                <summary class="flex list-none items-center justify-between rounded-md px-3 py-2 font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-publicPrimarySoftRing [&::-webkit-details-marker]:hidden {{ $marketsActive ? 'bg-surfaceMuted text-textPrimary' : 'hover:bg-surfaceMuted hover:text-textPrimary' }}">
                    <span>{{ __('public.nav.markets') }}</span>
                    <svg class="h-4 w-4 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" aria-hidden="true">
                        <path d="M6 8l4 4 4-4" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"></path>
                    </svg>
                </summary>
                <div class="mt-1 space-y-1 px-3 pb-2">
                    @foreach ($marketItems as $item)
                        @php($isActive = $isItemActive($item))
                        <a href="{{ MarketingNavigation::buildUrl($item) }}" class="block rounded-md px-3 py-2 {{ $isActive ? 'bg-surfaceMuted text-textPrimary' : 'hover:bg-surfaceMuted hover:text-textPrimary' }}">
                            <span class="block font-semibold">{{ $item['label'] }}</span>
                            <span class="mt-1 block text-xs leading-5 text-textMuted">{{ $item['description'] }}</span>
                        </a>
                    @endforeach
                </div>
            </details>

            @if ($showResourcesNav)
                <details class="group rounded-md">
                    <summary class="flex list-none items-center justify-between rounded-md px-3 py-2 font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-publicPrimarySoftRing [&::-webkit-details-marker]:hidden {{ $resourcesActive ? 'bg-surfaceMuted text-textPrimary' : 'hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <span>{{ __('public.nav.resources') }}</span>
                        <svg class="h-4 w-4 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" aria-hidden="true">
                            <path d="M6 8l4 4 4-4" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"></path>
                        </svg>
                    </summary>
                    <div class="mt-1 space-y-1 px-3 pb-2">
                        @if ($resourceHubItem !== null)
                            <a href="{{ MarketingNavigation::buildUrl($resourceHubItem) }}" class="block rounded-md border border-publicPrimary/12 bg-surfaceSubtle px-3 py-3 text-textPrimary">
                                <span class="block text-sm font-semibold">{{ $resourceHubItem['label'] }}</span>
                                <span class="mt-1 block text-xs leading-5 text-textSecondary">{{ $resourceHubItem['description'] }}</span>
                            </a>
                        @endif
                        @foreach ($resourceItems as $item)
                            @php($isActive = $isItemActive($item))
                            <a href="{{ MarketingNavigation::buildUrl($item) }}" class="block rounded-md px-3 py-2 {{ $isActive ? 'bg-surfaceMuted text-textPrimary' : 'hover:bg-surfaceMuted hover:text-textPrimary' }}">{{ $item['label'] }}</a>
                        @endforeach
                    </div>
                </details>
            @endif

            @foreach ($navItems as $item)
                @php($isActive = $isItemActive($item))
                <a href="{{ MarketingNavigation::buildUrl($item) }}" class="rounded-md px-3 py-2 {{ $isActive ? 'bg-surfaceMuted text-textPrimary' : 'hover:bg-surfaceMuted hover:text-textPrimary' }}">{{ $item['label'] }}</a>
            @endforeach
            <div class="mt-2 flex flex-wrap gap-2 px-3 pb-1">
                <a href="{{ route('login') }}" class="inline-flex items-center justify-center rounded-full border border-border bg-white px-4 py-2.5 text-sm font-medium text-textPrimary transition-colors hover:bg-surfaceMuted">{{ __('public.nav.sign_in') }}</a>
                <a href="{{ MarketingNavigation::buildUrl($primaryCta) }}" class="group inline-flex items-center justify-center gap-3 rounded-full bg-[#080d16] px-5 py-3 text-sm font-semibold text-white transition-colors hover:bg-black">
                    {{ $primaryCta['label'] }}
                    <i data-lucide="arrow-right" class="h-4 w-4 transition-transform duration-200 ease-out group-hover:translate-x-0.5" aria-hidden="true"></i>
                </a>
            </div>
        </nav>
    </div>
</header>
