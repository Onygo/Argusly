<!doctype html>
<html lang="{{ $publicLang ?? app()->getLocale() }}" class="scroll-smooth">
<head>
    @php
        $softwareSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => 'Argusly',
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'url' => $canonicalUrl,
            'description' => $metaDescription,
        ];
        $linkCards = [
            'ai_visibility_agentic_marketing' => 'AI Visibility & Agentic Marketing',
            'ai_visibility_solution' => 'AI Visibility',
            'opportunity_intelligence' => 'Opportunity Intelligence',
            'competitive_intelligence' => 'Competitive Intelligence',
            'autonomous_marketing' => 'Autonomous Marketing',
            'governance_security' => 'Governance & Security',
            'integrations' => 'Integrations',
            'ai_search_geo' => 'AI Search & GEO',
            'platform_overview' => 'Platform Overview',
            'how_it_works' => 'How It Works',
            'blog' => 'Blog',
            'pricing' => 'Pricing',
        ];
    @endphp
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
    <script type="application/ld+json">{!! json_encode($softwareSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
</head>
<body class="pl-marketing-v2 bg-background text-textSecondary antialiased">
@include('public.partials.analytics-body')
@include('public.partials.nav')

<main class="bg-background">
    <section class="pl-public-hero">
        <div class="mx-auto grid max-w-6xl gap-10 px-4 py-16 sm:px-6 md:py-24 lg:grid-cols-[minmax(0,1fr)_minmax(340px,0.72fr)] lg:items-end">
            <div>
                <div class="pl-public-hero-label">
                    <i data-lucide="workflow" class="h-3.5 w-3.5"></i>
                    {{ $copy['eyebrow'] }}
                </div>
                <h1 class="mt-5 max-w-4xl pl-public-heading pl-public-heading-hero">{{ $copy['h1'] }}</h1>
                <p class="mt-5 max-w-3xl text-base leading-8 text-textPrimary md:text-lg">{{ $copy['intro'] }}</p>
                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <a href="{{ $links['contact'] }}" class="pl-public-primary-button">{{ $copy['primary_cta'] }}</a>
                    <a href="{{ $links['ai_visibility_solution'] }}" class="pl-public-secondary-button">{{ $copy['secondary_cta'] }}</a>
                </div>
            </div>

            <div class="pl-public-card p-5">
                <p class="pl-public-eyebrow">Closed loop</p>
                <div class="mt-5 grid gap-3">
                    @foreach ($copy['hero_stats'] as $index => $stat)
                        <div class="rounded-md border border-border bg-[#fbfaf7] p-4">
                            <div class="flex items-center gap-3">
                                <span class="flex h-8 w-8 flex-none items-center justify-center rounded-md bg-publicPrimary/8 text-xs font-semibold text-publicPrimary">{{ $index + 1 }}</span>
                                <p class="font-semibold text-textPrimary">{{ $stat[0] }}</p>
                            </div>
                            <p class="mt-2 text-sm leading-6">{{ $stat[1] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section class="bg-white">
        <div class="mx-auto grid max-w-6xl gap-10 px-4 py-16 sm:px-6 md:py-20 lg:grid-cols-[0.9fr_1.1fr]">
            <div>
                <p class="pl-public-eyebrow">Definition</p>
                <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ $copy['definition']['title'] }}</h2>
                <p class="mt-4 text-base leading-8">{{ $copy['definition']['body'] }}</p>
                <p class="mt-4 text-base leading-8 text-textPrimary">{{ $copy['definition']['distinction'] }}</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ($copy['definition']['items'] as $item)
                    <div class="pl-public-card-compact pl-public-canvas p-5">
                        <x-public.icon name="circle-slash" size="sm" class="text-publicPrimary" />
                        <p class="mt-4 font-semibold text-textPrimary">{{ $item }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="pl-public-warm">
        <div class="mx-auto grid max-w-6xl gap-10 px-4 py-16 sm:px-6 md:py-20 lg:grid-cols-[0.82fr_1.18fr] lg:items-start">
            <div>
                <p class="pl-public-eyebrow">Marketing automation</p>
                <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ $copy['automation']['title'] }}</h2>
                <p class="mt-4 text-base leading-8">{{ $copy['automation']['text'] }}</p>
            </div>
            <div class="grid gap-4">
                @foreach ($copy['automation']['points'] as $point)
                    <div class="flex gap-3 pl-public-card-compact p-5">
                        <x-public.icon name="check" size="xs" class="mt-1 flex-none text-publicPrimary" />
                        <p class="text-sm leading-7 text-textPrimary">{{ $point }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="bg-white">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="max-w-3xl">
                <p class="pl-public-eyebrow">Intelligence Loop</p>
                <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ $copy['loop_title'] }}</h2>
                <p class="mt-4 text-base leading-8">{{ $copy['loop_text'] }}</p>
            </div>
            <div class="mt-10 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                @foreach ($copy['loop_steps'] as $index => $step)
                    <div class="pl-public-card-compact p-4">
                        <span class="flex h-8 w-8 items-center justify-center rounded-md bg-publicPrimary text-xs font-semibold text-white">{{ $index + 1 }}</span>
                        <p class="mt-4 text-sm font-semibold leading-6 text-textPrimary">{{ $step }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="pl-public-warm">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="max-w-3xl">
                <p class="pl-public-eyebrow">Argusly</p>
                <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ $copy['capabilities_title'] }}</h2>
            </div>
            <div class="mt-10 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($copy['capabilities'] as $capability)
                    <article class="pl-public-card-compact p-5">
                        <h3 class="pl-public-heading pl-public-heading-card">{{ $capability[0] }}</h3>
                        <p class="mt-3 text-sm leading-7">{{ $capability[1] }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section class="bg-white">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="max-w-3xl">
                <p class="pl-public-eyebrow">Comparison</p>
                <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ $copy['comparison_title'] }}</h2>
            </div>
            <div class="mt-10 overflow-hidden rounded-md border border-border bg-white">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[860px] text-left text-sm">
                        <thead>
                            <tr>
                                @foreach ($copy['comparison_columns'] as $column)
                                    <th class="border-b border-border bg-[#fbfaf7] px-4 py-4 font-semibold text-textPrimary">{{ $column }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($copy['comparison_rows'] as $row)
                                <tr class="align-top">
                                    @foreach ($row as $cellIndex => $cell)
                                        <td class="border-b border-border px-4 py-4 leading-7 {{ $cellIndex === 0 ? 'font-semibold text-textPrimary' : 'text-textSecondary' }}">{{ $cell }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <section class="pl-public-warm">
        <div class="mx-auto grid max-w-6xl gap-10 px-4 py-16 sm:px-6 md:py-20 lg:grid-cols-[0.9fr_1.1fr]">
            <div>
                <p class="pl-public-eyebrow">Implementation</p>
                <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ $copy['implementation_title'] }}</h2>
                <p class="mt-4 text-base leading-8">{{ $copy['implementation_text'] }}</p>
            </div>
            <div>
                <p class="pl-public-eyebrow">{{ $copy['links_title'] }}</p>
                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                    @foreach ($linkCards as $key => $label)
                        @continue(! isset($links[$key]))
                        <a href="{{ $links[$key] }}" class="rounded-md border border-border bg-white p-4 text-sm font-semibold text-textPrimary transition-colors hover:border-publicPrimary/30 hover:text-publicPrimary">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section class="bg-white">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="pl-public-cta-panel pl-public-cta-panel--split p-8 md:p-10">
                <div class="grid gap-8 lg:grid-cols-[1fr_auto] lg:items-center">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.14em] text-white/60">Agentic Marketing Operating System</p>
                        <h2 class="mt-3 max-w-3xl pl-public-heading pl-public-heading-h2 text-white">{{ $copy['cta_title'] }}</h2>
                        <p class="mt-4 max-w-2xl text-sm leading-7 text-white/76">{{ $copy['cta_text'] }}</p>
                    </div>
                    <div class="flex flex-col gap-3 sm:flex-row lg:flex-col">
                        <a href="{{ $links['contact'] }}" class="pl-public-cta-primary">{{ $copy['primary_cta'] }}</a>
                        <a href="{{ $links['ai_visibility_solution'] }}" class="pl-public-cta-secondary">{{ $copy['secondary_cta'] }}</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

@include('public.partials.footer')

</body>
</html>
