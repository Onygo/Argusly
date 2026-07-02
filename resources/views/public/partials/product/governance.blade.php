@php
    $governance = __('public.page.governance');
    $heroStats = (array) ($governance['hero_stats'] ?? []);
    $trustCards = (array) ($governance['trust_cards'] ?? []);
    $workflow = (array) ($governance['workflow'] ?? []);
    $enterprise = (array) ($governance['enterprise'] ?? []);
    $readiness = (array) ($governance['readiness'] ?? []);
    $capabilities = (array) ($governance['capabilities'] ?? []);
@endphp

<section class="pl-public-hero-brand">
    <div class="mx-auto grid max-w-6xl gap-8 px-4 py-16 sm:px-6 lg:grid-cols-[1.05fr_0.95fr] lg:items-center md:py-20">
        <div class="max-w-2xl">
            <span class="pl-public-hero-label">
                <x-public.icon name="shield-check" size="xs" />
                {{ __('public.page.product_badges.governance') }}
            </span>
            <h1 class="mt-4 pl-public-heading pl-public-heading-hero text-white">
                {{ $heading }}
            </h1>
            <p class="mt-5 max-w-xl text-sm leading-7 text-white/80 md:text-base">
                {{ $intro }}
            </p>
            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.contact', ['subject' => 'ai-governance'], app()->getLocale()) }}#contact-form" class="pl-public-cta-primary">
                    {{ $governance['primary_cta'] }}
                </a>
                <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.legal.ai-transparency', [], app()->getLocale()) }}" class="pl-public-cta-secondary">
                    {{ $governance['secondary_cta'] }}
                </a>
            </div>
        </div>

        <div class="rounded-md border border-white/16 bg-white/10 p-5">
            <div class="rounded-md border border-white/12 bg-white p-5 text-textPrimary">
                <div class="flex items-center justify-between border-b border-border pb-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-publicPrimary">{{ $governance['panel_label'] }}</p>
                        <p class="mt-1 text-sm font-semibold">{{ $governance['panel_title'] }}</p>
                    </div>
                    <span class="rounded-md bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">{{ $governance['panel_state'] }}</span>
                </div>
                <div class="mt-5 grid gap-3">
                    @foreach($heroStats as $stat)
                        <div class="flex items-center justify-between rounded-md border border-border bg-surfaceSubtle px-4 py-3">
                            <span class="text-sm text-textSecondary">{{ $stat['label'] }}</span>
                            <span class="text-sm font-semibold text-textPrimary">{{ $stat['value'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>

<section class="bg-white">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="mb-10 max-w-3xl">
            <span class="pl-public-pill-soft">{{ $governance['trust_eyebrow'] }}</span>
            <h2 class="mt-4 pl-public-heading pl-public-heading-h2">{{ $governance['trust_title'] }}</h2>
            <p class="mt-3 text-sm leading-7 text-textSecondary md:text-base">{{ $governance['trust_text'] }}</p>
        </div>

        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            @foreach($trustCards as $card)
                <article class="pl-public-card-soft p-6">
                    <x-public.icon :name="$card['icon'] ?? 'check-circle'" size="md" />
                    <h3 class="mt-4 pl-public-heading pl-public-heading-card">{{ $card['title'] }}</h3>
                    <p class="mt-3 text-sm leading-6 text-textSecondary">{{ $card['text'] }}</p>
                </article>
            @endforeach
        </div>
    </div>
</section>

<section class="pl-public-warm">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="grid gap-10 lg:grid-cols-[0.85fr_1.15fr] lg:items-start">
            <div>
                <span class="pl-public-pill">{{ $workflow['eyebrow'] }}</span>
                <h2 class="mt-4 pl-public-heading pl-public-heading-h2">{{ $workflow['title'] }}</h2>
                <p class="mt-3 text-sm leading-7 text-textSecondary md:text-base">{{ $workflow['text'] }}</p>
            </div>
            <div class="grid gap-3">
                @foreach(($workflow['steps'] ?? []) as $step)
                    <article class="pl-public-card-compact bg-white p-5">
                        <div class="flex items-start gap-4">
                            <span class="flex h-9 w-9 flex-none items-center justify-center rounded-md bg-publicPrimary text-sm font-semibold text-white">{{ $loop->iteration }}</span>
                            <div>
                                <h3 class="text-sm font-semibold text-textPrimary">{{ $step['title'] }}</h3>
                                <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $step['text'] }}</p>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        </div>
    </div>
</section>

<section class="border-y border-publicPrimary/10 bg-publicPrimary">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="grid gap-8 lg:grid-cols-[0.9fr_1.1fr] lg:items-center">
            <div>
                <span class="pl-public-hero-label">{{ $readiness['eyebrow'] }}</span>
                <h2 class="mt-4 pl-public-heading pl-public-heading-h2 text-white">{{ $readiness['title'] }}</h2>
                <p class="mt-3 text-sm leading-7 text-white/78 md:text-base">{{ $readiness['text'] }}</p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach(($readiness['points'] ?? []) as $point)
                    <div class="rounded-md border border-white/12 bg-white/8 p-4 text-sm leading-6 text-white/85">
                        <x-public.icon name="check" size="xs" class="mb-3 bg-white/10 text-white" />
                        {{ $point }}
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>

<section class="bg-white">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="grid gap-8 lg:grid-cols-2">
            <article class="pl-public-card p-7">
                <h2 class="pl-public-heading pl-public-heading-h3">{{ $enterprise['title'] }}</h2>
                <p class="mt-3 text-sm leading-7 text-textSecondary">{{ $enterprise['text'] }}</p>
                <ul class="mt-5 space-y-3">
                    @foreach(($enterprise['points'] ?? []) as $point)
                        <li class="flex items-start gap-3 text-sm leading-6 text-textSecondary">
                            <x-public.icon name="check" size="xs" class="mt-0.5 flex-none" />
                            <span>{{ $point }}</span>
                        </li>
                    @endforeach
                </ul>
            </article>
            <article class="pl-public-card-soft p-7">
                <h2 class="pl-public-heading pl-public-heading-h3">{{ $capabilities['title'] }}</h2>
                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                    @foreach(($capabilities['items'] ?? []) as $item)
                        <div class="rounded-md border border-border bg-white px-4 py-3 text-sm font-medium text-textPrimary">
                            {{ $item }}
                        </div>
                    @endforeach
                </div>
            </article>
        </div>
    </div>
</section>

<section class="pl-public-warm">
    <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
        <div class="pl-public-cta-panel">
            <h2 class="pl-public-heading pl-public-heading-h2 text-white">{{ $governance['cta_title'] }}</h2>
            <p class="mx-auto mt-3 max-w-2xl text-sm leading-7 text-white/76 md:text-base">{{ $governance['cta_text'] }}</p>
            <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.contact', ['subject' => 'ai-governance'], app()->getLocale()) }}#contact-form" class="pl-public-cta-primary">
                    {{ $governance['primary_cta'] }}
                </a>
                <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.product.platform', [], app()->getLocale()) }}" class="pl-public-cta-secondary">
                    {{ $governance['platform_cta'] }}
                </a>
            </div>
        </div>
    </div>
</section>
