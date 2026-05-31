@props(['competitor'])

@php
    $snapshot = $competitor->latestSnapshot->first();
    $statusClasses = [
        'active' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'paused' => 'border-amber-200 bg-amber-50 text-amber-700',
        'archived' => 'border-slate-200 bg-slate-50 text-slate-500',
    ];
@endphp

<article {{ $attributes->merge(['class' => 'rounded-lg border border-line bg-white p-5']) }}>
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-full border px-2.5 py-1 text-xs font-semibold {{ $statusClasses[$competitor->status] ?? $statusClasses['active'] }}">
                    {{ str($competitor->status)->headline() }}
                </span>
                @if ($competitor->industry)
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ $competitor->industry }}</span>
                @endif
            </div>
            <h3 class="mt-3 truncate text-base font-semibold text-ink">{{ $competitor->name }}</h3>
            <a class="mt-1 block truncate text-sm text-blue" href="{{ $competitor->website }}" target="_blank" rel="noreferrer">{{ $competitor->website }}</a>
        </div>
    </div>

    <div class="mt-5 grid grid-cols-3 gap-2 text-right">
        <div class="rounded-lg bg-panel p-3">
            <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Visibility</p>
            <p class="mt-1 text-lg font-semibold text-ink">{{ $snapshot?->visibility_score ?? '-' }}</p>
        </div>
        <div class="rounded-lg bg-panel p-3">
            <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Mentions</p>
            <p class="mt-1 text-lg font-semibold text-ink">{{ $snapshot?->mention_score ?? '-' }}</p>
        </div>
        <div class="rounded-lg bg-panel p-3">
            <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">SOV</p>
            <p class="mt-1 text-lg font-semibold text-ink">{{ $snapshot?->share_of_voice ?? '-' }}</p>
        </div>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-muted">
        <span>{{ $snapshot ? 'Last snapshot' : 'No snapshots yet' }}</span>
        @if ($snapshot)
            <time datetime="{{ $snapshot->captured_at?->toIso8601String() }}">{{ $snapshot->captured_at?->diffForHumans() }}</time>
        @endif
    </div>
</article>
