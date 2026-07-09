<!doctype html>
<html lang="{{ $publicLang ?? app()->getLocale() }}" class="scroll-smooth">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    @php($resolvedMetaTitle = \App\Support\SeoTitle::normalize($metaTitle))
    <title>{{ $resolvedMetaTitle }}</title>
    <meta name="description" content="{{ $metaDescription }}" />
    @include('partials.brand-meta')
    @include('public.partials.argusly-tracking', ['canonicalUrl' => $canonicalUrl ?? null])
    @include('public.partials.analytics')
    <link rel="canonical" href="{{ $canonicalUrl }}" />
    <meta property="og:type" content="{{ $ogType ?? 'website' }}" />
    <meta property="og:title" content="{{ $resolvedMetaTitle }}" />
    <meta property="og:description" content="{{ $metaDescription }}" />
    <meta property="og:url" content="{{ $canonicalUrl }}" />
    <meta name="twitter:card" content="summary" />
    <meta name="twitter:title" content="{{ $resolvedMetaTitle }}" />
    <meta name="twitter:description" content="{{ $metaDescription }}" />
    @vite(['resources/css/app.css', 'resources/js/public.js'])
    <script defer src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="pl-marketing-v2 bg-background text-textSecondary antialiased">
@include('public.partials.analytics-body')
@include('public.partials.nav')
@php
    $langParam = ($publicLang ?? app()->getLocale()) === 'en' ? ['lang' => 'en'] : [];
    $isEarlyAccess = \App\Support\EarlyAccess::enabled();
    $ctaHref = $isEarlyAccess
        ? route('public.early-access.show', ['intent' => 'early_access'])
        : route('pricing');
@endphp

