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
        'ogType' => $ogType ?? 'website',
    ])
    @include('partials.brand-meta')
    @include('public.partials.argusly-tracking', ['canonicalUrl' => $canonicalUrl ?? null])
    @include('public.partials.analytics')
    @vite(['resources/css/app.css', 'resources/js/public.js'])
    <script defer src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="pl-marketing-v2 bg-background text-textSecondary antialiased">
@include('public.partials.analytics-body')
@include('public.partials.nav')

<main class="bg-background">
    @include('public.legal.partials.hero', ['title' => $heroTitle, 'subtitle' => $heroSubtitle])

    @include('public.legal.partials.shell', ['items' => $legalSidebarItems, 'activeLegal' => $activeLegal])

    @if (($activeLegal ?? '') === 'security')
        <x-public.faq-section
            page-type="security"
            page-slug="legal.security"
            :locale="app()->getLocale()"
        />
    @endif
</main>

@include('public.partials.footer')

</body>
</html>
