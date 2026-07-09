@extends('layouts.app', ['title' => 'API Downloads'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>API Downloads</x-slot:title>
        <x-slot:description>Download API specification files for use with external tools.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    @php($redditUrl = trim((string) config('argusly.community.reddit_url', '')))
    <div class="space-y-6">
        <header class="space-y-2">
            <div class="flex items-center gap-2">
                <a href="{{ route('app.developer.docs.index') }}" class="text-textSecondary hover:text-textPrimary">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </a>
            </div>
        </header>

        {{-- Statistics --}}
        <div class="grid gap-4 sm:grid-cols-4">
            <div class="rounded-lg border border-border bg-background p-4">
                <p class="text-xs uppercase tracking-wide text-textSecondary">Endpoints</p>
                <p class="mt-1 text-2xl font-semibold text-textPrimary">{{ $statistics['endpoints'] ?? 0 }}</p>
            </div>
            <div class="rounded-lg border border-border bg-background p-4">
                <p class="text-xs uppercase tracking-wide text-textSecondary">Paths</p>
                <p class="mt-1 text-2xl font-semibold text-textPrimary">{{ $statistics['paths'] ?? 0 }}</p>
            </div>
            <div class="rounded-lg border border-border bg-background p-4">
                <p class="text-xs uppercase tracking-wide text-textSecondary">Schemas</p>
                <p class="mt-1 text-2xl font-semibold text-textPrimary">{{ $statistics['schemas'] ?? 0 }}</p>
            </div>
            <div class="rounded-lg border border-border bg-background p-4">
                <p class="text-xs uppercase tracking-wide text-textSecondary">Tags</p>
                <p class="mt-1 text-2xl font-semibold text-textPrimary">{{ $statistics['tags'] ?? 0 }}</p>
            </div>
        </div>

        {{-- Download Cards --}}
        <div class="space-y-4">
            @foreach ($files as $file)
                <div class="rounded-lg border border-border bg-background p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="font-semibold text-textPrimary">{{ $file['name'] }}</h3>
                                <span class="rounded border border-border px-1.5 py-0.5 text-xs text-textSecondary">{{ $file['format'] }}</span>
                            </div>
                            <p class="mt-1 text-sm text-textSecondary">{{ $file['description'] }}</p>
                            <p class="mt-2 font-mono text-xs text-textTertiary">{{ $file['filename'] }}</p>
                        </div>
                        <div>
                            @if ($file['exists'])
                                <a href="{{ route($file['route']) }}" class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse hover:bg-primary/90">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                    Download
                                </a>
                            @else
                                <span class="inline-flex items-center gap-2 rounded-md border border-border bg-surfaceSubtle px-4 py-2 text-sm text-textSecondary">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                    Not generated
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Generation Instructions --}}
        <div class="rounded-lg border border-border bg-background p-4">
            <h3 class="font-semibold text-textPrimary">Generating Documentation</h3>
            <p class="mt-2 text-sm text-textSecondary">Run the following Artisan commands to generate or update the documentation files:</p>
            <div class="mt-3 space-y-2">
                <div class="rounded border border-border bg-surfaceSubtle p-3">
                    <p class="mb-1 text-xs text-textSecondary">Generate OpenAPI specification:</p>
                    <code class="text-sm text-textPrimary">php artisan argusly:generate-openapi</code>
                </div>
                <div class="rounded border border-border bg-surfaceSubtle p-3">
                    <p class="mb-1 text-xs text-textSecondary">Generate Postman collection:</p>
                    <code class="text-sm text-textPrimary">php artisan argusly:generate-postman</code>
                </div>
            </div>
        </div>

        {{-- Postman Import Instructions --}}
        <div class="rounded-lg border border-border bg-background p-4">
            <h3 class="font-semibold text-textPrimary">Importing into Postman</h3>
            <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-textSecondary">
                <li>Download both the collection and environment files</li>
                <li>Open Postman and click <strong>Import</strong></li>
                <li>Drag and drop or select both JSON files</li>
                <li>Select the Argusly environment in the top-right dropdown</li>
                <li>Edit the environment and set your <code class="rounded bg-surfaceSubtle px-1">workspace_api_key</code></li>
                <li>Start making API requests</li>
            </ol>
        </div>

        @if ($redditUrl !== '')
            <div class="rounded-lg border border-border bg-background p-4">
                <h3 class="font-semibold text-textPrimary">Questions or ideas?</h3>
                <p class="mt-1 text-sm text-textSecondary">Join the discussion on Reddit for API questions, product feedback, and feature ideas.</p>
                <a href="{{ $redditUrl }}" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                    <i data-lucide="message-circle" class="h-4 w-4"></i>
                    Reddit Community
                </a>
            </div>
        @endif
    </div>
@endsection
