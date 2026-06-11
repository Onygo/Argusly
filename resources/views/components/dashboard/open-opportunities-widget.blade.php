@props(['summary'])

<section class="rounded-lg border border-border bg-surface p-5" aria-label="{{ __('app.dashboard_action_first.open_opportunities') }}">
    <div class="flex items-start justify-between gap-3">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">{{ __('app.dashboard_action_first.open_opportunities') }}</p>
            <h2 class="mt-1 text-2xl font-semibold text-textPrimary">{{ number_format((int) data_get($summary, 'count', 0)) }}</h2>
            <p class="mt-1 text-sm text-textSecondary">{{ trans_choice('app.dashboard_action_first.high_business_priority_count', (int) data_get($summary, 'high_priority_count', 0), ['count' => (int) data_get($summary, 'high_priority_count', 0)]) }}</p>
        </div>
        <i data-lucide="target" class="h-5 w-5 text-primary"></i>
    </div>
    <div class="mt-4 space-y-3">
        @forelse (data_get($summary, 'items', collect()) as $opportunity)
            <a href="{{ route('app.opportunity-intelligence.opportunities.show', $opportunity) }}" class="block rounded-md border border-border bg-background p-3 hover:bg-surfaceMuted">
                <p class="truncate text-sm font-semibold text-textPrimary">{{ $opportunity->title }}</p>
                <p class="mt-1 line-clamp-2 text-xs text-textSecondary">{{ $opportunity->summary ?: $opportunity->topic }}</p>
                <p class="mt-2 text-xs text-textSecondary">{{ __('app.dashboard_action_first.business_priority') }} {{ number_format((float) $opportunity->priority_score, 0) }}</p>
            </a>
        @empty
            <p class="rounded-md border border-border bg-background p-3 text-sm text-textSecondary">{{ __('app.dashboard_action_first.no_open_opportunities') }}</p>
        @endforelse
    </div>
</section>
