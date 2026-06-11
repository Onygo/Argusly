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
    @include('public.partials.argusly-tracking', ['canonicalUrl' => $canonicalUrl ?? null])
    @include('public.partials.analytics')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-background text-textSecondary antialiased">
@include('public.partials.analytics-body')
@include('public.partials.nav')

<main class="bg-background">
    <section class="pl-public-hero">
        <div class="mx-auto max-w-6xl px-4 py-16 sm:px-6 md:py-20">
            <h1 class="pl-public-heading pl-public-heading-h1">{{ __('public.blog.unavailable_title') }}</h1>
            <p class="mt-3 max-w-2xl text-sm text-textSecondary md:text-base">{{ __('public.blog.unavailable_text') }}</p>
            <div class="mt-8 flex gap-3">
                <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.blog.index') }}" class="pl-public-secondary-button">{{ __('public.blog.back_to_blog') }}</a>
                <a href="{{ \App\Support\LocalizedMarketingUrl::route('landing') }}" class="pl-public-primary-button">{{ __('public.blog.back_home') }}</a>
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
