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
        'ogType' => $ogType ?? 'article',
        'ogTitle' => $ogTitle ?? null,
        'ogDescription' => $ogDescription ?? null,
        'twitterTitle' => $twitterTitle ?? null,
        'twitterDescription' => $twitterDescription ?? null,
        'ogImage' => $ogImage ?? null,
        'robotsIndex' => $robotsIndex ?? true,
        'robotsFollow' => $robotsFollow ?? true,
    ])
    @include('partials.brand-meta')
    @include('public.partials.argusly-tracking', ['post' => $post, 'canonicalUrl' => $canonicalUrl ?? null])
    @include('public.partials.analytics')
    @vite(['resources/css/app.css', 'resources/js/public.js'])
    <script defer src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-background text-textSecondary antialiased">
@include('public.partials.analytics-body')
@include('public.partials.nav')
@php
    $showPricingCta = ! (bool) config('argusly.launch.soft_launch_mode', false)
        && (bool) config('argusly.launch.public_pricing_enabled', true);
    $secondaryCtaHref = $showPricingCta
        ? \App\Support\LocalizedMarketingUrl::route('pricing')
        : \App\Support\LocalizedMarketingUrl::route('public.early-access.show', ['intent' => 'early_access']);
    $secondaryCtaLabel = $showPricingCta ? __('public.blog.cta_pricing') : __('public.nav.early_access');
    $articleText = \Illuminate\Support\Str::lower(strip_tags(($post['title'] ?? '') . ' ' . ($post['content_html'] ?? '')));
    $showAutomotiveIndustryLink = \Illuminate\Support\Str::contains($articleText, [
        'automotive',
        'dealer',
        'dealers',
        'importer',
        'importeurs',
        'lease',
        'leasing',
        'fleet',
        'fleetmanagement',
        'mobiliteit',
        'mobility',
    ]);
@endphp

<main class="bg-background">
    <section class="pl-public-hero">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <div class="max-w-4xl">
                <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.blog.index') }}" class="inline-flex items-center gap-1 text-sm text-textSecondary hover:text-textPrimary">
                    <i data-lucide="arrow-left" class="h-4 w-4"></i>
                    {{ __('public.blog.back_to_blog') }}
                </a>
                <h1 class="mt-4 pl-public-heading pl-public-heading-h1">{{ $post['title'] ?? __('public.blog.meta_title') }}</h1>
                <p class="mt-3 text-sm text-textSecondary">
                    {{ $post['published_date'] ?? '' }}
                    @if(($post['reading_time'] ?? 0) > 0)
                        · {{ $post['reading_time'] }} {{ __('public.blog.min_read') }}
                    @endif
                    @if(($post['author'] ?? '') !== '')
                        · {{ $post['author'] }}
                    @endif
                </p>
            </div>
        </div>
    </section>

    <section class="bg-white">
        <div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 md:py-16">
            @if(($post['featured_image'] ?? '') !== '')
                <div class="mb-8 overflow-hidden rounded-md border border-border">
                    <img
                        src="{{ $post['featured_image'] }}"
                        alt="{{ $post['featured_image_alt'] ?? $post['title'] ?? __('public.blog.meta_title') }}"
                        class="h-auto w-full object-cover"
                        @if(!empty($post['featured_image_width'])) width="{{ $post['featured_image_width'] }}" @endif
                        @if(!empty($post['featured_image_height'])) height="{{ $post['featured_image_height'] }}" @endif
                        loading="eager"
                        fetchpriority="high"
                    >
                </div>
            @endif

            <article class="bg-white p-6">
                <div class="space-y-5 text-base leading-7 text-textSecondary [&_a]:text-link [&_a]:underline [&_h2]:mt-8 [&_h2]:text-2xl [&_h2]:font-semibold [&_h2]:text-textPrimary [&_h3]:mt-6 [&_h3]:text-xl [&_h3]:font-semibold [&_h3]:text-textPrimary [&_li]:ml-5 [&_li]:list-disc [&_p]:text-textSecondary">
                    {!! $post['content_html'] ?? '' !!}
                </div>

                <div class="mt-10 border-t border-border pt-5">
                    <p class="inline-flex items-center gap-2 rounded-full border border-publicPrimary/15 bg-[#f8fafc] px-3 py-1 text-xs text-textSecondary">
                        <x-public.icon name="sparkles" size="xs" />
                        {{ __('public.blog.generated_badge') }}
                    </p>

                    <div class="mt-5 flex flex-wrap gap-3">
                        <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.product.overview') }}" class="pl-public-secondary-button">{{ __('public.blog.cta_product') }}</a>
                        <a href="{{ $secondaryCtaHref }}" class="pl-public-primary-button">{{ $secondaryCtaLabel }}</a>
                        @if ($showAutomotiveIndustryLink)
                            <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.markets.automotive') }}" class="pl-public-secondary-button">Automotive AI Visibility</a>
                        @endif
                    </div>
                </div>
            </article>
        </div>
    </section>
</main>

@include('public.partials.footer')

@if (! empty($blogPostingSchema))
<script type="application/ld+json">@json($blogPostingSchema, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)</script>
@endif
@if (! empty($breadcrumbSchema))
<script type="application/ld+json">@json($breadcrumbSchema, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)</script>
@endif
@if (! empty($faqSchema))
<script type="application/ld+json">@json($faqSchema, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)</script>
@endif

</body>
</html>
