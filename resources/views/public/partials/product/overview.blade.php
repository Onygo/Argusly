{{-- Hero --}}
<section class="pl-public-hero">
    <div class="mx-auto grid max-w-6xl gap-7 px-4 py-16 sm:px-6 md:grid-cols-2 md:items-center md:gap-10 md:py-20">
        <div class="max-w-xl">
            <span class="inline-flex items-center rounded-full border border-publicPrimary/15 bg-white px-3 py-1 text-xs font-medium text-publicPrimary">{{ __('public.page.product_badges.overview') }}</span>
            <h1 class="mt-4 text-balance text-4xl font-semibold tracking-tight text-textPrimary md:text-5xl">
                {{ __('public.page.overview.hero_title') }}
            </h1>
            <p class="mt-3 text-lg font-medium text-textPrimary">
                {{ __('public.page.overview.hero_subtitle') }}
            </p>
            <p class="mt-4 max-w-prose text-pretty text-sm leading-7 text-textSecondary md:text-base">
                {{ $intro }}
            </p>
        </div>

        <x-public.hero-visual
            variant="product-overview"
            schematic="product-overview"
            desktop-wrapper-class="hidden pl-public-card p-6 md:block"
            desktop-inner-class=""
        />
    </div>
</section>

{{-- What You Get --}}
@php
    $whatYouGetCards = trans('public.page.overview.what_you_get_cards');
@endphp

<section class="border-y border-border bg-white">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="mx-auto mb-12 max-w-2xl text-center">
            <h2 class="text-2xl font-semibold text-textPrimary md:text-3xl">{{ __('public.page.overview.what_you_get_title') }}</h2>
            <p class="mt-3 text-sm text-textSecondary md:text-base">{{ __('public.page.overview.what_you_get_text') }}</p>
        </div>

        <div class="grid gap-6 md:grid-cols-3 md:gap-8">
            @foreach($whatYouGetCards as $index => $card)
                @php
                    $isMiddle = $index === 1;
                    $cardClasses = $isMiddle
                        ? 'rounded-2xl border-2 border-publicPrimary/20 bg-[#f8fafc] p-6 md:p-7'
                        : 'pl-public-card-soft p-6 md:p-7';
                @endphp
                <article class="{{ $cardClasses }} flex flex-col">
                    <div class="flex items-start gap-4">
                        <x-public.icon :name="$card['icon']" size="md" />
                        <div class="flex-1">
                            <h3 class="text-base font-semibold text-textPrimary md:text-lg">{{ $card['title'] }}</h3>
                            @if (!empty($card['intro']))
                                <p class="mt-1 text-sm text-textSecondary">{{ $card['intro'] }}</p>
                            @endif
                        </div>
                    </div>

                    <ul class="mt-5 flex-1 space-y-3">
                        @foreach($card['bullets'] as $bullet)
                            <li class="flex items-start gap-3 text-sm text-textSecondary">
                                <x-public.icon name="check" size="xs" class="mt-0.5 flex-none" />
                                <span>{{ $bullet }}</span>
                            </li>
                        @endforeach
                    </ul>
                </article>
            @endforeach
        </div>
    </div>
</section>

{{-- How It Works Flow --}}
<section class="border-y border-publicPrimary/10 bg-publicPrimary">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="mx-auto max-w-3xl text-center">
            <h2 class="text-2xl font-semibold text-white md:text-3xl">{{ __('public.page.overview.flow_title') }}</h2>
            <p class="mt-2 text-sm text-white/76 md:text-base">{{ __('public.page.overview.flow_text') }}</p>
        </div>

        <div class="mt-10 rounded-2xl border border-white/12 bg-white/8 px-5 py-7 md:px-8">
            <div class="grid items-center gap-6 md:grid-cols-4">
                @php
                    $flowIcons = ['list-checks', 'sparkles', 'users', 'check-circle-2'];
                @endphp
                @foreach(trans('public.page.overview.flow_steps') as $index => $step)
                    <div class="text-center">
                        <x-public.icon :name="$flowIcons[$index] ?? 'check'" size="md" class="mx-auto bg-white/95 text-publicPrimary" />
                        <p class="mt-2 text-xs font-semibold text-white">{{ $step['label'] }}</p>
                        <p class="mt-1 text-xs text-white/72">{{ $step['text'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>

{{-- Agentic Marketing Layer --}}
<section class="border-y border-border bg-white">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="grid gap-10 lg:grid-cols-[0.82fr_1.18fr] lg:items-center">
            <div>
                <span class="inline-flex items-center rounded-full border border-publicPrimary/15 bg-[#f8fafc] px-3 py-1 text-xs font-medium text-publicPrimary">Agentic Marketing</span>
                <h2 class="mt-4 text-3xl font-semibold tracking-tight text-textPrimary md:text-4xl">{{ __('public.page.overview.agentic_title') }}</h2>
                <p class="mt-4 text-sm leading-7 text-textSecondary md:text-base">{{ __('public.page.overview.agentic_text') }}</p>
                <div class="mt-7">
                    <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.agentic-marketing') }}" class="inline-flex items-center justify-center gap-2 rounded-xl border border-publicPrimary/18 bg-white px-6 py-3 text-sm font-semibold text-publicPrimary transition-colors hover:bg-[#f8fafc]">
                        {{ __('public.page.overview.agentic_cta') }}
                        <x-public.icon name="arrow-right" size="xs" />
                    </a>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                @foreach (__('public.page.overview.agentic_points') as $point)
                    <div class="pl-public-card-compact pl-public-canvas p-5">
                        <x-public.icon name="sparkles" size="sm" />
                        <p class="mt-4 text-sm font-semibold leading-6 text-textPrimary">{{ $point }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>

{{-- CTA --}}
<section class="pl-public-warm">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="pl-public-cta-panel">
            <h2 class="text-balance text-2xl font-semibold tracking-tight text-white md:text-3xl">
                {{ __('public.page.overview.cta_title') }}
            </h2>
            <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-white/76 md:text-base">
                {{ __('public.page.overview.cta_text') }}
            </p>

            @php($ctaPoints = __('public.page.overview.cta_points'))
            @if(is_array($ctaPoints) && count($ctaPoints) > 0)
                <div class="mt-5 flex flex-wrap items-center justify-center gap-x-4 gap-y-2 text-sm text-white/76">
                    @foreach($ctaPoints as $point)
                        <span class="flex items-center gap-2">
                            <span class="h-1 w-1 rounded-full bg-white/55"></span>
                            {{ $point }}
                        </span>
                    @endforeach
                </div>
            @endif

            <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                <a href="{{ $primaryCtaHref }}" class="pl-public-cta-primary">
                    {{ $primaryCtaLabel }}
                </a>
                <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.contact') }}" class="pl-public-cta-secondary">
                    {{ $ctaSecondary ?? __('public.cta.secondary') }}
                </a>
            </div>
        </div>
    </div>
</section>
