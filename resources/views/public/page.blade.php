<!doctype html>
<html lang="{{ $publicLang ?? app()->getLocale() }}" class="scroll-smooth">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    @include('public.partials.seo-head', [
        'metaTitle' => $metaTitle,
        'metaDescription' => $metaDescription,
        'canonicalUrl' => $canonicalUrl ?? request()->url(),
        'hreflangUrls' => $hreflangUrls ?? [],
        'ogType' => $ogType ?? 'website',
    ])
    @include('partials.brand-meta')
    @include('public.partials.argusly-tracking', ['canonicalUrl' => $canonicalUrl ?? request()->url()])
    @include('public.partials.analytics')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://unpkg.com/lucide@latest"></script>
    @if (($pageKey ?? '') === 'company.contact')
        <x-forms.recaptcha-script />
    @endif
</head>
<body class="bg-background text-textSecondary antialiased">
@include('public.partials.analytics-body')
@include('public.partials.nav')
@php
    $softLaunchMode = (bool) config('argusly.launch.soft_launch_mode', false);
    $pricingEnabled = (bool) config('argusly.launch.public_pricing_enabled', true);
    $showPricingCta = ! $softLaunchMode && $pricingEnabled;
    $recaptchaConfigured = app(\App\Services\Security\RecaptchaService::class)->isConfigured();
    $primaryCtaHref = $showPricingCta
        ? \App\Support\LocalizedMarketingUrl::route('pricing')
        : \App\Support\LocalizedMarketingUrl::route('public.early-access.show', ['intent' => 'early_access']);
    $primaryCtaLabel = $showPricingCta
        ? ($ctaPrimary ?? __('public.page.cta.primary'))
        : __('public.nav.early_access');
    $pageKey = (string) ($pageKey ?? '');
    $isProductOverviewPage = $pageKey === 'product.overview';
    $isProductPlatformPage = $pageKey === 'product.platform';
    $isAboutPage = $pageKey === 'company.about';
    $isContactPage = $pageKey === 'company.contact';
    $isRoadmapPage = $pageKey === 'company.roadmap';
@endphp

<main class="bg-background" @if($pageKey !== '') data-page="{{ str_replace('.', '-', $pageKey) }}" @endif>
    @if ($isProductOverviewPage)
        @include('public.partials.product.overview')
    @elseif ($isProductPlatformPage)
        @include('public.partials.product.platform')
    @elseif ($isAboutPage)
        @include('public.partials.company.about')
    @elseif ($isContactPage)
        @include('public.partials.company.contact')
    @elseif ($isRoadmapPage)
        @include('public.partials.company.roadmap')
    @else
        @include('public.partials.page-generic')
    @endif
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
