@php
    $resolvedMetaTitle = \App\Support\SeoTitle::normalize($metaTitle ?? config('app.name', 'Argusly'));
    $resolvedOgTitle = \App\Support\SeoTitle::normalize($ogTitle ?? $resolvedMetaTitle);
    $resolvedTwitterTitle = \App\Support\SeoTitle::normalize($twitterTitle ?? $resolvedOgTitle);
    $resolvedSocialImage = app(\App\Support\SocialImageResolver::class)->resolve(
        $ogImage ?? $socialImage ?? null,
        $canonicalUrl ?? null,
        $socialImagePageType ?? $pageType ?? null
    );
@endphp
<title>{{ $resolvedMetaTitle }}</title>
<meta name="description" content="{{ $metaDescription ?? '' }}" />
@if (filled(config('services.google.search_console_verification')))
    <meta name="google-site-verification" content="{{ config('services.google.search_console_verification') }}" />
@endif
@if (isset($robotsIndex) || isset($robotsFollow))
    <meta name="robots" content="{{ ($robotsIndex ?? true) ? 'index' : 'noindex' }},{{ ($robotsFollow ?? true) ? 'follow' : 'nofollow' }}" />
@endif
@if (! empty($canonicalUrl))
    <link rel="canonical" href="{{ $canonicalUrl }}" />
    <meta property="og:url" content="{{ $canonicalUrl }}" />
@endif
@foreach ((array) ($hreflangUrls ?? []) as $hreflang => $href)
    @if(is_string($hreflang) && trim($hreflang) !== '' && is_string($href) && trim($href) !== '')
        <link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}" />
    @endif
@endforeach
<meta property="og:title" content="{{ $resolvedOgTitle }}" />
<meta property="og:description" content="{{ $ogDescription ?? $metaDescription }}" />
@if (! empty($ogType ?? null))
    <meta property="og:type" content="{{ $ogType }}" />
@endif
<meta property="og:image" content="{{ $resolvedSocialImage }}" />
<meta property="og:image:width" content="{{ (int) config('argusly_social.width', 1200) }}" />
<meta property="og:image:height" content="{{ (int) config('argusly_social.height', 630) }}" />
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="{{ $resolvedTwitterTitle }}" />
<meta name="twitter:description" content="{{ $twitterDescription ?? $ogDescription ?? $metaDescription }}" />
<meta name="twitter:image" content="{{ $resolvedSocialImage }}" />
<script type="application/ld+json">{!! json_encode([
    "\x40context" => 'https://schema.org',
    '@type' => 'Organization',
    'name' => 'Argusly',
    'url' => url('/'),
    'logo' => asset('images/argusly-logo.svg'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
<script type="application/ld+json">{!! json_encode([
    "\x40context" => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => 'Argusly',
    'url' => url('/'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@if (request()->routeIs('*.public.product.*') || request()->routeIs('*.pricing') || request()->routeIs('pricing'))
    <script type="application/ld+json">{!! json_encode([
        "\x40context" => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => 'Argusly',
        'applicationCategory' => 'BusinessApplication',
        'operatingSystem' => 'Web',
        'url' => $canonicalUrl ?? url('/'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endif
