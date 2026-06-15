@props(['action'])

@php
    $priorityTone = match ((string) $action->priority_label) {
        'critical' => 'border-rose-200 bg-rose-50 text-rose-800',
        'high' => 'border-amber-200 bg-amber-50 text-amber-800',
        'low' => 'border-slate-200 bg-slate-50 text-slate-700',
        default => 'border-sky-200 bg-sky-50 text-sky-800',
    };
@endphp

<article {{ $attributes->merge(['class' => 'rounded-lg border border-border bg-surface p-5']) }}>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-full border px-2.5 py-1 text-xs font-semibold {{ $priorityTone }}">{{ ucfirst((string) $action->priority_label) }}</span>
                <span class="rounded-full border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', ucfirst((string) $action->source_group)) }}</span>
                <span class="rounded-full border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">{{ ucfirst((string) $action->estimated_effort) }} effort</span>
            </div>
            <h3 class="mt-3 text-base font-semibold text-textPrimary">{{ $action->title }}</h3>
            @if ($action->summary)
                <p class="mt-1 text-sm leading-6 text-textSecondary">{{ $action->summary }}</p>
            @endif
        </div>
        <div class="flex shrink-0 flex-wrap gap-2">
            @if ($action->primary_cta_url)
                <a href="{{ $action->primary_cta_url }}" class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                    <i data-lucide="arrow-right" class="h-4 w-4"></i>
                    {{ $action->primary_cta_label ?: 'Open' }}
                </a>
            @endif
        </div>
    </div>

    <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-md border border-border bg-background p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Why this matters</p>
            <p class="mt-1 text-sm leading-6 text-textSecondary">{{ $action->why_this_matters }}</p>
        </div>
        <div class="rounded-md border border-border bg-background p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Expected outcome</p>
            <p class="mt-1 text-sm leading-6 text-textSecondary">{{ $action->expected_outcome }}</p>
        </div>
        <div class="rounded-md border border-border bg-background p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">What Argusly will do</p>
            <p class="mt-1 text-sm leading-6 text-textSecondary">{{ $action->what_argusly_will_do }}</p>
        </div>
        <div class="rounded-md border border-border bg-background p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">What requires approval</p>
            <p class="mt-1 text-sm leading-6 text-textSecondary">{{ $action->what_requires_approval }}</p>
        </div>
    </div>

    <div class="mt-4 grid gap-3 sm:grid-cols-3">
        <div class="rounded-md border border-border bg-background px-3 py-2">
            <p class="text-xs text-textSecondary">Priority</p>
            <p class="text-sm font-semibold text-textPrimary">{{ $action->priority_score }}/100</p>
        </div>
        <div class="rounded-md border border-border bg-background px-3 py-2">
            <p class="text-xs text-textSecondary">Confidence</p>
            <p class="text-sm font-semibold text-textPrimary">{{ $action->confidence_score }}/100</p>
        </div>
        <div class="rounded-md border border-border bg-background px-3 py-2">
            <p class="text-xs text-textSecondary">Expected impact</p>
            <p class="text-sm font-semibold text-textPrimary">{{ $action->expected_impact_score }}/100</p>
        </div>
    </div>
</article>
