@extends('layouts.app', ['title' => 'Markdown preview'])

@section('pageHeader')
    <x-page-header title="Markdown preview">
        <x-slot:description>Preview rendered markdown content before publishing or review.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-2xl font-semibold tracking-tight text-textPrimary">Markdown preview</h2>
            <p class="mt-1 text-sm text-textSecondary">
                {{ $content->title }} · Locale {{ $resolvedLocale }} · Source {{ $preview['source'] }}
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('app.content.show', $content) }}" class="rounded border border-border px-3 py-2 text-sm">Back to content</a>
            <a href="{{ route('app.content.markdown-preview', ['content' => $content, 'locale' => $resolvedLocale]) }}" class="rounded border border-border px-3 py-2 text-sm">Refresh preview</a>
            <a href="#article-preview" class="rounded border border-border px-3 py-2 text-sm">Jump to article preview</a>
        </div>
    </div>

    <div class="mb-4 rounded-lg border border-border bg-surface p-4" id="article-preview">
        <div class="mb-3">
            <h2 class="text-sm font-semibold text-textPrimary">Rendered article preview</h2>
            <p class="text-xs text-textSecondary">Preview of the article HTML with Answer Blocks placed as they will render publicly.</p>
        </div>
        <article class="rounded border border-border bg-white p-6 text-base leading-7 text-textSecondary [&_a]:text-link [&_a]:underline [&_h2]:mt-8 [&_h2]:text-2xl [&_h2]:font-semibold [&_h2]:text-textPrimary [&_h3]:mt-6 [&_h3]:text-xl [&_h3]:font-semibold [&_h3]:text-textPrimary [&_li]:ml-5 [&_li]:list-disc [&_p]:text-textSecondary">
            {!! $articleHtml !!}
        </article>
    </div>

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_18rem]">
        <div class="rounded-lg border border-border bg-surface p-4">
            <div class="mb-3 flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-textPrimary">Canonical Markdown</h2>
                    <p class="text-xs text-textSecondary">Deterministic preview generated from the current canonical content state.</p>
                </div>
                <span class="rounded border border-border px-2 py-1 text-xs text-textSecondary">
                    {{ $artifact?->markdown_status ?? 'preview_only' }}
                </span>
            </div>
            <pre class="overflow-x-auto whitespace-pre-wrap rounded border border-border bg-background p-4 text-xs leading-6 text-textPrimary">{{ $preview['rendered_markdown'] }}</pre>
        </div>

        <div class="space-y-4">
            <div class="rounded-lg border border-border bg-surface p-4">
                <h2 class="text-sm font-semibold text-textPrimary">Artifact</h2>
                <dl class="mt-3 space-y-2 text-xs text-textSecondary">
                    <div>
                        <dt class="font-medium text-textPrimary">Stored version</dt>
                        <dd>{{ $artifact?->markdown_version ?? 'n/a' }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-textPrimary">Stored checksum</dt>
                        <dd class="break-all">{{ $artifact?->markdown_checksum ?? 'n/a' }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-textPrimary">Generated at</dt>
                        <dd>{{ optional($artifact?->markdown_generated_at)->format('Y-m-d H:i:s') ?? 'n/a' }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-textPrimary">Preview excerpt</dt>
                        <dd>{{ $preview['excerpt'] ?? 'n/a' }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-lg border border-border bg-surface p-4">
                <h2 class="text-sm font-semibold text-textPrimary">Rendered HTML snapshot</h2>
                <div class="mt-3 rounded border border-border bg-background p-3 text-xs text-textSecondary">
                    <code class="break-all">{{ $preview['rendered_html'] }}</code>
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-4">
                <h2 class="text-sm font-semibold text-textPrimary">FAQ schema</h2>
                <div class="mt-3 rounded border border-border bg-background p-3 text-xs text-textSecondary">
                    <code class="break-all">{{ $faqSchema ? json_encode($faqSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : 'disabled' }}</code>
                </div>
            </div>
        </div>
    </div>
@endsection
