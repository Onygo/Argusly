@props(['agent'])

@php
    $statusClasses = [
        'active' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'idle' => 'border-blue/15 bg-blue/5 text-blue',
        'paused' => 'border-amber-200 bg-amber-50 text-amber-700',
    ];
@endphp

<article {{ $attributes->merge(['class' => 'rounded-lg border border-line bg-white p-5']) }}>
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-base font-semibold text-ink">{{ $agent->name }}</h3>
                <span class="rounded-full border px-2.5 py-1 text-xs font-semibold {{ $statusClasses[$agent->status] ?? $statusClasses['idle'] }}">
                    {{ str($agent->status)->headline() }}
                </span>
            </div>
            <p class="mt-2 text-sm leading-6 text-muted">{{ $agent->description }}</p>
        </div>
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
        @foreach ($agent->capabilities ?? [] as $capability)
            <span class="rounded-full border border-line bg-panel px-2.5 py-1 text-xs font-semibold text-muted">{{ str($capability)->replace('_', ' ')->headline() }}</span>
        @endforeach
    </div>

    <div class="mt-5 grid grid-cols-2 gap-2 text-right">
        <div class="rounded-lg bg-panel p-3">
            <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Runs</p>
            <p class="mt-1 text-lg font-semibold text-ink">{{ $agent->runs_count }}</p>
        </div>
        <div class="rounded-lg bg-panel p-3">
            <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Tasks</p>
            <p class="mt-1 text-lg font-semibold text-ink">{{ $agent->tasks_count }}</p>
        </div>
    </div>
</article>
