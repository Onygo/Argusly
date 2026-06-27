<div class="rounded-lg border border-border bg-surface p-5">
    <h2 class="text-sm font-semibold text-textPrimary">{{ $title }}</h2>
    <div class="mt-4 space-y-3">
        @forelse ($items as $item)
            <a href="{{ route('app.drafts.show', ['draft' => $item['id'], 'tab' => 'intelligence']) }}" class="block rounded-md border border-border bg-background p-3 hover:bg-surfaceSubtle">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="line-clamp-2 text-sm font-medium text-textPrimary">{{ $item['title'] }}</p>
                        <p class="mt-1 text-xs text-textSecondary">{{ $item['workspace'] }} · {{ $item['locale'] ?: 'n/a' }}</p>
                    </div>
                    <span class="shrink-0 rounded bg-surfaceMuted px-2 py-1 text-xs text-textSecondary">{{ $metricLabel }} {{ $item[$metric] ?? 'n/a' }}</span>
                </div>
            </a>
        @empty
            <p class="text-sm text-textSecondary">No scored articles for this section yet.</p>
        @endforelse
    </div>
</div>
