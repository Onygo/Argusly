{{--
    Automatic Argusly analytics tracking injection for first-party marketing pages.

    Usage in Blade templates:
    @include('public.partials.publishlayer-tracking')

    For blog posts with content context:
    @include('public.partials.publishlayer-tracking', ['post' => $post])

    Optional variables:
    - $post: array with content data (id, slug, locale, canonical_url)
    - $canonicalUrl: fallback canonical URL if not in $post
--}}
@php
    $trackingResolver = app(\App\Services\Analytics\PublishLayerTrackingSiteResolver::class);
    $trackingConfig = $trackingResolver->getTrackingConfig();
    $shouldInject = $trackingConfig !== null && $trackingResolver->shouldInjectTracking();
@endphp
@if ($shouldInject)
<script>
    window.PublishLayer = window.PublishLayer || {};
    window.PublishLayer.siteKey = "{{ $trackingConfig['siteKey'] }}";
    window.PublishLayer.engagedAfterSeconds = {{ $trackingConfig['engagedAfterSeconds'] }};
    window.PublishLayer.readThroughScrollPercent = {{ $trackingConfig['readThroughScrollPercent'] }};
    window.PublishLayer.readThroughFallbackSeconds = {{ $trackingConfig['readThroughFallbackSeconds'] }};
@if (isset($post) && is_array($post) && !empty($post['id']))
    {{-- Content-level metadata for blog posts / articles --}}
    window.PublishLayer.contentId = "{{ $post['id'] }}";
    window.PublishLayer.locale = "{{ $post['locale'] ?? app()->getLocale() }}";
    window.PublishLayer.slug = "{{ $post['slug'] ?? '' }}";
    window.PublishLayer.canonicalUrl = "{{ $post['canonical_url'] ?? ($canonicalUrl ?? '') }}";
    window.PublishLayer.contentType = "article";
@else
    {{-- Page-level metadata for non-content pages --}}
    window.PublishLayer.locale = "{{ app()->getLocale() }}";
    window.PublishLayer.path = "{{ request()->path() }}";
@if (isset($canonicalUrl) && $canonicalUrl)
    window.PublishLayer.canonicalUrl = "{{ $canonicalUrl }}";
@endif
@endif
</script>
<script async src="{{ $trackingResolver->getTrackingScriptUrl() }}"></script>
@endif
