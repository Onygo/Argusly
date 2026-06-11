@props(['action'])

@php
    $impactTone = match (strtolower((string) data_get($action, 'estimated_impact'))) {
        strtolower((string) __('app.dashboard_action_first.high')) => 'border-rose-200 bg-rose-50 text-rose-700',
        strtolower((string) __('app.dashboard_action_first.medium')) => 'border-amber-200 bg-amber-50 text-amber-700',
        default => 'border-emerald-200 bg-emerald-50 text-emerald-700',
    };
@endphp

<section class="rounded-lg border border-border bg-surface p-5" aria-label="{{ __('app.dashboard_action_first.recommended_action') }}">
    <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
        <div class="max-w-3xl">
            <div class="flex flex-wrap items-center gap-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">{{ data_get($action, 'eyebrow') }}</p>
                <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $impactTone }}">
                    {{ __('app.dashboard_action_first.estimated_impact') }}: {{ data_get($action, 'estimated_impact') }}
                </span>
            </div>
            <h2 class="mt-2 text-xl font-semibold text-textPrimary">{{ data_get($action, 'title') }}</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">{{ __('app.dashboard_action_first.what_happened') }}</p>
                    <p class="mt-1 text-sm leading-6 text-textSecondary">{{ data_get($action, 'what_happened') }}</p>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">{{ __('app.dashboard_action_first.why_it_matters') }}</p>
                    <p class="mt-1 text-sm leading-6 text-textSecondary">{{ data_get($action, 'why_it_matters') }}</p>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">{{ __('app.dashboard_action_first.expected_outcome') }}</p>
                    <p class="mt-1 text-sm leading-6 text-textSecondary">{{ data_get($action, 'expected_outcome') }}</p>
                </div>
            </div>
            <p class="mt-4 text-sm font-semibold text-textPrimary">{{ data_get($action, 'recommended_action') }}</p>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2">
            @if (data_get($action, 'primary_cta_route'))
                <a href="{{ data_get($action, 'primary_cta_route') }}" class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                    <i data-lucide="arrow-right" class="h-4 w-4"></i>
                    {{ data_get($action, 'primary_cta_label') }}
                </a>
            @endif
            @if (data_get($action, 'secondary_cta_route'))
                <a href="{{ data_get($action, 'secondary_cta_route') }}" class="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">
                    <i data-lucide="eye" class="h-4 w-4"></i>
                    {{ data_get($action, 'secondary_cta_label') }}
                </a>
            @endif
        </div>
    </div>
</section>