<main class="bg-background">
    {{-- Hero --}}
    <section class="pl-public-hero">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="max-w-3xl">
                <div class="mb-4 pl-public-hero-label">
                    <x-public.icon name="rocket" size="xs" />
                    <span>{{ __('public.product_updates.badge') }}</span>
                </div>
                <h1 class="pl-public-heading pl-public-heading-hero">{{ __('public.product_updates.title') }}</h1>
                <p class="mt-4 max-w-2xl text-sm leading-6 text-textSecondary md:text-base">
                    {{ __('public.product_updates.subtitle') }}
                </p>
            </div>
        </div>
    </section>

    {{-- Search and Updates --}}
    <section class="bg-background">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            {{-- Search and Filter --}}
            <div class="pl-public-card p-5 md:p-6">
                <form method="GET" action="{{ route('public.product_updates.index', $langParam) }}" class="grid gap-4 md:grid-cols-[1fr_auto]">
                    <div>
                        <label for="updates-search" class="mb-1.5 block text-sm font-medium text-textPrimary">{{ __('public.product_updates.search_label') }}</label>
                        <input
                            id="updates-search"
                            type="search"
                            name="q"
                            value="{{ $searchTerm }}"
                            class="w-full rounded-md border border-border bg-background px-4 py-2.5 text-sm focus:border-publicPrimary focus:outline-none focus:ring-1 focus:ring-publicPrimary"
                            placeholder="{{ __('public.product_updates.search_placeholder') }}"
                        />
                    </div>
                    <div class="flex items-end gap-2">
                        @if ($activeTag !== '')
                            <input type="hidden" name="tag" value="{{ $activeTag }}">
                        @endif
                        <button type="submit" class="rounded-full bg-publicPrimary px-4 py-2.5 text-sm font-medium text-white transition-colors hover:bg-publicPrimaryHover">
                            {{ __('public.product_updates.search') }}
                        </button>
                        <a href="{{ route('public.product_updates.index', $langParam) }}" class="rounded-full border border-border bg-white px-4 py-2.5 text-sm font-medium text-textPrimary transition-colors hover:bg-[#f8fafc]">
                            {{ __('public.product_updates.reset') }}
                        </a>
                    </div>
                </form>

                @if (!empty($availableTags))
                    <div class="mt-5 border-t border-border pt-5">
                        <div class="flex flex-wrap items-center gap-2">
                            <a href="{{ route('public.product_updates.index', array_merge($langParam, $searchTerm !== '' ? ['q' => $searchTerm] : [])) }}" class="rounded-md border px-3 py-1.5 text-xs font-medium transition-colors {{ $activeTag === '' ? 'border-publicPrimary bg-publicPrimary/5 text-publicPrimary' : 'border-border bg-white text-textSecondary hover:border-borderStrong hover:text-textPrimary' }}">
                                {{ __('public.product_updates.all_tags') }}
                            </a>
                            @foreach ($availableTags as $tag)
                                <a href="{{ route('public.product_updates.index', array_merge($langParam, ['tag' => $tag], $searchTerm !== '' ? ['q' => $searchTerm] : [])) }}" class="rounded-md border px-3 py-1.5 text-xs font-medium transition-colors {{ $activeTag === $tag ? 'border-publicPrimary bg-publicPrimary/5 text-publicPrimary' : 'border-border bg-white text-textSecondary hover:border-borderStrong hover:text-textPrimary' }}">
                                    {{ $tag }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            {{-- Updates List --}}
            <div class="mt-8 space-y-4">
                @forelse ($updates as $update)
                    <article id="update_{{ $update->id }}" class="pl-public-card p-6">
                        <div class="flex flex-wrap items-center gap-3 text-xs">
                            <time datetime="{{ optional($update->published_at)->toIso8601String() }}" class="inline-flex items-center gap-1.5 text-textMuted">
                                <i data-lucide="calendar" class="h-3.5 w-3.5"></i>
                                {{ optional($update->published_at)->format('Y-m-d') }}
                            </time>
                            @if ($update->version)
                                <span class="rounded-md border border-publicPrimary/15 bg-publicPrimary/5 px-2 py-0.5 font-medium text-publicPrimary">{{ $update->version }}</span>
                            @endif
                            <a href="#update_{{ $update->id }}" class="ml-auto text-textMuted hover:text-publicPrimary">#{{ $update->id }}</a>
                        </div>

                        <h2 class="mt-3 text-xl font-semibold text-textPrimary">{{ $update->title }}</h2>
                        <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $update->summary }}</p>

                        @if (!empty($update->tags))
                            <div class="mt-4 flex flex-wrap gap-1.5">
                                @foreach ((array) $update->tags as $tag)
                                    <a href="{{ route('public.product_updates.index', array_merge($langParam, ['tag' => $tag], $searchTerm !== '' ? ['q' => $searchTerm] : [])) }}" class="rounded-md border border-border px-2 py-0.5 text-xs text-textSecondary transition-colors hover:border-borderStrong hover:text-textPrimary">
                                        {{ $tag }}
                                    </a>
                                @endforeach
                            </div>
                        @endif

                        <div class="mt-5 border-t border-border pt-5 text-sm leading-6 text-textSecondary prose-sm prose-headings:mt-4 prose-headings:font-semibold prose-headings:text-textPrimary prose-p:mt-3 prose-ul:mt-3 prose-ul:list-disc prose-ul:pl-5 prose-ol:mt-3 prose-ol:list-decimal prose-ol:pl-5 prose-a:text-link prose-a:underline">
                            {!! $update->body_html !!}
                        </div>
                    </article>
                @empty
                    {{-- Empty State --}}
                    <div class="pl-public-card p-8 text-center md:p-12">
                        <div class="mx-auto mb-4 inline-flex h-16 w-16 items-center justify-center rounded-md bg-[#f8fafc]">
                            <i data-lucide="inbox" class="h-8 w-8 text-publicPrimary"></i>
                        </div>
                        <h2 class="pl-public-heading pl-public-heading-h3">{{ __('public.product_updates.empty_title') }}</h2>
                        <p class="mx-auto mt-2 max-w-md text-sm text-textSecondary">{{ __('public.product_updates.empty') }}</p>
                        <div class="mt-6 flex flex-col items-center justify-center gap-3 sm:flex-row">
                            <a href="{{ route('public.company.roadmap') }}" class="pl-public-primary-button">
                                <i data-lucide="map" class="h-4 w-4"></i>
                                {{ __('public.product_updates.view_roadmap') }}
                            </a>
                            <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.contact') }}" class="inline-flex items-center gap-2 rounded-full border border-border bg-white px-5 py-3 text-sm font-semibold text-textPrimary transition-colors hover:bg-[#f8fafc]">
                                <i data-lucide="message-circle" class="h-4 w-4"></i>
                                {{ __('public.nav.contact') }}
                            </a>
                        </div>
                    </div>
                @endforelse
            </div>

            @if ($updates->hasPages())
                <div class="mt-10">
                    {{ $updates->onEachSide(1)->links() }}
                </div>
            @endif
        </div>
    </section>

    {{-- CTA --}}
    <section class="pl-public-warm">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="pl-public-cta-panel">
                <h2 class="text-balance pl-public-heading pl-public-heading-h2 text-white">
                    {{ __('public.product_updates.cta_title') }}
                </h2>
                <p class="mx-auto mt-3 max-w-xl text-sm leading-relaxed text-white/76 md:text-base">
                    {{ __('public.product_updates.cta_text') }}
                </p>
                <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                    <a href="{{ $ctaHref }}" class="pl-public-cta-primary">
                        {{ $isEarlyAccess ? __('public.nav.early_access') : __('public.cta.primary') }}
                    </a>
                    <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.contact') }}" class="pl-public-cta-secondary">
                        {{ __('public.cta.secondary') }}
                    </a>
                </div>
            </div>
        </div>
    </section>
</main>

@include('public.partials.footer')

</body>
</html>
