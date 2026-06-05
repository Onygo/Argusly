@if (config('publishlayer.seo.enabled', true))
    @if (config('publishlayer.seo.canonical', true) && !empty($meta['canonical']))
        <link rel="canonical" href="{{ $meta['canonical'] }}">
    @endif
    @if (!empty($meta['description']))
        <meta name="description" content="{{ $meta['description'] }}">
    @endif
    @if (!empty($meta['robots']))
        <meta name="robots" content="{{ $meta['robots'] }}">
    @endif
    @foreach ($structuredData ?? [] as $schema)
        <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endforeach
@endif
