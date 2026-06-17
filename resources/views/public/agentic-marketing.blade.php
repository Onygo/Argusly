<!doctype html>
<html lang="{{ $publicLang ?? app()->getLocale() }}" class="scroll-smooth">
<head>
    @php
        $faqSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => collect($faq)->map(fn (array $item): array => [
                '@type' => 'Question',
                'name' => $item['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']],
            ])->all(),
        ];
        $softwareSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => 'Argusly',
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'url' => $canonicalUrl,
            'description' => $metaDescription,
        ];
        $primaryCta = \App\Support\LocalizedMarketingUrl::route('public.early-access.show', ['intent' => 'agentic-marketing']);
        $contactCta = \App\Support\LocalizedMarketingUrl::route('public.company.contact', ['subject' => $copy['primary_cta']]) . '#contact-form';
    @endphp
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    @include('public.partials.seo-head', [
        'metaTitle' => $metaTitle,
        'metaDescription' => $metaDescription,
        'canonicalUrl' => $canonicalUrl,
        'hreflangUrls' => $hreflangUrls ?? [],
        'ogTitle' => $ogTitle,
        'ogDescription' => $ogDescription,
        'ogType' => 'website',
    ])
    @include('partials.brand-meta')
    @include('public.partials.argusly-tracking', ['canonicalUrl' => $canonicalUrl])
    @include('public.partials.analytics')
    @vite(['resources/css/app.css', 'resources/js/public.js'])
    <script defer src="https://unpkg.com/lucide@latest"></script>
    <script type="application/ld+json">{!! json_encode($faqSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    <script type="application/ld+json">{!! json_encode($softwareSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
</head>
<body class="bg-background text-textSecondary antialiased">
@include('public.partials.analytics-body')
@include('public.partials.nav')

<main class="bg-background">
    <section class="pl-public-hero">
        <div class="mx-auto grid max-w-6xl gap-10 px-4 py-16 sm:px-6 md:py-20 lg:grid-cols-[minmax(0,1fr)_minmax(360px,0.72fr)] lg:items-center">
            <div>
                <div class="pl-public-hero-label">
                    <i data-lucide="workflow" class="h-3.5 w-3.5"></i>
                    {{ $copy['badge'] }}
                </div>
                <h1 class="mt-5 pl-public-heading pl-public-heading-hero">{{ $copy['h1'] }}</h1>
                <p class="mt-5 max-w-3xl text-pretty text-base leading-8 text-textSecondary md:text-lg">{{ $copy['intro'] }}</p>
                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <a href="{{ $contactCta }}" class="pl-public-primary-button">{{ $copy['primary_cta'] }}</a>
                    <a href="#architecture" class="inline-flex items-center justify-center rounded-full border border-publicPrimary/18 bg-white px-6 py-3 text-sm font-semibold text-publicPrimary transition-colors hover:bg-surfaceMuted">{{ $copy['secondary_cta'] }}</a>
                </div>
                <div class="mt-8 grid gap-3 text-sm md:grid-cols-3">
                    @foreach ($copy['hero_cards'] as $card)
                        <div class="pl-public-card-compact p-4">
                            <p class="font-semibold text-textPrimary">{{ $card['title'] }}</p>
                            <p class="mt-1 leading-6">{{ $card['text'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="rounded-md border border-publicPrimary/12 bg-white p-4">
                <div class="rounded-md border border-border bg-[#fbfaf7] p-4">
                    <div class="flex items-center justify-between border-b border-border pb-3">
                        <span class="pl-public-eyebrow">{{ $copy['loop_title'] }}</span>
                        <span class="rounded-md bg-publicPrimary px-2.5 py-1 text-xs font-medium text-white">{{ $copy['loop_status'] }}</span>
                    </div>
                    <div class="mt-5 grid gap-3">
                        @foreach ($copy['loop_steps'] as $index => $step)
                            <div class="flex items-center gap-3 pl-public-card-compact p-3">
                                <span class="flex h-8 w-8 flex-none items-center justify-center rounded-md bg-publicPrimary/8 text-xs font-semibold text-publicPrimary">{{ $index + 1 }}</span>
                                <div>
                                    <p class="text-sm font-semibold text-textPrimary">{{ $step[0] }}</p>
                                    <p class="text-xs leading-5 text-textSecondary">{{ $step[1] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="sticky top-[61px] z-40 hidden border-b border-border bg-white/95 backdrop-blur lg:block">
        <nav class="mx-auto flex max-w-6xl items-center gap-6 px-4 py-3 text-sm text-textMuted sm:px-6">
            @foreach ($copy['section_nav'] as $item)
                <a href="{{ $item['href'] }}" class="hover:text-textPrimary">{{ $item['label'] }}</a>
            @endforeach
        </nav>
    </div>

    <section id="shift" class="bg-white">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="grid gap-10 lg:grid-cols-[0.82fr_1.18fr]">
                <div>
                    <p class="pl-public-eyebrow">{{ $copy['problem']['eyebrow'] }}</p>
                    <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ $copy['problem']['title'] }}</h2>
                    <p class="mt-4 text-base leading-8">{{ $copy['problem']['text'] }}</p>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($copy['problem']['cards'] as $card)
                        <article class="pl-public-card-compact pl-public-canvas p-5">
                            <h3 class="pl-public-heading pl-public-heading-card">{{ $card[0] }}</h3>
                            <p class="mt-2 text-sm leading-7">{{ $card[1] }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section class="pl-public-warm">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="mx-auto max-w-3xl text-center">
                <p class="pl-public-eyebrow">{{ $copy['what_is']['eyebrow'] }}</p>
                <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ $copy['what_is']['title'] }}</h2>
                <p class="mt-4 text-base leading-8">{{ $copy['what_is']['text'] }}</p>
            </div>
            <div class="mt-10 overflow-hidden pl-public-card">
                <div class="grid md:grid-cols-3">
                    @foreach ($copy['what_is']['columns'] as $column)
                        <div class="border-b border-border p-6 md:border-b-0 md:border-r last:md:border-r-0">
                            <h3 class="pl-public-heading pl-public-heading-h3">{{ $column[0] }}</h3>
                            <p class="mt-3 text-sm leading-7 text-textPrimary">{{ $column[1] }}</p>
                            <p class="mt-3 text-sm leading-7">{{ $column[2] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section class="bg-white">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="grid gap-10 lg:grid-cols-[0.9fr_1.1fr] lg:items-start">
                <div>
                    <p class="pl-public-eyebrow">{{ $copy['fit']['eyebrow'] }}</p>
                    <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ $copy['fit']['title'] }}</h2>
                    @foreach ($copy['fit']['paragraphs'] as $paragraph)
                        <p class="mt-4 text-base leading-8">{{ $paragraph }}</p>
                    @endforeach
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($copy['fit']['cards'] as $card)
                        <article class="rounded-md border border-border bg-surface p-5">
                            <h3 class="pl-public-heading pl-public-heading-card">{{ $card[0] }}</h3>
                            <p class="mt-2 text-sm leading-7">{{ $card[1] }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section id="architecture" class="border-y border-publicPrimary/10 bg-publicPrimary text-white">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="grid gap-10 lg:grid-cols-[0.7fr_1.3fr] lg:items-center">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.14em] text-white/60">{{ $copy['architecture']['eyebrow'] }}</p>
                    <h2 class="mt-3 pl-public-heading pl-public-heading-h2 text-white">{{ $copy['architecture']['title'] }}</h2>
                    <p class="mt-4 text-base leading-8 text-white/76">{{ $copy['architecture']['text'] }}</p>
                </div>
                <div class="rounded-md border border-white/12 bg-white/8 p-5">
                    <div class="grid gap-3 md:grid-cols-5">
                        @foreach ($copy['architecture']['steps'] as $step)
                            <div class="rounded-md border border-white/12 bg-white p-4 text-publicPrimary">
                                <p class="text-sm font-semibold">{{ $step[0] }}</p>
                                <p class="mt-2 text-xs leading-5 text-textSecondary">{{ $step[1] }}</p>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-5 grid gap-3 md:grid-cols-3">
                        @foreach ($copy['architecture']['panels'] as $panel)
                            <div class="rounded-md border border-white/12 bg-white/10 p-4">
                                <p class="text-sm font-semibold">{{ $panel[0] }}</p>
                                <p class="mt-2 text-xs leading-6 text-white/72">{{ $panel[1] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="bg-white">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="max-w-3xl">
                <p class="pl-public-eyebrow">Argusly</p>
                <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ $copy['features_title'] }}</h2>
            </div>
            <div class="mt-10 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($copy['features'] as $feature)
                    <article class="pl-public-card-compact pl-public-canvas p-5">
                        <h3 class="pl-public-heading pl-public-heading-card">{{ $feature[0] }}</h3>
                        <p class="mt-2 text-sm leading-7">{{ $feature[1] }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section id="visibility" class="pl-public-warm">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="grid gap-10 lg:grid-cols-2 lg:items-start">
                <div>
                    <p class="pl-public-eyebrow">{{ $copy['visibility']['eyebrow'] }}</p>
                    <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ $copy['visibility']['title'] }}</h2>
                    <p class="mt-4 text-base leading-8">{{ $copy['visibility']['text'] }}</p>
                    <div class="mt-6 pl-public-card-compact p-5">
                        <h3 class="pl-public-heading pl-public-heading-card">{{ $copy['visibility']['block_title'] }}</h3>
                        <p class="mt-3 text-sm leading-7">{{ $copy['visibility']['block'] }}</p>
                    </div>
                </div>
                <div class="pl-public-card p-5">
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        @foreach ($copy['visibility']['nodes'] as $node)
                            <div class="rounded-md border border-border bg-[#fbfaf7] p-4">
                                <p class="font-semibold text-textPrimary">{{ $node[0] }}</p>
                                <p class="mt-1 text-xs leading-5">{{ $node[1] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="lifecycle" class="bg-white">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="grid gap-10 lg:grid-cols-[1.1fr_0.9fr]">
                <div>
                    <p class="pl-public-eyebrow">{{ $copy['lifecycle']['eyebrow'] }}</p>
                    <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ $copy['lifecycle']['title'] }}</h2>
                    <p class="mt-4 text-base leading-8">{{ $copy['lifecycle']['text'] }}</p>
                    <div class="mt-8 grid gap-4 md:grid-cols-2">
                        @foreach ($copy['lifecycle']['cards'] as $item)
                            <div class="rounded-md border border-border bg-surface p-5">
                                <h3 class="pl-public-heading pl-public-heading-card">{{ $item[0] }}</h3>
                                <p class="mt-2 text-sm leading-7">{{ $item[1] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="pl-public-card-soft p-5">
                    <p class="pl-public-eyebrow">{{ $copy['lifecycle']['loop_title'] }}</p>
                    <ol class="mt-5 space-y-3">
                        @foreach ($copy['lifecycle']['loop'] as $index => $step)
                            <li class="flex gap-3 pl-public-card-compact p-3">
                                <span class="flex h-7 w-7 flex-none items-center justify-center rounded-md bg-publicPrimary text-xs font-semibold text-white">{{ $index + 1 }}</span>
                                <span class="text-sm font-medium text-textPrimary">{{ $step }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="pl-public-warm">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="grid gap-10 lg:grid-cols-[0.8fr_1.2fr]">
                <div>
                    <p class="pl-public-eyebrow">{{ $copy['future']['eyebrow'] }}</p>
                    <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ $copy['future']['title'] }}</h2>
                </div>
                <div class="space-y-5 text-base leading-8">
                    @foreach ($copy['future']['paragraphs'] as $paragraph)
                        <p>{{ $paragraph }}</p>
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
                        <p class="text-sm font-semibold uppercase tracking-[0.14em] text-white/60">{{ $copy['cta']['eyebrow'] }}</p>
                        <h2 class="mt-3 max-w-3xl pl-public-heading pl-public-heading-h2 text-white">{{ $copy['cta']['title'] }}</h2>
                        <p class="mt-4 max-w-2xl text-sm leading-7 text-white/76">{{ $copy['cta']['text'] }}</p>
                    </div>
                    <div class="flex flex-col gap-3 sm:flex-row lg:flex-col">
                        <a href="{{ $contactCta }}" class="pl-public-cta-primary">{{ $copy['cta']['primary'] }}</a>
                        <a href="{{ $primaryCta }}" class="pl-public-cta-secondary">{{ $copy['cta']['secondary'] }}</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="pl-public-warm">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="grid gap-8 lg:grid-cols-[0.82fr_1.18fr]">
                <div>
                    <p class="pl-public-eyebrow">{{ $copy['seo']['eyebrow'] }}</p>
                    <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ $copy['seo']['title'] }}</h2>
                </div>
                <div class="grid gap-4">
                    @foreach ($copy['seo']['blocks'] as $block)
                        <article class="pl-public-card-compact p-5">
                            <h3 class="pl-public-heading pl-public-heading-card">{{ $block[0] }}</h3>
                            <p class="mt-2 text-sm leading-7">{{ $block[1] }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section id="faq" class="bg-white">
        <div class="mx-auto max-w-4xl px-4 py-16 sm:px-6 md:py-20">
            <div class="text-center">
                <p class="pl-public-eyebrow">FAQ</p>
                <h2 class="mt-3 pl-public-heading pl-public-heading-h2">{{ $copy['faq_title'] }}</h2>
            </div>
            <div class="mt-10 space-y-4">
                @foreach ($faq as $item)
                    <article class="pl-public-card-compact pl-public-canvas p-5">
                        <h3 class="pl-public-heading pl-public-heading-card">{{ $item['question'] }}</h3>
                        <p class="mt-2 text-sm leading-7">{{ $item['answer'] }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

</main>

@include('public.partials.footer')

</body>
</html>
