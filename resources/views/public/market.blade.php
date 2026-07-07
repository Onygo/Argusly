<!doctype html>
<html lang="{{ $publicLang ?? app()->getLocale() }}" class="scroll-smooth">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    @include('public.partials.seo-head', [
        'metaTitle' => $metaTitle,
        'metaDescription' => $metaDescription,
        'canonicalUrl' => $canonicalUrl,
        'hreflangUrls' => $hreflangUrls ?? [],
        'ogType' => 'website',
    ])
    @include('partials.brand-meta')
    @include('public.partials.argusly-tracking', ['canonicalUrl' => $canonicalUrl])
    @include('public.partials.analytics')
    @vite(['resources/css/app.css', 'resources/js/public.js'])
    <script defer src="https://unpkg.com/lucide@latest"></script>
    <script type="application/ld+json">
        {!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $page['meta_title'],
            'description' => $page['meta_description'],
            'url' => $canonicalUrl,
            'about' => [
                '@type' => 'Thing',
                'name' => $page['label'],
            ],
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => 'Argusly',
                'url' => \App\Support\LocalizedMarketingUrl::route('landing'),
            ],
            'potentialAction' => [
                '@type' => 'ContactAction',
                'name' => $page['demo_cta'],
                'target' => $contactCta,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>
</head>
<body class="pl-marketing-v2 bg-background text-textSecondary antialiased">
@include('public.partials.analytics-body')
@include('public.partials.nav')

@php
    $sectionMap = [
        'ai_visibility' => ['label' => 'AI Visibility', 'icon' => 'sparkles', 'url' => $aiVisibilityUrl],
        'competitive_intelligence' => ['label' => 'Competitive Intelligence', 'icon' => 'radar', 'url' => $competitiveUrl],
        'opportunity_intelligence' => ['label' => 'Opportunity Intelligence', 'icon' => 'target', 'url' => $opportunityUrl],
        'agentic_marketing' => ['label' => 'Agentic Marketing', 'icon' => 'workflow', 'url' => $agenticUrl],
    ];
    $challenges = array_values((array) ($page['challenges'] ?? []));
    $useCases = array_values((array) ($page['use_cases'] ?? []));
@endphp

<main class="bg-background" data-page="market-{{ $marketKey }}">
    <section class="pl-public-hero">
        <div class="mx-auto grid max-w-6xl gap-10 px-4 py-16 sm:px-6 md:py-20 lg:grid-cols-[minmax(0,1fr)_minmax(340px,0.72fr)] lg:items-center">
            <div>
                <div class="pl-public-hero-label">
                    <i data-lucide="building-2" class="h-3.5 w-3.5"></i>
                    {{ $page['hero']['eyebrow'] }}
                </div>
                <h1 class="mt-5 pl-public-heading pl-public-heading-hero">{{ $page['hero']['title'] }}</h1>
                <p class="mt-5 max-w-3xl text-pretty text-base leading-8 text-textSecondary md:text-lg">{{ $page['hero']['intro'] }}</p>
                <p class="mt-4 max-w-3xl text-sm leading-7 text-white/72">{{ $page['hero']['challenge'] }}</p>
                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <a href="{{ $contactCta }}" class="pl-public-primary-button">{{ $page['demo_cta'] }}</a>
                    <a href="{{ $opportunityUrl }}" class="pl-public-secondary-button">Explore Opportunity Intelligence</a>
                </div>
            </div>

            <div class="pl-public-card p-5">
                <div class="rounded-md border border-border bg-[#fbfaf7] p-4">
                    <div class="flex items-center justify-between border-b border-border pb-3">
                        <span class="pl-public-eyebrow">Market signal loop</span>
                        <span class="rounded-md bg-publicPrimary px-2.5 py-1 text-xs font-medium text-white">Vertical</span>
                    </div>
                    <div class="mt-5 grid gap-3">
                        @foreach ($page['metrics'] as $metric)
                            <div class="pl-public-card-compact p-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-textMuted">{{ $metric['label'] }}</p>
                                <p class="mt-2 text-sm font-semibold text-textPrimary">{{ $metric['value'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    @if ($challenges !== [])
        <section class="bg-white">
            <div class="mx-auto grid max-w-6xl gap-10 px-4 py-16 sm:px-6 md:py-20 lg:grid-cols-[0.72fr_1.28fr]">
                <div>
                    <p class="pl-public-eyebrow">{{ __('public.markets.challenges_eyebrow') }}</p>
                    <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ __('public.markets.challenges_title') }}</h2>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($challenges as $challenge)
                        <div class="flex gap-3 rounded-md border border-border/80 bg-[#fbfaf7] p-4 text-sm leading-6 text-textSecondary">
                            <x-public.icon name="alert-circle" size="xs" class="mt-0.5 flex-none text-publicPrimary" />
                            <span>{{ $challenge }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section class="bg-white">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            @if ($challenges !== [])
                <div class="mb-10 max-w-3xl">
                    <p class="pl-public-eyebrow">{{ __('public.markets.how_eyebrow') }}</p>
                </div>
            @endif
            <div class="grid gap-6">
                @foreach ($sectionMap as $sectionKey => $meta)
                    @php($section = $page['sections'][$sectionKey])
                    <article id="{{ \Illuminate\Support\Str::slug($meta['label']) }}" class="grid gap-6 rounded-md border border-border/80 bg-[#fbfaf7] p-6 md:grid-cols-[0.72fr_1.28fr] md:p-8">
                        <div>
                            <div class="inline-flex h-10 w-10 items-center justify-center rounded-md border border-publicPrimary/15 bg-white text-publicPrimary">
                                <i data-lucide="{{ $meta['icon'] }}" class="h-5 w-5"></i>
                            </div>
                            <p class="mt-5 pl-public-eyebrow">{{ $meta['label'] }}</p>
                            <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ $section['title'] }}</h2>
                            <a href="{{ $meta['url'] }}" class="mt-5 inline-flex text-sm font-semibold text-publicPrimary hover:text-publicPrimaryHover">
                                {{ __('public.markets.learn_more', ['label' => $meta['label']]) }}
                            </a>
                        </div>
                        <div>
                            <p class="text-base leading-8 text-textSecondary">{{ $section['body'] }}</p>
                            <ul class="mt-6 grid gap-3 sm:grid-cols-2">
                                @foreach ($section['points'] as $point)
                                    <li class="flex gap-3 rounded-md border border-border bg-white p-4 text-sm leading-6 text-textSecondary">
                                        <x-public.icon name="check" size="xs" class="mt-0.5 flex-none text-publicPrimary" />
                                        <span>{{ $point }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    @if ($useCases !== [])
        <section class="pl-public-warm">
            <div class="mx-auto grid max-w-6xl gap-10 px-4 py-16 sm:px-6 md:py-20 lg:grid-cols-[0.72fr_1.28fr]">
                <div>
                    <p class="pl-public-eyebrow">{{ __('public.markets.use_cases_eyebrow') }}</p>
                    <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ __('public.markets.use_cases_title') }}</h2>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($useCases as $useCase)
                        <div class="pl-public-card-compact p-4">
                            <div class="flex items-start gap-3 text-sm leading-6 text-textSecondary">
                                <x-public.icon name="check" size="xs" class="mt-0.5 flex-none text-publicPrimary" />
                                <span>{{ $useCase }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section class="pl-public-warm">
        <div class="mx-auto grid max-w-6xl gap-10 px-4 py-16 sm:px-6 md:py-20 lg:grid-cols-[0.72fr_1.28fr]">
            <div>
                <p class="pl-public-eyebrow">{{ __('public.markets.seo_eyebrow') }}</p>
                <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ __('public.markets.seo_title') }}</h2>
                <p class="mt-4 text-sm leading-7 text-textSecondary">{{ __('public.markets.seo_text') }}</p>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <div class="pl-public-card p-5">
                    <h3 class="pl-public-heading pl-public-heading-card">Schema markup opportunities</h3>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach ($page['schema_opportunities'] as $schemaType)
                            <span class="pl-public-pill-soft">{{ $schemaType }}</span>
                        @endforeach
                    </div>
                </div>
                <div class="pl-public-card p-5">
                    <h3 class="pl-public-heading pl-public-heading-card">Recommended content clusters</h3>
                    <ul class="mt-4 space-y-3 text-sm leading-6 text-textSecondary">
                        @foreach ($page['content_clusters'] as $cluster)
                            <li class="flex gap-3">
                                <x-public.icon name="arrow-right" size="xs" class="mt-0.5 flex-none text-publicPrimary" />
                                <span>{{ $cluster }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <section class="bg-white">
        <div class="mx-auto grid max-w-6xl gap-10 px-4 py-16 sm:px-6 md:py-20 lg:grid-cols-[0.72fr_1.28fr]">
            <div>
                <p class="pl-public-eyebrow">{{ __('public.markets.more_eyebrow') }}</p>
                <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ __('public.markets.more_title') }}</h2>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                @foreach ($marketLinks as $market)
                    @continue($market['key'] === $marketKey)
                    <a href="{{ $market['url'] }}" class="pl-public-card-compact p-5 transition-colors hover:bg-[#fbfaf7]">
                        <h3 class="pl-public-heading pl-public-heading-card">{{ $market['label'] }}</h3>
                        <p class="mt-2 text-sm leading-7 text-textSecondary">{{ $market['description'] }}</p>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    <x-public.faq-section
        page-type="market"
        :page-slug="$marketKey"
        :locale="app()->getLocale()"
    />

    <section class="pl-public-warm">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="pl-public-cta-panel pl-public-cta-panel--split p-8 md:p-10">
                <div class="grid gap-8 lg:grid-cols-[1fr_auto] lg:items-center">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.14em] text-white/60">{{ $page['label'] }}</p>
                        <h2 class="mt-3 max-w-3xl pl-public-heading pl-public-heading-h2 text-white">{{ __('public.markets.cta_title') }}</h2>
                        <p class="mt-4 max-w-2xl text-sm leading-7 text-white/76">{{ $page['description'] }}</p>
                    </div>
                    <a href="{{ $contactCta }}" class="pl-public-cta-primary">{{ $page['demo_cta'] }}</a>
                </div>
            </div>
        </div>
    </section>
</main>

@include('public.partials.footer')

</body>
</html>
