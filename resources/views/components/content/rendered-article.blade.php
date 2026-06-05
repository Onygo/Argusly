@props([
    'content' => null,
    'html' => null,
    'compact' => false,
])

@php
    $renderer = app(\App\Services\Content\ContentRenderer::class);

    if ($html instanceof \Illuminate\Support\HtmlString) {
        $rendered = $html;
    } elseif ($html !== null) {
        $rendered = $renderer->sanitizeHtmlFragment((string) $html);
    } else {
        $rendered = $renderer->renderToHtml(is_string($content) ? $content : null);
    }
@endphp

<div {{ $attributes->class(['pl-content-prose', 'pl-content-prose-compact' => $compact]) }}>
    {!! $rendered !!}
</div>
