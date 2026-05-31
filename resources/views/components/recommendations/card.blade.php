@props(['recommendation', 'compact' => false])

@php
    $statusClasses = [
        'new' => 'border-blue/15 bg-blue/5 text-blue',
        'accepted' => 'border-amber-200 bg-amber-50 text-amber-700',
        'dismissed' => 'border-slate-200 bg-slate-50 text-slate-500',
        'completed' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
    ];
@endphp

<article {{ $attributes->merge(['class' => 'rounded-lg border border-line bg-white p-4']) }}>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-full border px-2.5 py-1 text-xs font-semibold {{ $statusClasses[$recommendation->status] ?? $statusClasses['new'] }}">
                    {{ str($recommendation->status)->headline() }}
                </span>
                @if ($recommendation->brand)
                    <span class="text-xs text-muted">{{ $recommendation->brand->name }}</span>
                @endif
            </div>
            <h3 class="mt-3 text-sm font-semibold text-ink">{{ $recommendation->title }}</h3>
            <p class="mt-2 text-sm leading-6 text-muted">{{ $recommendation->summary }}</p>
        </div>

        <div class="grid shrink-0 grid-cols-2 gap-2 text-right sm:w-32">
            <div class="rounded-lg bg-panel p-3">
                <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Impact</p>
                <p class="mt-1 text-lg font-semibold text-ink">{{ $recommendation->impact_score ?? '-' }}</p>
            </div>
            <div class="rounded-lg bg-panel p-3">
                <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Conf.</p>
                <p class="mt-1 text-lg font-semibold text-ink">{{ $recommendation->confidence_score ?? '-' }}</p>
            </div>
        </div>
    </div>

    @if (! $compact)
        <div class="mt-4 rounded-lg border border-line bg-panel p-4">
            <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Recommended action</p>
            <p class="mt-1 text-sm leading-6 text-ink">{{ $recommendation->recommended_action }}</p>
        </div>

        <x-evidence.list :items="$recommendation->evidenceItems" class="mt-4 bg-panel" />
    @endif

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <time class="text-xs text-muted" datetime="{{ $recommendation->created_at?->toIso8601String() }}">
            {{ $recommendation->created_at?->diffForHumans() }}
        </time>
        @if (! $compact)
            <div class="flex flex-wrap items-center gap-2">
                @if ($recommendation->status !== 'accepted')
                    <form method="POST" action="{{ route('app.recommendations.accept', $recommendation) }}">
                        @csrf
                        <x-ui.button type="submit" variant="light" size="sm">Accept</x-ui.button>
                    </form>
                @endif
                @if ($recommendation->status !== 'dismissed')
                    <form method="POST" action="{{ route('app.recommendations.dismiss', $recommendation) }}">
                        @csrf
                        <x-ui.button type="submit" variant="light" size="sm">Dismiss</x-ui.button>
                    </form>
                @endif
            </div>
        @endif
    </div>
</article>
