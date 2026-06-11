@props(['items'])

<section class="rounded-lg border border-border bg-surface p-5" aria-label="{{ __('app.dashboard_action_first.intelligence_feed') }}">
    <div class="flex items-center justify-between gap-3">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">{{ __('app.dashboard_action_first.intelligence_feed') }}</p>
            <h2 class="mt-1 text-base font-semibold text-textPrimary">{{ __('app.dashboard_action_first.latest_business_changes') }}</h2>
        </div>
    </div>
    <div class="mt-4 divide-y divide-border">
        @forelse ($items as $item)
            <a href="{{ data_get($item, 'route') }}" class="grid gap-3 py-3 first:pt-0 last:pb-0 sm:grid-cols-[9rem_minmax(0,1fr)_7rem] sm:items-center hover:bg-surfaceMuted">
                <span class="text-xs font-semibold uppercase tracking-wide text-textFaint">{{ data_get($item, 'type') }}</span>
                <span class="min-w-0">
                    <span class="block truncate text-sm font-semibold text-textPrimary">{{ data_get($item, 'title') }}</span>
                    <span class="mt-1 block line-clamp-1 text-xs text-textSecondary">{{ data_get($item, 'description') }}</span>
                </span>
                <span class="text-xs font-medium text-textSecondary">{{ __('app.dashboard_action_first.impact') }}: {{ data_get($item, 'impact') }}</span>
            </a>
        @empty
            <p class="rounded-md border border-border bg-background p-3 text-sm text-textSecondary">{{ __('app.dashboard_action_first.no_feed_items') }}</p>
        @endforelse
    </div>
</section>
