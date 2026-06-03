@props(['check'])

@php
    $result = $check->latestResult->first();
    $providerRun = $check->latestProviderRun->first();
    $statusClasses = [
        'active' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'paused' => 'border-amber-200 bg-amber-50 text-amber-700',
        'archived' => 'border-slate-200 bg-slate-50 text-slate-500',
    ];
@endphp

<article {{ $attributes->merge(['class' => 'rounded-md border border-line bg-white p-5']) }}>
    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.badge variant="blue">{{ $check->provider }}</x-ui.badge>
                <span class="rounded-full border px-2.5 py-1 text-xs font-semibold {{ $statusClasses[$check->status] ?? $statusClasses['active'] }}">
                    {{ str($check->status)->headline() }}
                </span>
            </div>
            <h3 class="mt-3 text-base font-semibold text-ink">{{ $check->brand }}</h3>
            <p class="mt-2 text-sm leading-6 text-muted">{{ $check->query }}</p>
        </div>
        <div class="grid shrink-0 grid-cols-3 gap-2 text-right sm:w-48">
            <div class="rounded-md bg-panel p-3">
                <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Score</p>
                <p class="mt-1 text-lg font-semibold text-ink">{{ $result?->score ?? '-' }}</p>
            </div>
            <div class="rounded-md bg-panel p-3">
                <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Pos.</p>
                <p class="mt-1 text-lg font-semibold text-ink">{{ $result?->position ?? '-' }}</p>
            </div>
            <div class="rounded-md bg-panel p-3">
                <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Mention</p>
                <p class="mt-1 text-lg font-semibold text-ink">{{ $result?->mention_found ? 'Yes' : '-' }}</p>
            </div>
        </div>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-muted">
        <span>{{ $result ? 'Latest placeholder result' : 'No results yet' }}</span>
        @if ($result)
            <time datetime="{{ $result->captured_at?->toIso8601String() }}">{{ $result->captured_at?->diffForHumans() }}</time>
        @endif
    </div>

    @if ($result)
        <x-evidence.list :items="$result->evidenceItems" class="mt-4 bg-panel" />
    @endif

    @if ($providerRun)
        <div class="mt-4 rounded-md border border-line bg-panel p-4">
            <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-start">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Provider run</p>
                    <p class="mt-1 text-sm font-semibold text-ink">{{ $providerRun->model ?? 'Model pending' }}</p>
                </div>
                <span class="text-xs font-semibold text-muted">{{ str($providerRun->status)->headline() }}</span>
            </div>
            <p class="mt-3 text-sm leading-6 text-muted">{{ $providerRun->normalized_answer }}</p>
            <div class="mt-3 grid gap-2 text-xs text-muted sm:grid-cols-3">
                <span>{{ $providerRun->citations->count() }} citations</span>
                <span>{{ $providerRun->answerEntities->count() }} entities</span>
                <span>{{ $providerRun->cost_credits }} credits</span>
            </div>
        </div>
    @endif
</article>
