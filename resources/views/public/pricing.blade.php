<!doctype html>
<html lang="{{ $publicLang ?? app()->getLocale() }}" class="scroll-smooth">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    @include('public.partials.seo-head', [
        'metaTitle' => $metaTitle ?? __('public.landing.pricing_meta_title'),
        'metaDescription' => $metaDescription ?? __('public.landing.pricing_meta_description'),
        'canonicalUrl' => $canonicalUrl ?? null,
        'hreflangUrls' => $hreflangUrls ?? [],
        'ogType' => 'website',
    ])
    @include('partials.brand-meta')
    @include('public.partials.publishlayer-tracking', ['canonicalUrl' => $canonicalUrl ?? null])
    @include('public.partials.analytics')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-background text-textSecondary antialiased">
@include('public.partials.analytics-body')
@include('public.partials.nav')

@php
    $locale = app()->getLocale();
    $hero = (array) ($pageContent['hero'] ?? []);
    $comparison = (array) ($pageContent['comparison'] ?? []);
    $credits = (array) ($pageContent['credits'] ?? []);
    $creditPacksSection = (array) ($pageContent['credit_packs'] ?? []);
    $teamWorkflow = (array) ($pageContent['team_workflow'] ?? []);
    $roi = (array) ($pageContent['roi'] ?? []);
    $faqItems = collect((array) ($pageContent['faq'] ?? []));
    $finalCta = (array) ($pageContent['final_cta'] ?? []);
    $canSelfRegister = (bool) config('publishlayer.launch.public_registration_enabled', true)
        && ! (bool) config('publishlayer.launch.soft_launch_mode', false);
    $registerHref = $canSelfRegister
        ? route('register', ['plan' => 'creator'])
        : \App\Support\LocalizedMarketingUrl::route('public.early-access.show', ['intent' => 'early_access'], $locale);
    $contactHref = \App\Support\LocalizedMarketingUrl::route('public.contact', ['subject' => 'enterprise-pricing'], $locale) . '#contact-form';
    $plansCollection = collect($plans ?? [])->sortBy('sort_order')->values();
    $creditPacksCollection = collect($creditPacks ?? [])->sortBy('sort_order')->values();
    $formatCurrency = function (?int $amountCents, string $currency = 'EUR'): string {
        if ($amountCents === null) {
            return '';
        }

        $amount = number_format($amountCents / 100, 0);

        return strtoupper($currency) === 'EUR' ? '€' . $amount : strtoupper($currency) . ' ' . $amount;
    };
@endphp

