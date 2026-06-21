@php
    use App\Support\MarketingNavigation;

    $markets = MarketingNavigation::marketItems();
    $variant = $variant ?? 'warm';
    $sectionClass = $variant === 'white' ? 'bg-white' : 'pl-public-warm';
@endphp

@if ($markets !== [])
    <section class="{{ $sectionClass }}">
        <div class="mx-auto grid max-w-6xl gap-8 px-4 py-14 sm:px-6 md:py-16 lg:grid-cols-[0.72fr_1.28fr]">
            <div>
                <p class="pl-public-eyebrow">{{ __('public.markets.eyebrow') }}</p>
                <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ $title ?? __('public.markets.internal_title') }}</h2>
                <p class="mt-4 text-sm leading-7 text-textSecondary">{{ $text ?? __('public.markets.internal_text') }}</p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($markets as $market)
                    <a href="{{ MarketingNavigation::buildUrl($market) }}" class="pl-public-card-compact p-4 transition-colors hover:bg-[#fbfaf7]">
                        <h3 class="text-sm font-semibold text-textPrimary">{{ $market['label'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $market['description'] }}</p>
                    </a>
                @endforeach
            </div>
        </div>
    </section>
@endif
