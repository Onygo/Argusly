@props(['step'])

<section class="rounded-lg border border-border bg-surface p-5" aria-label="{{ __('app.dashboard_action_first.journey_progress') }}">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">{{ __('app.dashboard_action_first.journey_progress') }}</p>
            <h2 class="mt-1 text-base font-semibold text-textPrimary">{{ data_get($step, 'next_stage') }}</h2>
            <p class="mt-1 text-sm text-textSecondary">{{ __('app.dashboard_action_first.next_journey_step_hint') }}</p>
        </div>
        @if (data_get($step, 'primary_cta_route'))
            <a href="{{ data_get($step, 'primary_cta_route') }}" class="inline-flex h-9 shrink-0 items-center justify-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">
                <i data-lucide="route" class="h-4 w-4"></i>
                {{ data_get($step, 'primary_cta_label') }}
            </a>
        @endif
    </div>
    <div class="mt-4">
        <div class="flex items-center justify-between text-xs text-textSecondary">
            <span>{{ __('app.dashboard_action_first.progress') }}</span>
            <span>{{ (int) data_get($step, 'progress', 0) }}%</span>
        </div>
        <div class="mt-2 h-2 overflow-hidden rounded-full bg-surfaceMuted">
            <div class="h-full rounded-full bg-primary" style="width: {{ max(0, min(100, (int) data_get($step, 'progress', 0))) }}%"></div>
        </div>
    </div>
</section>