<main class="bg-background" data-page="pricing">
    <section class="border-b border-border/70 bg-[#f3f0e8]">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-24">
            <div class="grid gap-10 lg:grid-cols-[minmax(0,1.2fr)_minmax(320px,0.8fr)] lg:items-end">
                <div class="max-w-3xl">
                    <span class="inline-flex items-center rounded-full border border-publicPrimary/15 bg-white px-3 py-1 text-xs font-medium text-publicPrimary">
                        {{ $hero['eyebrow'] ?? 'Premium content operations' }}
                    </span>
                    <h1 class="mt-5 text-balance text-4xl font-semibold tracking-tight text-textPrimary md:text-6xl">
                        {{ $hero['headline'] ?? 'Scale content operations beyond AI writing' }}
                    </h1>
                    <p class="mt-5 max-w-2xl text-lg leading-8 text-textPrimary">
                        {{ $hero['subheadline'] ?? 'Plan, generate, optimize, localize and publish content from one platform.' }}
                    </p>
                    <p class="mt-4 max-w-2xl text-base leading-7 text-textSecondary">
                        {{ $hero['supporting_text'] ?? 'More than AI writing. Argusly manages the full content lifecycle.' }}
                    </p>
                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <a href="#plans" class="inline-flex items-center justify-center rounded-xl bg-publicPrimary px-5 py-3 text-sm font-semibold text-white transition-colors hover:bg-publicPrimaryHover">
                            {{ $hero['primary_cta_label'] ?? 'Choose a plan' }}
                        </a>
                        <a href="{{ $contactHref }}" class="pl-public-secondary-button">
                            {{ $hero['secondary_cta_label'] ?? 'Talk to sales' }}
                        </a>
                    </div>
                </div>

                <div class="rounded-[28px] border border-border/80 bg-white p-6 sm:p-7">
                    <p class="text-sm font-semibold text-textPrimary">{{ $credits['title'] ?? 'Flexible AI credits' }}</p>
                    <p class="mt-3 text-sm leading-6 text-textSecondary">
                        {{ $credits['body'] ?? 'Credits are consumed by generation, translations, refreshes, answer blocks, research and AI visibility workflows.' }}
                    </p>
                    <div class="mt-6 space-y-3 border-t border-border/70 pt-6">
                        <div class="flex items-start gap-3 text-sm">
                            <x-public.icon name="refresh-cw" size="xs" class="mt-0.5 flex-none text-publicPrimary" />
                            <span>Unused subscription credits roll over for 3 months.</span>
                        </div>
                        <div class="flex items-start gap-3 text-sm">
                            <x-public.icon name="layers" size="xs" class="mt-0.5 flex-none text-publicPrimary" />
                            <span>Purchased credit packs remain valid for 12 months.</span>
                        </div>
                        <div class="flex items-start gap-3 text-sm">
                            <x-public.icon name="users" size="xs" class="mt-0.5 flex-none text-publicPrimary" />
                            <span>Credits are shared across the workspace team.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="plans" class="bg-white">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:gap-8 xl:grid-cols-3">
                @foreach($plansCollection as $plan)
                    @php
                        $isPopular = (bool) ($plan['is_popular'] ?? false);
                        $features = array_values(array_filter((array) ($plan['features'] ?? [])));
                        $price = $formatCurrency($plan['price_monthly_cents'] ?? null, (string) ($plan['currency'] ?? 'EUR'));
                        $ctaHref = $canSelfRegister
                            ? route('register', ['plan' => $plan['slug']])
                            : \App\Support\LocalizedMarketingUrl::route('public.early-access.show', ['intent' => 'early_access'], $locale);
                    @endphp
                    <article
                        data-pricing-card
                        class="relative flex h-full min-h-[620px] flex-col rounded-[28px] border p-8 sm:p-9 {{ $isPopular ? 'border-publicPrimary bg-[#fbfaf6]' : 'border-border/80 bg-[#fcfbf8]' }}"
                    >
                        @if($isPopular)
                            <div class="absolute right-6 top-6">
                                <span class="inline-flex items-center rounded-full bg-publicPrimary px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-white">
                                    {{ $plan['badge'] ?: 'Most popular' }}
                                </span>
                            </div>
                        @endif

                        <div class="pr-20">
                            @if(! empty($plan['eyebrow']))
                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-textMuted">{{ $plan['eyebrow'] }}</p>
                            @endif
                            <h2 class="mt-3 text-2xl font-semibold tracking-tight text-textPrimary">{{ $plan['name'] }}</h2>
                            <p class="mt-3 text-sm leading-6 text-textSecondary">{{ $plan['audience'] ?? $plan['description'] }}</p>
                        </div>

                        <div class="mt-8">
                            <div class="flex items-end gap-2">
                                <span class="text-5xl font-semibold tracking-tight text-textPrimary">{{ $price }}</span>
                                <span class="pb-1 text-sm text-textSecondary">/ month</span>
                            </div>
                            <p class="mt-4 text-sm font-medium text-textPrimary">
                                {{ number_format((int) ($plan['included_credits_monthly'] ?? 0)) }} credits / month
                            </p>
                            @if(($plan['article_estimate_min'] ?? null) !== null && ($plan['article_estimate_max'] ?? null) !== null)
                                <p class="mt-2 text-xs leading-5 text-textMuted">
                                    Approx. {{ $plan['article_estimate_min'] }} to {{ $plan['article_estimate_max'] }} standard SEO articles
                                </p>
                            @endif
                        </div>

                        <div class="mt-6 rounded-2xl border border-border/70 bg-white px-4 py-4">
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-textMuted">Platform access</p>
                            <div class="mt-3 grid grid-cols-2 gap-3 text-sm text-textSecondary">
                                <div>
                                    <p class="font-medium text-textPrimary">{{ $plan['workspace_limit'] ?? 'Custom' }}</p>
                                    <p>Workspaces</p>
                                </div>
                                <div>
                                    <p class="font-medium text-textPrimary">{{ $plan['user_limit'] ?? 'Custom' }}</p>
                                    <p>Users</p>
                                </div>
                            </div>
                        </div>

                        <ul class="mt-7 space-y-3.5 text-sm leading-6 text-textSecondary">
                            @foreach($features as $feature)
                                <li class="flex items-start gap-3">
                                    <x-public.icon name="check" size="xs" class="mt-0.5 flex-none text-publicPrimary" />
                                    <span>{{ $feature }}</span>
                                </li>
                            @endforeach
                        </ul>

                        <div class="mt-auto pt-8">
                            <a href="{{ $ctaHref }}" class="inline-flex w-full items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold transition-colors {{ $isPopular ? 'bg-publicPrimary text-white hover:bg-publicPrimaryHover' : 'border border-border bg-white text-textPrimary hover:bg-surfaceMuted' }}">
                                {{ $plan['cta_label'] ?: 'Choose plan' }}
                            </a>
                        </div>
                    </article>
                @endforeach
            </div>

            @if($enterprisePlan)
                <section class="mt-10 lg:mt-14" data-enterprise-block>
                    <article class="overflow-hidden rounded-[28px] border border-border/80 bg-textPrimary text-white">
                        <div class="bg-gradient-to-r from-white/[0.04] via-transparent to-transparent px-8 py-8 sm:px-10 sm:py-10 xl:px-12">
                            <div class="flex flex-col gap-10 xl:flex-row xl:items-start xl:justify-between">
                                <div class="max-w-2xl">
                                    <span class="inline-flex items-center rounded-full border border-white/15 bg-white/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-white/90">
                                        {{ $enterprisePlan['badge'] ?? 'Enterprise' }}
                                    </span>
                                    <h2 class="mt-5 text-3xl font-semibold tracking-tight sm:text-4xl">
                                        {{ $enterprisePlan['price_label'] ?? 'Custom pricing' }}
                                    </h2>
                                    <p class="mt-4 text-lg leading-8 text-white/88">
                                        {{ $enterprisePlan['audience'] ?? $enterprisePlan['description'] }}
                                    </p>
                                    <p class="mt-4 max-w-xl text-sm leading-7 text-white/72">
                                        {{ $enterprisePlan['name'] }} is built for organizations that need tailored governance, custom workflows, shared team operations, and enterprise-grade support.
                                    </p>
                                    <div class="mt-7">
                                        <a href="{{ $enterprisePlan['cta_url'] }}" class="inline-flex items-center justify-center rounded-xl bg-white px-5 py-3 text-sm font-semibold text-textPrimary transition-colors hover:bg-white/90">
                                            {{ $enterprisePlan['cta_label'] ?: 'Talk to sales' }}
                                        </a>
                                    </div>
                                </div>

                                <div class="w-full xl:max-w-2xl">
                                    <ul class="grid gap-x-8 gap-y-4 text-sm leading-6 text-white/84 md:grid-cols-2">
                                        @foreach((array) ($enterprisePlan['features'] ?? []) as $feature)
                                            <li class="flex items-start gap-3">
                                                <x-public.icon name="check" size="xs" class="mt-0.5 flex-none bg-white text-textPrimary" />
                                                <span>{{ $feature }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </article>
                </section>
            @endif
        </div>
    </section>

    <section class="pl-public-warm">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="max-w-3xl">
                <h2 class="text-3xl font-semibold tracking-tight text-textPrimary md:text-4xl">
                    {{ $comparison['title'] ?? 'More than AI writing' }}
                </h2>
                <p class="mt-4 text-base leading-7 text-textSecondary">
                    {{ $comparison['subtitle'] ?? 'Argusly helps teams manage the full content lifecycle from planning to publishing and AI discoverability.' }}
                </p>
            </div>

            <div class="mt-10 overflow-hidden rounded-[28px] border border-border/80 bg-white">
                <div class="grid grid-cols-[minmax(0,1.4fr)_180px_180px] border-b border-border/70 bg-[#fcfbf8] px-6 py-4 text-sm font-semibold text-textPrimary">
                    <div>Capabilities</div>
                    <div class="text-center">{{ $comparison['left_label'] ?? 'Argusly' }}</div>
                    <div class="text-center">{{ $comparison['right_label'] ?? 'Traditional AI writers' }}</div>
                </div>
                @foreach((array) ($comparison['rows'] ?? []) as $row)
                    <div class="grid grid-cols-[minmax(0,1.4fr)_180px_180px] items-center border-b border-border/60 px-6 py-4 text-sm last:border-b-0">
                        <div class="text-textPrimary">{{ $row['label'] ?? '' }}</div>
                        <div class="flex justify-center">
                            <x-public.icon name="{{ !empty($row['publishlayer']) ? 'check' : 'minus' }}" size="xs" class="{{ !empty($row['publishlayer']) ? 'text-publicPrimary' : 'text-textMuted' }}" />
                        </div>
                        <div class="flex justify-center">
                            <x-public.icon name="{{ !empty($row['alternative']) ? 'check' : 'minus' }}" size="xs" class="{{ !empty($row['alternative']) ? 'text-textPrimary' : 'text-textMuted' }}" />
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="bg-white">
        <div class="mx-auto grid max-w-6xl gap-8 px-4 py-16 sm:px-6 lg:grid-cols-[minmax(0,1fr)_minmax(320px,0.9fr)] md:py-20">
            <div>
                <h2 class="text-3xl font-semibold tracking-tight text-textPrimary md:text-4xl">
                    {{ $credits['title'] ?? 'Flexible AI credits' }}
                </h2>
                <p class="mt-4 max-w-2xl text-base leading-7 text-textSecondary">
                    {{ $credits['body'] ?? 'Credits are consumed by generation, translations, refreshes, answer blocks, research and AI visibility workflows.' }}
                </p>
                <p class="mt-4 max-w-2xl text-sm leading-6 text-textMuted">
                    {{ $credits['note'] ?? 'A standard SEO article typically uses 10 to 14 credits depending on content depth, research and optimization workflows.' }}
                </p>
            </div>
            <div class="rounded-[28px] border border-border/80 bg-[#fcfbf8] p-7">
                <ul class="space-y-4 text-sm text-textSecondary">
                    @foreach((array) ($credits['points'] ?? []) as $point)
                        <li class="flex items-start gap-3">
                            <x-public.icon name="check" size="xs" class="mt-0.5 flex-none text-publicPrimary" />
                            <span>{{ $point }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>

    <section class="pl-public-warm">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="max-w-3xl">
                <h2 class="text-3xl font-semibold tracking-tight text-textPrimary md:text-4xl">
                    {{ $creditPacksSection['title'] ?? 'Scale usage when needed' }}
                </h2>
                <p class="mt-4 text-base leading-7 text-textSecondary">
                    {{ $creditPacksSection['subtitle'] ?? 'Add flexible credit packs anytime without upgrading your plan.' }}
                </p>
            </div>

            <div class="mt-10 grid gap-6 md:grid-cols-3">
                @foreach($creditPacksCollection as $pack)
                    <article class="pl-public-card-compact p-6">
                        @if(! empty($pack['badge']))
                            <span class="inline-flex items-center rounded-full border border-publicPrimary/15 bg-publicPrimary/5 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-publicPrimary">
                                {{ $pack['badge'] }}
                            </span>
                        @endif
                        <h3 class="mt-4 text-2xl font-semibold tracking-tight text-textPrimary">{{ number_format((int) ($pack['credits'] ?? 0)) }} credits</h3>
                        <p class="mt-2 text-3xl font-semibold tracking-tight text-textPrimary">{{ $formatCurrency($pack['price_cents'] ?? 0, (string) ($pack['currency'] ?? 'EUR')) }}</p>
                        <p class="mt-3 text-sm leading-6 text-textSecondary">{{ $pack['description'] ?? '' }}</p>
                        @if(($pack['expires_in_months'] ?? null) !== null)
                            <p class="mt-4 text-xs text-textMuted">Valid for {{ $pack['expires_in_months'] }} months after purchase.</p>
                        @endif
                    </article>
                @endforeach
            </div>

            <div class="mt-8 flex flex-col gap-3 pl-public-card-compact px-6 py-5 text-sm text-textSecondary md:flex-row md:items-center md:justify-between">
                <div>
                    <p>{{ $creditPacksSection['footer_note'] ?? 'Purchased credit packs remain valid for 12 months and are shared across the workspace team.' }}</p>
                    <p class="mt-1 text-textMuted">{{ $creditPacksSection['custom_label'] ?? 'Custom enterprise packs available' }}</p>
                </div>
                <a href="{{ $contactHref }}" class="pl-public-secondary-button">
                    {{ $hero['secondary_cta_label'] ?? 'Talk to sales' }}
                </a>
            </div>
        </div>
    </section>

    <section class="bg-white">
        <div class="mx-auto grid max-w-6xl gap-8 px-4 py-16 sm:px-6 lg:grid-cols-2 md:py-20">
            <div class="rounded-[28px] border border-border/80 bg-[#fcfbf8] p-7 sm:p-8">
                <h2 class="text-3xl font-semibold tracking-tight text-textPrimary">{{ $teamWorkflow['title'] ?? 'Built for teams, workflows and scale' }}</h2>
                <p class="mt-4 text-base leading-7 text-textSecondary">{{ $teamWorkflow['subtitle'] ?? '' }}</p>
                <ul class="mt-6 space-y-4 text-sm text-textSecondary">
                    @foreach((array) ($teamWorkflow['points'] ?? []) as $point)
                        <li class="flex items-start gap-3">
                            <x-public.icon name="check" size="xs" class="mt-0.5 flex-none text-publicPrimary" />
                            <span>{{ $point }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="rounded-[28px] border border-border/80 bg-textPrimary p-7 text-white sm:p-8">
                <h2 class="text-3xl font-semibold tracking-tight">{{ $roi['title'] ?? 'Replace fragmented content workflows' }}</h2>
                <ul class="mt-6 grid gap-4 sm:grid-cols-2">
                    @foreach((array) ($roi['items'] ?? []) as $item)
                        <li class="rounded-2xl border border-white/10 bg-white/5 px-4 py-4 text-sm text-white/82">
                            {{ $item }}
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>

    <section class="pl-public-warm">
        <div class="mx-auto max-w-4xl px-4 py-16 sm:px-6 md:py-20">
            <div class="text-center">
                <h2 class="text-3xl font-semibold tracking-tight text-textPrimary md:text-4xl">FAQ</h2>
            </div>
            <div class="mt-10 space-y-4">
                @foreach($faqItems as $item)
                    <details class="group pl-public-card-compact">
                        <summary class="flex cursor-pointer items-center justify-between gap-4 px-6 py-5 text-left text-sm font-medium text-textPrimary">
                            <span>{{ $item['question'] ?? '' }}</span>
                            <x-public.icon name="chevron-down" size="xs" class="transition-transform group-open:rotate-180" />
                        </summary>
                        <div class="px-6 pb-5 text-sm leading-6 text-textSecondary">
                            {{ $item['answer'] ?? '' }}
                        </div>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    <section class="bg-publicPrimary">
        <div class="mx-auto max-w-5xl px-4 py-16 text-center sm:px-6 md:py-20">
            <h2 class="text-3xl font-semibold tracking-tight text-white md:text-4xl">
                {{ $finalCta['title'] ?? 'Move content operations into one scalable system' }}
            </h2>
            <p class="mx-auto mt-4 max-w-3xl text-base leading-7 text-white/80">
                {{ $finalCta['body'] ?? 'Run planning, AI-assisted production, localization, optimization and publishing from one operational platform.' }}
            </p>
            <div class="mt-8 flex flex-col items-center justify-center gap-4 sm:flex-row">
                <a href="{{ $registerHref }}" class="inline-flex items-center justify-center rounded-xl bg-white px-6 py-3 text-sm font-semibold text-publicPrimary transition-colors hover:bg-white/90">
                    {{ $finalCta['primary_label'] ?? 'Choose your plan' }}
                </a>
                <a href="{{ $contactHref }}" class="inline-flex items-center justify-center rounded-xl border border-white/20 bg-white/10 px-6 py-3 text-sm font-semibold text-white transition-colors hover:bg-white/20">
                    {{ $finalCta['secondary_label'] ?? 'Talk to sales' }}
                </a>
            </div>
        </div>
    </section>
</main>

@include('public.partials.footer')

<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (window.lucide) {
            lucide.createIcons();
        }
    });
</script>
</body>
</html>
