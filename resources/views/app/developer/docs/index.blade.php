@extends('layouts.app', ['title' => 'API Reference'])

@section('content')
    <div class="space-y-6">
        <header class="space-y-2">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">API Reference</h1>
                    <p class="text-textSecondary">{{ $info['description'] ?? 'PublishLayer API documentation' }}</p>
                </div>
                <a href="{{ route('app.developer.docs.downloads') }}" class="inline-flex items-center gap-2 rounded-md border border-border bg-background px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    Downloads
                </a>
            </div>
        </header>

        @if (!$specExists)
            <div class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3">
                <p class="text-sm font-medium text-amber-900">OpenAPI specification not generated yet</p>
                <p class="mt-1 text-sm text-amber-800">Run <code class="rounded bg-amber-100 px-1 py-0.5">php artisan publishlayer:generate-openapi</code> to generate the API documentation.</p>
            </div>
        @else
            <div class="flex gap-6">
                {{-- Sidebar --}}
                <aside class="w-56 shrink-0">
                    <nav class="sticky top-4 space-y-1">
                        {{-- Authentication card --}}
                        <div class="mb-4 rounded-lg border border-border bg-background p-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Authentication</p>
                            <p class="mt-1 text-xs text-textPrimary">{{ $authInfo['header'] ?? 'Authorization' }}</p>
                            <code class="mt-1 block text-xs text-primary">{{ $authInfo['format'] ?? 'Bearer {api_key}' }}</code>
                        </div>

                        {{-- Base URL --}}
                        @if (!empty($servers))
                            <div class="mb-4 rounded-lg border border-border bg-background p-3">
                                <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Base URL</p>
                                <code class="mt-1 block break-all text-xs text-textPrimary">{{ $servers[0]['url'] ?? '' }}</code>
                            </div>
                        @endif

                        {{-- Tag navigation --}}
                        <p class="px-3 text-xs font-semibold uppercase tracking-wide text-textSecondary">Endpoints</p>
                        @foreach($tags as $tag)
                            <a href="{{ route('app.developer.docs.index', ['tag' => $tag['name']]) }}"
                               class="block rounded-md px-3 py-2 text-sm transition-colors {{ $activeTag === $tag['name'] ? 'bg-primary text-textInverse' : 'text-textSecondary hover:bg-surfaceSubtle hover:text-textPrimary' }}">
                                {{ $tag['name'] }}
                            </a>
                        @endforeach
                    </nav>
                </aside>

                {{-- Main Content --}}
                <main class="min-w-0 flex-1 space-y-6">
                    @if ($activeTag)
                        <div class="space-y-2">
                            <h2 class="text-xl font-semibold text-textPrimary">{{ $activeTag }}</h2>
                            @php
                                $tagDescription = collect($tags)->firstWhere('name', $activeTag)['description'] ?? '';
                            @endphp
                            @if ($tagDescription)
                                <p class="text-textSecondary">{{ $tagDescription }}</p>
                            @endif
                        </div>

                        <div class="space-y-4">
                            @forelse($endpoints as $endpoint)
                                @include('app.developer.docs.partials.endpoint-card', ['endpoint' => $endpoint])
                            @empty
                                <div class="rounded-lg border border-border bg-background p-4">
                                    <p class="text-sm text-textSecondary">No endpoints found for this tag.</p>
                                </div>
                            @endforelse
                        </div>
                    @else
                        {{-- Overview when no tag selected --}}
                        <div class="space-y-6">
                            <div class="grid gap-4 sm:grid-cols-3">
                                <div class="rounded-lg border border-border bg-background p-4">
                                    <p class="text-xs uppercase tracking-wide text-textSecondary">Total Endpoints</p>
                                    <p class="mt-1 text-2xl font-semibold text-textPrimary">{{ $statistics['endpoints'] ?? 0 }}</p>
                                </div>
                                <div class="rounded-lg border border-border bg-background p-4">
                                    <p class="text-xs uppercase tracking-wide text-textSecondary">Resource Types</p>
                                    <p class="mt-1 text-2xl font-semibold text-textPrimary">{{ $statistics['schemas'] ?? 0 }}</p>
                                </div>
                                <div class="rounded-lg border border-border bg-background p-4">
                                    <p class="text-xs uppercase tracking-wide text-textSecondary">API Version</p>
                                    <p class="mt-1 text-2xl font-semibold text-textPrimary">{{ $info['version'] ?? '1.0.0' }}</p>
                                </div>
                            </div>

                            <div class="rounded-lg border border-border bg-background p-4">
                                <h3 class="font-semibold text-textPrimary">Quick Start</h3>
                                <ol class="mt-3 space-y-2 text-sm text-textSecondary">
                                    <li>1. Create an API key in the <a href="{{ route('app.developer.api') }}" class="text-primary underline">Developer portal</a></li>
                                    <li>2. Include the key in requests: <code class="rounded bg-surfaceSubtle px-1 py-0.5">Authorization: Bearer YOUR_API_KEY</code></li>
                                    <li>3. Make requests to <code class="rounded bg-surfaceSubtle px-1 py-0.5">{{ $servers[0]['url'] ?? 'https://api.publishlayer.com/api/v1' }}</code></li>
                                </ol>
                            </div>

                            <div class="rounded-lg border border-border bg-background p-4">
                                <h3 class="font-semibold text-textPrimary">Select a category</h3>
                                <p class="mt-1 text-sm text-textSecondary">Choose a category from the sidebar to view endpoint documentation.</p>
                            </div>
                        </div>
                    @endif
                </main>
            </div>
        @endif
    </div>
@endsection
