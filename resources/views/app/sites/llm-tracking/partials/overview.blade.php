<div class="space-y-6">
    <x-llm-tracking.analysis-card
        title="What this means"
        description="Executive summary of the latest run so you can decide what to do next without reading the raw output."
        icon="sparkles"
    >
        <ul class="space-y-3">
            @foreach ((array) data_get($detail, 'overview.executive_summary', []) as $item)
                <li class="flex gap-3 text-sm leading-6 text-textSecondary">
                    <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-primary/70"></span>
                    <span>{{ $item }}</span>
                </li>
            @endforeach
        </ul>
    </x-llm-tracking.analysis-card>

    <x-llm-tracking.analysis-card
        title="Brand presence"
        description="How the brand appears in the answer: presence, placement, prominence, context, and citation support."
        icon="badge-check"
    >
        <div class="grid gap-3 md:grid-cols-2">
            @foreach ((array) data_get($detail, 'overview.brand_presence.rows', []) as $row)
                <div class="rounded-lg border border-border bg-background px-4 py-3">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-textMuted">{{ $row['label'] ?? '' }}</p>
                            <p class="mt-2 text-sm font-medium text-textPrimary">{{ $row['value'] ?? '' }}</p>
                        </div>
                        <x-llm-tracking.status-badge :label="$row['value'] ?? ''" :tone="$row['tone'] ?? 'slate'" />
                    </div>
                </div>
            @endforeach
        </div>
    </x-llm-tracking.analysis-card>

    <div class="grid gap-6 xl:grid-cols-2">
        <x-llm-tracking.analysis-card
            title="Why the score looks like this"
            description="Top scoring factors and explainability from the current visibility calculation."
            icon="line-chart"
        >
            <div class="space-y-3">
                @foreach ((array) data_get($detail, 'overview.score_factors', []) as $factor)
                    <div class="rounded-lg border border-border bg-background px-4 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <p class="text-sm font-medium text-textPrimary">{{ $factor['label'] ?? '' }}</p>
                                @if (($factor['weight'] ?? null))
                                    <p class="text-xs text-textMuted">{{ $factor['weight'] }}</p>
                                @endif
                            </div>
                            <span class="text-sm font-semibold text-textPrimary">{{ $factor['score'] }}</span>
                        </div>
                        @if (($factor['text'] ?? '') !== '')
                            <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $factor['text'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-llm-tracking.analysis-card>

        <x-llm-tracking.analysis-card
            title="Run comparison"
            description="Compare the current result with the previous run and the earliest baseline run."
            icon="git-compare"
        >
            <div class="space-y-4">
                @foreach (['current_vs_previous', 'current_vs_baseline'] as $comparisonKey)
                    @php($comparison = data_get($detail, 'overview.comparison.' . $comparisonKey))
                    <div class="rounded-lg border border-border bg-background p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h3 class="text-sm font-semibold text-textPrimary">{{ $comparison['label'] ?? '' }}</h3>
                                @if (($comparison['run_label'] ?? '') !== '')
                                    <p class="text-xs text-textMuted">{{ $comparison['run_label'] }}</p>
                                @endif
                            </div>
                        </div>

                        @if (! empty($comparison['available']))
                            <div class="mt-3 space-y-3">
                                @foreach ((array) ($comparison['rows'] ?? []) as $row)
                                    <div class="rounded-lg border border-border/70 px-3 py-3">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <p class="text-sm font-medium text-textPrimary">{{ $row['label'] ?? '' }}</p>
                                            <x-llm-tracking.status-badge :label="$row['delta'] ?? 'No change'" :tone="$row['delta_tone'] ?? 'slate'" />
                                        </div>
                                        <div class="mt-2 grid grid-cols-2 gap-2 text-sm text-textSecondary">
                                            <div>Current: <span class="font-medium text-textPrimary">{{ $row['current'] ?? '-' }}</span></div>
                                            <div>Compare: <span class="font-medium text-textPrimary">{{ $row['comparison'] ?? '-' }}</span></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="mt-3 text-sm text-textSecondary">Not enough run history yet to compare this query.</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-llm-tracking.analysis-card>
    </div>
</div>
