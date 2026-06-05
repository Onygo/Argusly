@extends('layouts.app', ['title' => 'Search'])

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-textPrimary">Search</h1>
        <p class="mt-1 text-sm text-textSecondary">Results for "{{ $q !== '' ? $q : '...' }}"</p>
    </div>

    @if($q === '')
        <div class="rounded-lg border border-border bg-surface p-5 text-sm text-textSecondary">
            Enter a search term in the top navigation.
        </div>
    @else
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-textPrimary">Content ({{ $contents->count() }})</h2>
                <div class="mt-3 space-y-2">
                    @forelse($contents as $content)
                        <a href="{{ route('app.content.show', $content) }}" class="block rounded-md border border-border px-3 py-2 hover:bg-surfaceSubtle">
                            <p class="text-sm font-medium text-textPrimary">{{ $content->title }}</p>
                            <p class="text-xs text-textSecondary">{{ $content->clientSite?->name ?? 'Site' }}</p>
                        </a>
                    @empty
                        <p class="text-sm text-textSecondary">No content matches.</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-textPrimary">Sites ({{ $sites->count() }})</h2>
                <div class="mt-3 space-y-2">
                    @forelse($sites as $site)
                        <a href="{{ route('app.sites.show', $site) }}" class="block rounded-md border border-border px-3 py-2 hover:bg-surfaceSubtle">
                            <p class="text-sm font-medium text-textPrimary">{{ $site->name }}</p>
                            <p class="text-xs text-textSecondary">{{ $site->base_url ?: $site->site_url }}</p>
                        </a>
                    @empty
                        <p class="text-sm text-textSecondary">No sites match.</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-textPrimary">Briefs ({{ $briefs->count() }})</h2>
                <div class="mt-3 space-y-2">
                    @forelse($briefs as $brief)
                        <a href="{{ route('app.briefs.show', $brief) }}" class="block rounded-md border border-border px-3 py-2 hover:bg-surfaceSubtle">
                            <p class="text-sm font-medium text-textPrimary">{{ $brief->title }}</p>
                            <p class="text-xs text-textSecondary">{{ $brief->status }}</p>
                        </a>
                    @empty
                        <p class="text-sm text-textSecondary">No briefs match.</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-textPrimary">Drafts ({{ $drafts->count() }})</h2>
                <div class="mt-3 space-y-2">
                    @forelse($drafts as $draft)
                        <a href="{{ route('app.drafts.show', $draft) }}" class="block rounded-md border border-border px-3 py-2 hover:bg-surfaceSubtle">
                            <p class="text-sm font-medium text-textPrimary">{{ $draft->title }}</p>
                            <p class="text-xs text-textSecondary">{{ $draft->status }}</p>
                        </a>
                    @empty
                        <p class="text-sm text-textSecondary">No drafts match.</p>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
@endsection

