@php
    $siteType = \App\Models\ClientSite::normalizeType((string) ($site->type ?? 'wordpress'));
@endphp

<div class="flex flex-wrap gap-2">
    <a href="{{ route('app.sites.show', $site) }}" class="rounded bg-primary px-2 py-1 text-xs font-medium text-white hover:bg-primary/90">View setup details</a>

    @can('manage-organization')
        @if ($siteType === \App\Models\ClientSite::TYPE_LARAVEL)
            <form method="POST" action="{{ route('app.sites.test-laravel', $site) }}">
                @csrf
                <button class="rounded border border-border px-2 py-1 text-xs hover:bg-surfaceSubtle">Test connection</button>
            </form>
        @else
            <form method="POST" action="{{ route('app.sites.test-wordpress', $site) }}">
                @csrf
                <button class="rounded border border-border px-2 py-1 text-xs hover:bg-surfaceSubtle">Test connection</button>
            </form>
        @endif
    @endcan
</div>
