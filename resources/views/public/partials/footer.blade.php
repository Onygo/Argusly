<footer class="border-t border-border bg-surface">
    @php
        use App\Support\MarketingNavigation;
        use App\Support\EarlyAccess;
        use App\Support\LocalizedMarketingUrl;

        $productItems = MarketingNavigation::footerProductItems();
        $companyItems = MarketingNavigation::footerCompanyItems();
        $resourceItems = MarketingNavigation::footerResourceItems();
        $showResourcesColumn = $resourceItems !== [];
        $legalItems = MarketingNavigation::footerLegalItems();
        $tagline = MarketingNavigation::footerTagline();
        $earlyAccessNote = MarketingNavigation::footerEarlyAccessNote();
    @endphp
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        <div class="flex flex-col gap-8 md:flex-row md:items-start md:justify-between">
            <div>
                <a href="{{ LocalizedMarketingUrl::route('landing') }}" class="inline-flex items-center gap-2 rounded-md px-1 py-1 hover:bg-surfaceMuted">
                    <span class="inline-flex h-7 w-7 items-center justify-center rounded-md bg-accentYellow-100 text-accentYellow-900">
                        <i data-lucide="layers" class="h-4 w-4"></i>
                    </span>
                    <span class="leading-tight">
                        <span class="block text-sm font-semibold text-textPrimary">{{ \App\Support\Brand::product() }}</span>
                    </span>
                </a>
                <p class="mt-3 max-w-xs text-sm text-textSecondary">
                    {{ $tagline }}
                </p>
                @if ($earlyAccessNote)
                    <p class="mt-2 max-w-xs text-xs text-textMuted">
                        {{ $earlyAccessNote }}
                    </p>
                @endif
                <x-layout.footer
                    class="mt-4"
                    align="left"
                    :statement="config('brand.show_parent_branding', true)
                        ? \App\Support\Brand::product().' is a product by '.\App\Support\Brand::parent()
                        : \App\Support\Brand::product()"
                />
            </div>

            <div class="grid grid-cols-2 gap-8 text-sm {{ $showResourcesColumn ? 'md:grid-cols-4' : 'md:grid-cols-3' }}">
                <div>
                    <p class="font-semibold text-textPrimary">{{ __('public.footer.product') }}</p>
                    <ul class="mt-3 space-y-2 text-textSecondary">
                        @foreach ($productItems as $item)
                            <li><a href="{{ MarketingNavigation::buildUrl($item) }}" class="hover:text-textPrimary">{{ $item['label'] }}</a></li>
                        @endforeach
                    </ul>
                </div>

                <div>
                    <p class="font-semibold text-textPrimary">{{ __('public.footer.company') }}</p>
                    <ul class="mt-3 space-y-2 text-textSecondary">
                        @foreach ($companyItems as $item)
                            <li><a href="{{ MarketingNavigation::buildUrl($item) }}" class="hover:text-textPrimary">{{ $item['label'] }}</a></li>
                        @endforeach
                    </ul>
                </div>

                @if ($showResourcesColumn)
                    <div>
                        <p class="font-semibold text-textPrimary">{{ __('public.footer.resources') }}</p>
                        <ul class="mt-3 space-y-2 text-textSecondary">
                            @foreach ($resourceItems as $item)
                                <li><a href="{{ MarketingNavigation::buildUrl($item) }}" class="hover:text-textPrimary">{{ $item['label'] }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div>
                    <p class="font-semibold text-textPrimary">{{ __('public.footer.legal') }}</p>
                    <ul class="mt-3 space-y-2 text-textSecondary">
                        @foreach ($legalItems as $item)
                            <li><a href="{{ MarketingNavigation::buildUrl($item) }}" class="hover:text-textPrimary">{{ $item['label'] }}</a></li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>
</footer>
