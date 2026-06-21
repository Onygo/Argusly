<!doctype html>
<html lang="{{ $publicLang ?? app()->getLocale() }}" class="scroll-smooth">
<head>
    @php
        $faqIntelligence = app(\App\Services\Faq\FaqIntelligenceRenderer::class)
            ->forPage('resource', (string) ($page->key ?? $translation->slug ?? request()->path()), app()->getLocale());
        $faqSchema = null;

        if ($faqIntelligence['items']->isEmpty() && ! empty($content['faq'] ?? [])) {
            $faqSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => collect((array) ($content['faq'] ?? []))
                    ->filter(fn ($item) => trim((string) ($item['question'] ?? '')) !== '' && trim((string) ($item['answer'] ?? '')) !== '')
                    ->map(fn ($item) => [
                        '@type' => 'Question',
                        'name' => (string) $item['question'],
                        'acceptedAnswer' => [
                            '@type' => 'Answer',
                            'text' => (string) $item['answer'],
                        ],
                    ])
                    ->values()
                    ->all(),
            ];
        }
    @endphp
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    @include('public.partials.seo-head', [
        'metaTitle' => $metaTitle,
        'metaDescription' => $metaDescription,
        'canonicalUrl' => $canonicalUrl,
        'hreflangUrls' => $hreflangUrls ?? [],
        'ogType' => 'article',
    ])
    @include('partials.brand-meta')
    @include('public.partials.argusly-tracking', ['canonicalUrl' => $canonicalUrl ?? null])
    @include('public.partials.analytics')
    @vite(['resources/css/app.css', 'resources/js/public.js'])
    <script defer src="https://unpkg.com/lucide@latest"></script>
    @if ($faqSchema !== null)
        <script type="application/ld+json">{!! json_encode($faqSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endif
</head>
<body class="bg-background text-textSecondary antialiased">
@include('public.partials.analytics-body')
@include('public.partials.nav')

<main class="bg-background">
    <section class="pl-public-hero">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <nav class="mb-6 flex flex-wrap items-center gap-2 text-xs text-textMuted">
                @foreach ($breadcrumbs as $index => $crumb)
                    @if (! empty($crumb['url']))
                        <a href="{{ $crumb['url'] }}" class="hover:text-textPrimary">{{ $crumb['label'] }}</a>
                    @else
                        <span>{{ $crumb['label'] }}</span>
                    @endif
                    @if ($index < count($breadcrumbs) - 1)
                        <span>/</span>
                    @endif
                @endforeach
            </nav>

            @if (($content['eyebrow'] ?? '') !== '')
                <div class="pl-public-hero-label">
                    {{ $content['eyebrow'] }}
                </div>
            @endif

            <h1 class="mt-5 pl-public-heading pl-public-heading-hero">{{ $translation->title }}</h1>
            @if (($content['subheadline'] ?? '') !== '')
                <p class="mt-4 max-w-3xl text-lg font-medium leading-8 text-textPrimary md:text-xl">{{ $content['subheadline'] }}</p>
            @endif
            <p class="mt-4 max-w-3xl text-sm leading-7 text-textSecondary md:text-base">{{ $content['intro'] ?? '' }}</p>
            @if (($content['hero_primary_route'] ?? '') !== '' || ($content['hero_secondary_route'] ?? '') !== '')
                <div class="mt-8 flex flex-wrap gap-3">
                    @if (($content['hero_primary_route'] ?? '') !== '')
                        <a href="{{ \App\Support\LocalizedMarketingUrl::route($content['hero_primary_route'], (array) ($content['hero_primary_params'] ?? [])) }}" class="pl-public-primary-button">
                            {{ $content['hero_primary_label'] ?? __('public.nav.contact') }}
                        </a>
                    @endif
                    @if (($content['hero_secondary_route'] ?? '') !== '')
                        <a href="{{ \App\Support\LocalizedMarketingUrl::route($content['hero_secondary_route'], (array) ($content['hero_secondary_params'] ?? [])) }}" class="pl-public-secondary-button">
                            {{ $content['hero_secondary_label'] ?? __('public.nav.platform') }}
                        </a>
                    @endif
                </div>
            @endif
        </div>
    </section>

    <section class="bg-white">
        <div class="mx-auto grid max-w-6xl gap-8 px-4 py-12 sm:px-6 lg:grid-cols-[minmax(0,1fr)_320px]">
            <div class="space-y-8">
                @foreach ((array) ($content['sections'] ?? []) as $section)
                    <article class="rounded-md border border-border bg-surface p-6 md:p-8">
                        <h2 class="pl-public-heading pl-public-heading-h2">{{ $section['title'] ?? '' }}</h2>
                        @if (($section['intro'] ?? '') !== '')
                            <p class="mt-4 text-sm font-medium leading-7 text-textPrimary md:text-base">{{ $section['intro'] }}</p>
                        @endif
                        @foreach ((array) ($section['paragraphs'] ?? []) as $paragraph)
                            <p class="mt-4 text-sm leading-7 text-textSecondary md:text-base">{{ $paragraph }}</p>
                        @endforeach
                        @if (! empty($section['steps'] ?? []))
                            <ol class="mt-5 space-y-4">
                                @foreach ((array) $section['steps'] as $index => $step)
                                    <li class="flex items-start gap-4 pl-public-card px-4 py-4">
                                        <div class="flex h-8 w-8 flex-none items-center justify-center rounded-full bg-publicPrimary text-sm font-semibold text-white">
                                            {{ $index + 1 }}
                                        </div>
                                        <div>
                                            @if (($step['title'] ?? '') !== '')
                                                <h3 class="pl-public-heading pl-public-heading-card">{{ $step['title'] }}</h3>
                                            @endif
                                            @if (($step['text'] ?? '') !== '')
                                                <p class="mt-1 text-sm leading-7 text-textSecondary md:text-base">{{ $step['text'] }}</p>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                        @if (! empty($section['bullets'] ?? []))
                            <ul class="mt-5 space-y-3 text-sm text-textSecondary">
                                @foreach ((array) $section['bullets'] as $bullet)
                                    <li class="flex items-start gap-3">
                                        <x-public.icon name="check" size="xs" class="mt-0.5 flex-none" />
                                        <span>{{ $bullet }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if (! empty($section['table']['headers'] ?? []) && ! empty($section['table']['rows'] ?? []))
                            <div class="mt-6 overflow-hidden rounded-md border border-border">
                                <div class="overflow-x-auto">
                                    <table class="pl-responsive-table min-w-full bg-white text-left text-sm text-textSecondary">
                                        <thead>
                                        <tr>
                                            @foreach ((array) $section['table']['headers'] as $header)
                                                <th scope="col">{{ $header }}</th>
                                            @endforeach
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach ((array) $section['table']['rows'] as $row)
                                            <tr>
                                                @foreach ((array) $row as $columnIndex => $value)
                                                    <td data-label="{{ $section['table']['headers'][$columnIndex] ?? '' }}" @class(['pl-no-label' => $columnIndex === 0])>
                                                        {{ $value }}
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif
                        @if (! empty($section['cards'] ?? []))
                            <div class="mt-6 grid gap-4 md:grid-cols-2">
                                @foreach ((array) $section['cards'] as $card)
                                    <a href="{{ $card['url'] ?? '#' }}" class="block pl-public-card p-5 transition-colors hover:bg-surface">
                                        <h3 class="pl-public-heading pl-public-heading-card">{{ $card['title'] ?? '' }}</h3>
                                        @if (($card['description'] ?? '') !== '')
                                            <p class="mt-2 text-sm leading-7 text-textSecondary">{{ $card['description'] }}</p>
                                        @endif
                                    </a>
                                @endforeach
                            </div>
                        @endif
                        @if (! empty($section['qa_blocks'] ?? []))
                            <div class="mt-6 space-y-4">
                                @foreach ((array) $section['qa_blocks'] as $qa)
                                    <div class="pl-public-card p-5">
                                        <h3 class="pl-public-heading pl-public-heading-card">{{ $qa['question'] ?? '' }}</h3>
                                        @if (($qa['answer'] ?? '') !== '')
                                            <p class="mt-2 text-sm leading-7 text-textSecondary">{{ $qa['answer'] }}</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </article>
                @endforeach

                <x-public.faq-section
                    :items="$faqIntelligence['items']"
                    :schema="$faqIntelligence['schema']"
                    :locale="app()->getLocale()"
                    :heading="$content['faq_title'] ?? null"
                />

                @if ($faqIntelligence['items']->isEmpty() && ! empty($content['faq'] ?? []))
                    <article class="rounded-md border border-border bg-surface p-6 md:p-8">
                        <h2 class="pl-public-heading pl-public-heading-h2">{{ $content['faq_title'] ?? 'FAQ' }}</h2>
                        <div class="mt-6 space-y-4">
                            @foreach ((array) ($content['faq'] ?? []) as $item)
                                <div class="pl-public-card p-5">
                                    <h3 class="pl-public-heading pl-public-heading-card">{{ $item['question'] ?? '' }}</h3>
                                    @if (($item['answer'] ?? '') !== '')
                                        <p class="mt-2 text-sm leading-7 text-textSecondary">{{ $item['answer'] }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </article>
                @endif
            </div>

            <aside class="space-y-6">
                @if ($relatedTopics !== [])
                    <section class="pl-public-card-soft p-6">
                        <h2 class="pl-public-eyebrow">{{ __('public.marketing_topics.related_topics') }}</h2>
                        <div class="mt-4 space-y-3">
                            @foreach ($relatedTopics as $topic)
                                <a href="{{ $topic['url'] }}" class="block pl-public-card px-4 py-3 text-sm font-medium text-textPrimary transition-colors hover:bg-surface">
                                    {{ $topic['title'] }}
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if ($platformLinks !== [])
                    <section class="rounded-md border border-border bg-white p-6">
                        <h2 class="pl-public-eyebrow">{{ __('public.marketing_topics.platform_links') }}</h2>
                        <div class="mt-4 space-y-3">
                            @foreach ($platformLinks as $link)
                                <a href="{{ $link['url'] }}" class="block text-sm font-medium text-publicPrimary hover:text-publicPrimaryHover">{{ $link['label'] }}</a>
                            @endforeach
                        </div>
                    </section>
                @endif

                <section class="pl-public-cta-panel pl-public-cta-panel--split p-6">
                    @if (($cta['eyebrow'] ?? '') !== '')
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-white/70">{{ $cta['eyebrow'] }}</p>
                    @endif
                    <h2 class="mt-3 pl-public-heading pl-public-heading-h2 text-white">{{ $cta['title'] ?? '' }}</h2>
                    <p class="mt-3 text-sm leading-7 text-white/80">{{ $cta['text'] ?? '' }}</p>
                    <div class="mt-5 flex flex-col gap-3">
                        @if (($cta['primary_route'] ?? '') !== '')
                            <a href="{{ \App\Support\LocalizedMarketingUrl::route($cta['primary_route'], (array) ($cta['primary_params'] ?? [])) }}" class="pl-public-cta-primary">
                                {{ $cta['primary_label'] ?? __('public.nav.contact') }}
                            </a>
                        @endif
                        @if (($cta['secondary_route'] ?? '') !== '')
                            <a href="{{ \App\Support\LocalizedMarketingUrl::route($cta['secondary_route'], (array) ($cta['secondary_params'] ?? [])) }}" class="pl-public-cta-secondary">
                                {{ $cta['secondary_label'] ?? __('public.nav.platform') }}
                            </a>
                        @endif
                    </div>
                </section>
            </aside>
        </div>
    </section>
</main>

@include('public.partials.footer')

</body>
</html>
