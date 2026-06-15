@props(['summary'])

<section class="rounded-lg border border-border bg-surface p-5" aria-label="Recommended Actions">
    <div class="flex items-start justify-between gap-3">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Recommended Actions</p>
            <h2 class="mt-1 text-2xl font-semibold text-textPrimary">{{ number_format((int) data_get($summary, 'count', 0)) }}</h2>
            <p class="mt-1 text-sm text-textSecondary">{{ (int) data_get($summary, 'high_priority_count', 0) }} high impact, {{ (int) data_get($summary, 'approval_required_count', 0) }} need approval</p>
        </div>
        <i data-lucide="list-checks" class="h-5 w-5 text-primary"></i>
    </div>
    <div class="mt-4 space-y-3">
        @forelse (data_get($summary, 'items', collect()) as $action)
            <a href="{{ $action->primary_cta_url ?: route('app.recommended-actions.index') }}" class="block rounded-md border border-border bg-background p-3 hover:bg-surfaceMuted">
                <p class="truncate text-sm font-semibold text-textPrimary">{{ $action->title }}</p>
                <p class="mt-1 line-clamp-2 text-xs text-textSecondary">{{ $action->expected_outcome }}</p>
                <p class="mt-2 text-xs text-textSecondary">Priority {{ $action->priority_score }}/100 · {{ ucfirst((string) $action->estimated_effort) }} effort</p>
            </a>
        @empty
            <p class="rounded-md border border-border bg-background p-3 text-sm text-textSecondary">No recommended actions need attention right now.</p>
        @endforelse
    </div>
    <a href="{{ route('app.recommended-actions.index') }}" class="mt-4 inline-flex h-9 items-center gap-2 rounded-md border border-border px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">
        <i data-lucide="inbox" class="h-4 w-4"></i>
        Open actions inbox
    </a>
</section>
