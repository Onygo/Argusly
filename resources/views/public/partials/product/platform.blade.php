@php
    $platformSections = $sections ?? [];
    $platformHeroLinks = __('public.page.platform.hero_links');
@endphp

{{-- Hero --}}
<section class="pl-public-hero-brand">
    <div class="mx-auto grid max-w-6xl gap-7 px-4 py-16 sm:px-6 md:grid-cols-[1.1fr_0.9fr] md:items-center md:gap-10 md:py-20">
        <div class="max-w-xl">
            <span class="inline-flex items-center rounded-full border border-white/16 bg-white/10 px-3 py-1 text-xs font-medium text-white">
                {{ __('public.page.product_badges.platform') }}
            </span>
            <h1 class="mt-4 text-balance text-4xl font-semibold tracking-tight text-white md:text-5xl">
                {{ $heading }}
            </h1>
            <p class="mt-4 max-w-lg text-pretty text-sm leading-7 text-white/80 md:text-base">
                {{ $intro }}
            </p>

            <div class="mt-8 flex flex-wrap gap-3">
                @foreach ($platformHeroLinks as $link)
                    <a href="{{ $link['href'] }}" class="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-white/15">
                        <span>{{ $link['label'] }}</span>
                        <x-public.icon name="arrow-down" size="xs" />
                    </a>
                @endforeach
            </div>
        </div>

        <x-public.hero-visual
            variant="product-overview"
            schematic="product-overview"
            desktop-wrapper-class="hidden rounded-2xl border border-white/16 bg-white/10 p-6 shadow-sm md:block"
            desktop-inner-class=""
        />
    </div>
</section>

{{-- Capabilities --}}
@if (!empty($platformSections['capabilities']))
    @php
        $capSection = $platformSections['capabilities'];
        $capIcons = ['wand-2', 'layout-template', 'plug-zap', 'bar-chart-3'];
    @endphp
    <section id="capabilities" class="scroll-mt-24 border-y border-border bg-white">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="mb-10 max-w-2xl">
                <span class="pl-public-pill-soft">
                    {{ $capSection['eyebrow'] }}
                </span>
                <h2 class="mt-4 text-3xl font-semibold tracking-tight text-textPrimary">
                    {{ $capSection['title'] }}
                </h2>
                <p class="mt-3 text-sm leading-7 text-textSecondary md:text-base">
                    {{ $capSection['intro'] }}
                </p>
            </div>

            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                @foreach (($capSection['cards'] ?? []) as $index => $card)
                    <article class="pl-public-card-soft p-6">
                        <x-public.icon :name="$capIcons[$index] ?? 'sparkles'" size="md" />
                        <h3 class="mt-4 text-base font-semibold text-textPrimary">{{ $card['title'] }}</h3>
                        <ul class="mt-4 space-y-3 text-sm text-textSecondary">
                            @foreach($card['bullets'] as $bullet)
                                <li class="flex items-start gap-3">
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
@endif

{{-- Governance (dark) --}}
@if (!empty($platformSections['governance']))
    @php
        $govSection = $platformSections['governance'];
        $govIcons = ['shield-check', 'users', 'history'];
    @endphp
    <section id="governance" class="scroll-mt-24 border-y border-publicPrimary/10 bg-publicPrimary">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="mb-10 max-w-2xl">
                <span class="inline-flex items-center rounded-full border border-white/16 bg-white/10 px-3 py-1 text-xs font-medium text-white">
                    {{ $govSection['eyebrow'] }}
                </span>
                <h2 class="mt-4 text-3xl font-semibold tracking-tight text-white">
                    {{ $govSection['title'] }}
                </h2>
                <p class="mt-3 text-sm leading-7 text-white/78 md:text-base">
                    {{ $govSection['intro'] }}
                </p>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                @foreach (($govSection['cards'] ?? []) as $index => $card)
                    <article class="rounded-2xl border border-white/12 bg-white/8 p-6">
                        <x-public.icon :name="$govIcons[$index] ?? 'shield'" size="md" class="bg-white/12 text-white" />
                        <h3 class="mt-4 text-base font-semibold text-white">{{ $card['title'] }}</h3>
                        <ul class="mt-4 space-y-3 text-sm text-white/85">
                            @foreach($card['bullets'] as $bullet)
                                <li class="flex items-start gap-3">
                                    <x-public.icon name="check" size="xs" class="mt-0.5 flex-none bg-white/10 text-white" />
                                    <span>{{ $bullet }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </article>
                @endforeach
            </div>
        </div>
    </section>
@endif

{{-- Intelligence --}}
@if (!empty($platformSections['intelligence']))
    @php
        $intelSection = $platformSections['intelligence'];
        $intelIcons = ['sparkles', 'line-chart', 'git-branch', 'search'];
    @endphp
    <section id="intelligence" class="scroll-mt-24 pl-public-warm">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="grid gap-10 lg:grid-cols-[0.9fr_1.1fr] lg:items-start">
                <div>
                    <span class="pl-public-pill">
                        {{ $intelSection['eyebrow'] }}
                    </span>
                    <h2 class="mt-4 text-3xl font-semibold tracking-tight text-textPrimary">
                        {{ $intelSection['title'] }}
                    </h2>
                    <p class="mt-3 text-sm leading-7 text-textSecondary md:text-base">
                        {{ $intelSection['intro'] }}
                    </p>
                    @if (!empty($intelSection['transition']))
                        <p class="mt-4 text-sm leading-7 text-textMuted">
                            {{ $intelSection['transition'] }}
                        </p>
                    @endif
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach (($intelSection['cards'] ?? []) as $index => $card)
                        <article class="pl-public-card p-6">
                            <x-public.icon :name="$intelIcons[$index] ?? 'sparkles'" size="md" />
                            <h3 class="mt-4 text-base font-semibold text-textPrimary">{{ $card['title'] }}</h3>
                            <ul class="mt-4 space-y-3 text-sm text-textSecondary">
                                @foreach($card['bullets'] as $bullet)
                                    <li class="flex items-start gap-3">
                                        <x-public.icon name="check" size="xs" class="mt-0.5 flex-none" />
                                        <span>{{ $bullet }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </article>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
@endif

{{-- CTA --}}
<section class="pl-public-warm">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="pl-public-cta-panel">
            <h2 class="text-balance text-2xl font-semibold tracking-tight text-white md:text-3xl">
                {{ $ctaHeading ?? __('public.page.platform.cta_title') }}
            </h2>
            <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-white/76 md:text-base">
                {{ $ctaText ?? __('public.page.platform.cta_text') }}
            </p>

            @php($ctaPoints = __('public.page.platform.cta_points'))
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
