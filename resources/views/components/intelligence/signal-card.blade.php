@props(['signal', 'compact' => false])

@php
    $statusClasses = [
        'new' => 'border-blue/15 bg-blue/5 text-blue',
        'reviewed' => 'border-line bg-white text-muted',
        'in_progress' => 'border-purple/15 bg-purple/5 text-purple',
        'resolved' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'dismissed' => 'border-slate-200 bg-slate-50 text-slate-500',
    ];
    $priorityClasses = [
        'low' => 'border-slate-200 bg-slate-50 text-slate-600',
        'medium' => 'border-blue/15 bg-blue/5 text-blue',
        'high' => 'border-amber-200 bg-amber-50 text-amber-700',
        'critical' => 'border-rose-200 bg-rose-50 text-rose-700',
    ];
@endphp

<article {{ $attributes->merge(['class' => 'rounded-md border border-line bg-white p-5']) }}>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-full border px-2.5 py-1 text-xs font-semibold {{ $statusClasses[$signal->status] ?? $statusClasses['reviewed'] }}">
                    {{ str($signal->status)->replace('_', ' ')->headline() }}
                </span>
                <span class="rounded-full border px-2.5 py-1 text-xs font-semibold {{ $priorityClasses[$signal->priority] ?? $priorityClasses['medium'] }}">
                    {{ str($signal->priority)->headline() }}
                </span>
                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ str($signal->category)->headline() }}</span>
                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ str($signal->type)->replace('_', ' ')->headline() }}</span>
                @if ($signal->brand)
                    <span class="text-xs text-muted">{{ $signal->brand->name }}</span>
                @endif
            </div>
            <h3 class="mt-3 text-base font-semibold text-ink">{{ $signal->title }}</h3>
            <p class="mt-2 text-sm leading-6 text-muted">{{ $signal->summary }}</p>
            @if (! $compact)
                <a href="{{ route('app.intelligence.show', $signal) }}" class="mt-3 inline-flex text-sm font-semibold text-blue">Open signal</a>
            @endif
        </div>

        <div class="grid shrink-0 grid-cols-2 gap-2 text-right sm:w-32">
            <div class="rounded-md bg-panel p-3">
                <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Impact</p>
                <p class="mt-1 text-lg font-semibold text-ink">{{ $signal->impact_score ?? '—' }}</p>
            </div>
            <div class="rounded-md bg-panel p-3">
                <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Conf.</p>
                <p class="mt-1 text-lg font-semibold text-ink">{{ $signal->confidence_score ?? '—' }}</p>
            </div>
        </div>
    </div>

    @if (! $compact && $signal->recommended_action)
        <div class="mt-4 rounded-md border border-line bg-panel p-4">
            <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Recommended action</p>
            <p class="mt-1 text-sm leading-6 text-ink">{{ $signal->recommended_action }}</p>
        </div>
    @endif

    @if (! $compact)
        <x-evidence.list :items="$signal->evidenceItems" class="mt-4 bg-panel" />
    @endif

    @if (! $compact && $signal->recommendations->isNotEmpty())
        <div class="mt-4 space-y-3">
            <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Recommendations</p>
            @foreach ($signal->recommendations as $recommendation)
                <x-recommendations.card :recommendation="$recommendation" />
            @endforeach
        </div>
    @endif

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-muted">
        <div class="flex flex-wrap items-center gap-2">
            <span>{{ $signal->source }}</span>
            <time datetime="{{ $signal->detected_at?->toIso8601String() }}">{{ $signal->detected_at?->diffForHumans() }}</time>
        </div>
        @if (! $compact)
            <div class="flex flex-wrap items-center gap-2">
                @if ($signal->status !== 'reviewed')
                    <form method="POST" action="{{ route('app.intelligence.reviewed', $signal) }}">
                        @csrf
                        <x-ui.button type="submit" variant="light" size="sm">Mark reviewed</x-ui.button>
                    </form>
                @endif
                @if ($signal->status !== 'dismissed')
                    <form method="POST" action="{{ route('app.intelligence.dismiss', $signal) }}">
                        @csrf
                        <x-ui.button type="submit" variant="light" size="sm">Dismiss</x-ui.button>
                    </form>
                @endif
            </div>
        @endif
    </div>
</article>
