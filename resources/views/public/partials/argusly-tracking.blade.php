{{--
    Automatic Argusly analytics tracking injection for first-party marketing pages.

    Usage in Blade templates:
    @include('public.partials.argusly-tracking')

    For blog posts with content context:
    @include('public.partials.argusly-tracking', ['post' => $post])

    Optional variables:
    - $post: array with content data (id, slug, locale, canonical_url)
    - $canonicalUrl: fallback canonical URL if not in $post
--}}
@php
    $trackingResolver = app(\App\Services\Analytics\ArguslyTrackingSiteResolver::class);
    $trackingConfig = $trackingResolver->getTrackingConfig();
    $shouldInject = $trackingConfig !== null && $trackingResolver->shouldInjectTracking();
@endphp
@if ($shouldInject)
<script>
    window.Argusly = window.Argusly || {};
    window.Argusly.siteKey = "{{ $trackingConfig['siteKey'] }}";
    window.Argusly.engagedAfterSeconds = {{ $trackingConfig['engagedAfterSeconds'] }};
    window.Argusly.readThroughScrollPercent = {{ $trackingConfig['readThroughScrollPercent'] }};
    window.Argusly.readThroughFallbackSeconds = {{ $trackingConfig['readThroughFallbackSeconds'] }};
@if (isset($post) && is_array($post) && !empty($post['id']))
    {{-- Content-level metadata for blog posts / articles --}}
    window.Argusly.contentId = "{{ $post['id'] }}";
    window.Argusly.locale = "{{ $post['locale'] ?? app()->getLocale() }}";
    window.Argusly.slug = "{{ $post['slug'] ?? '' }}";
    window.Argusly.canonicalUrl = "{{ $post['canonical_url'] ?? ($canonicalUrl ?? '') }}";
    window.Argusly.contentType = "article";
@else
    {{-- Page-level metadata for non-content pages --}}
    window.Argusly.locale = "{{ app()->getLocale() }}";
    window.Argusly.path = "{{ request()->path() }}";
@if (isset($canonicalUrl) && $canonicalUrl)
    window.Argusly.canonicalUrl = "{{ $canonicalUrl }}";
@endif
@endif
</script>
<script async src="{{ $trackingResolver->getTrackingScriptUrl() }}"></script>
@endif
