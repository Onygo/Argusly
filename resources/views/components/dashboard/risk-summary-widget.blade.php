@props(['summary'])

<section class="rounded-lg border border-border bg-surface p-5" aria-label="{{ __('app.dashboard_action_first.active_risks') }}">
    <div class="flex items-start justify-between gap-3">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">{{ __('app.dashboard_action_first.active_risks') }}</p>
            <h2 class="mt-1 text-2xl font-semibold text-textPrimary">{{ number_format((int) data_get($summary, 'count', 0)) }}</h2>
            <p class="mt-1 text-sm text-textSecondary">{{ trans_choice('app.dashboard_action_first.high_impact_risk_count', (int) data_get($summary, 'high_priority_count', 0), ['count' => (int) data_get($summary, 'high_priority_count', 0)]) }}</p>
        </div>
        <i data-lucide="circle-alert" class="h-5 w-5 text-amber-600"></i>
    </div>
    <div class="mt-4 space-y-3">
        @forelse (data_get($summary, 'items', collect()) as $risk)
            <a href="{{ route('app.opportunities.candidates.show', $risk) }}" class="block rounded-md border border-border bg-background p-3 hover:bg-surfaceMuted">
                <p class="truncate text-sm font-semibold text-textPrimary">{{ $risk->title }}</p>
                <p class="mt-1 line-clamp-2 text-xs text-textSecondary">{{ $risk->summary ?: $risk->primary_topic }}</p>
                <p class="mt-2 text-xs text-textSecondary">{{ __('app.dashboard_action_first.risk_level') }} {{ number_format((float) ($risk->risk_score ?: $risk->priority_score), 0) }}</p>
            </a>
        @empty
            <p class="rounded-md border border-border bg-background p-3 text-sm text-textSecondary">{{ __('app.dashboard_action_first.no_active_risks') }}</p>
        @endforelse
    </div>
</section>
