@props(['summary' => []])

@php
    $topSignals = collect(data_get($summary, 'top', []));
    $latestSignals = collect(data_get($summary, 'latest', []));
    $confidenceSignals = collect(data_get($summary, 'highest_confidence', []));
    $signals = $topSignals->merge($latestSignals)->merge($confidenceSignals)->unique('id')->take(5)->values();
@endphp

<section class="rounded-lg border border-border bg-surface p-5" aria-label="Human Signals">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Human Signals</p>
            <h2 class="mt-1 text-base font-semibold text-textPrimary">Observed patterns behind the work</h2>
            <p class="mt-1 text-sm text-textSecondary">{{ (int) data_get($summary, 'count', 0) }} active signal{{ (int) data_get($summary, 'count', 0) === 1 ? '' : 's' }}</p>
        </div>
    </div>

    <div class="mt-4 divide-y divide-border">
        @forelse ($signals as $signal)
            <article class="grid gap-3 py-4 first:pt-0 last:pb-0 lg:grid-cols-[minmax(0,1fr)_8rem_auto] lg:items-center">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full border border-border bg-background px-2 py-0.5 text-[11px] font-medium uppercase tracking-wide text-textSecondary">{{ str_replace('_', ' ', (string) data_get($signal, 'type')) }}</span>
                        <h3 class="text-sm font-semibold text-textPrimary">{{ data_get($signal, 'title') }}</h3>
                    </div>
                    <p class="mt-2 line-clamp-2 text-sm text-textSecondary">{{ data_get($signal, 'observation') }}</p>
                    <p class="mt-1 line-clamp-1 text-xs text-textFaint">{{ data_get($signal, 'impact') }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium text-textFaint">Confidence</p>
                    <p class="mt-1 text-lg font-semibold text-textPrimary">{{ number_format((float) data_get($signal, 'confidence_score', 0), 0) }}%</p>
                </div>
                <div class="flex gap-2 lg:justify-end">
                    <a href="{{ route('app.content.create') }}" class="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-border px-3 text-sm font-medium text-textPrimary hover:bg-background">
                        <i data-lucide="file-plus-2" class="h-4 w-4"></i>
                        Content
                    </a>
                    <a href="{{ route('app.opportunities.index') }}" class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                        <i data-lucide="target" class="h-4 w-4"></i>
                        Opportunity
                    </a>
                </div>
            </article>
        @empty
            <div class="rounded-md border border-border bg-background p-4">
                <p class="text-sm font-medium text-textPrimary">No Human Signals detected yet</p>
                <p class="mt-1 text-sm text-textSecondary">Run detection after AI visibility, FAQ, campaign, or content performance data is available.</p>
            </div>
        @endforelse
    </div>
</section>
