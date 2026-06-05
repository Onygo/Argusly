<div class="space-y-6">
    <div class="grid gap-6 xl:grid-cols-2">
        @foreach (['current_vs_previous', 'current_vs_baseline'] as $comparisonKey)
            @php($comparison = data_get($detail, 'history.summary.' . $comparisonKey))
            <x-llm-tracking.analysis-card :title="$comparison['label'] ?? null" icon="git-compare">
                @if (! empty($comparison['available']))
                    <p class="text-sm text-textSecondary">{{ $comparison['run_label'] ?? '' }}</p>
                    <div class="mt-4 space-y-3">
                        @foreach ((array) ($comparison['rows'] ?? []) as $row)
                            <div class="rounded-lg border border-border bg-background px-4 py-3">
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
                    <p class="text-sm text-textMuted">Not enough historical data to compare this query yet.</p>
                @endif
            </x-llm-tracking.analysis-card>
        @endforeach
    </div>

    <x-llm-tracking.analysis-card
        title="Run history"
        description="Every successful run with the most important metrics and the delta versus the previous run."
        icon="history"
    >
        @if (! empty(data_get($detail, 'history.rows', [])))
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-border text-left text-xs uppercase tracking-[0.16em] text-textMuted">
                            <th class="pb-3 pr-4 font-medium">Run</th>
                            <th class="pb-3 pr-4 font-medium">Provider</th>
                            <th class="pb-3 pr-4 font-medium">Model</th>
                            <th class="pb-3 pr-4 font-medium">Visibility</th>
                            <th class="pb-3 pr-4 font-medium">Mention</th>
                            <th class="pb-3 pr-4 font-medium">Sentiment</th>
                            <th class="pb-3 pr-4 font-medium">Position</th>
                            <th class="pb-3 pr-4 font-medium">Delta</th>
                            <th class="pb-3 font-medium">Cached</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ((array) data_get($detail, 'history.rows', []) as $row)
                            <tr class="border-b border-border/60 align-top">
                                <td class="py-3 pr-4 text-textPrimary">{{ $row['run_at'] ?? '' }}</td>
                                <td class="py-3 pr-4 text-textSecondary">{{ $row['provider'] ?? '-' }}</td>
                                <td class="py-3 pr-4 text-textSecondary">{{ $row['model'] ?? '-' }}</td>
                                <td class="py-3 pr-4 text-textSecondary">{{ $row['visibility'] ?? '-' }}</td>
                                <td class="py-3 pr-4 text-textSecondary">{{ $row['mention_rate'] ?? '-' }}</td>
                                <td class="py-3 pr-4 text-textSecondary">{{ $row['sentiment'] ?? '-' }}</td>
                                <td class="py-3 pr-4 text-textSecondary">{{ $row['position'] ?? '-' }}</td>
                                <td class="py-3 pr-4">
                                    <x-llm-tracking.status-badge :label="$row['delta'] ?? '-'" :tone="$row['delta_tone'] ?? 'slate'" />
                                </td>
                                <td class="py-3 text-textSecondary">{{ !empty($row['is_cached']) ? 'Yes' : 'No' }}</td>
                            </tr>
                            @if (($row['error_message'] ?? '') !== '')
                                <tr>
                                    <td colspan="9" class="pb-3 text-sm text-rose-700">{{ $row['error_message'] }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="rounded-lg border border-dashed border-border bg-background px-4 py-10 text-sm text-textMuted">
                No run history yet.
            </div>
        @endif
    </x-llm-tracking.analysis-card>

    <x-llm-tracking.analysis-card
        title="Trend over time"
        description="Weekly rollup to spot whether visibility and presence are actually improving."
        icon="chart-column"
    >
        @if (! empty(data_get($detail, 'history.trend_rows', [])))
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-border text-left text-xs uppercase tracking-[0.16em] text-textMuted">
                            <th class="pb-3 pr-4 font-medium">Week</th>
                            <th class="pb-3 pr-4 font-medium">Visibility</th>
                            <th class="pb-3 pr-4 font-medium">Mention rate</th>
                            <th class="pb-3 pr-4 font-medium">Citation rate</th>
                            <th class="pb-3 pr-4 font-medium">Positive context</th>
                            <th class="pb-3 pr-4 font-medium">Position</th>
                            <th class="pb-3 font-medium">Runs</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ((array) data_get($detail, 'history.trend_rows', []) as $row)
                            <tr class="border-b border-border/60">
                                <td class="py-3 pr-4 text-textPrimary">{{ $row['period_start'] ?? '' }}</td>
                                <td class="py-3 pr-4 text-textSecondary">{{ $row['visibility'] ?? '-' }}</td>
                                <td class="py-3 pr-4 text-textSecondary">{{ $row['mention_rate'] ?? '-' }}</td>
                                <td class="py-3 pr-4 text-textSecondary">{{ $row['citation_rate'] ?? '-' }}</td>
                                <td class="py-3 pr-4 text-textSecondary">{{ $row['positive_context'] ?? '-' }}</td>
                                <td class="py-3 pr-4 text-textSecondary">{{ $row['position'] ?? '-' }}</td>
                                <td class="py-3 text-textSecondary">{{ $row['run_count'] ?? 0 }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="rounded-lg border border-dashed border-border bg-background px-4 py-10 text-sm text-textMuted">
                No trend data yet. Let this query run across multiple periods.
            </div>
        @endif
    </x-llm-tracking.analysis-card>
</div>
