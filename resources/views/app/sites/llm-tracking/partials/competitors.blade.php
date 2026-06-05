<div class="space-y-6">
    <x-llm-tracking.analysis-card
        title="Competitor analysis"
        description="Compare competitor visibility, relative position, answer share, and whether they outperform the brand."
        icon="users"
    >
        <div class="rounded-lg border border-border bg-background px-4 py-3">
            <p class="text-sm text-textSecondary">{{ data_get($detail, 'competitors.summary', 'No competitor analysis yet.') }}</p>
            <p class="mt-2 text-sm font-medium text-textPrimary">{{ data_get($detail, 'competitors.conclusion', '') }}</p>
        </div>

        @if (! empty(data_get($detail, 'competitors.rows', [])))
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-border text-left text-xs uppercase tracking-[0.16em] text-textMuted">
                            <th class="pb-3 pr-4 font-medium">Competitor</th>
                            <th class="pb-3 pr-4 font-medium">Mentioned</th>
                            <th class="pb-3 pr-4 font-medium">Mentions</th>
                            <th class="pb-3 pr-4 font-medium">Share</th>
                            <th class="pb-3 pr-4 font-medium">Position</th>
                            <th class="pb-3 pr-4 font-medium">Advantage</th>
                            <th class="pb-3 font-medium">Context</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ((array) data_get($detail, 'competitors.rows', []) as $row)
                            <tr class="border-b border-border/60 align-top">
                                <td class="py-3 pr-4 font-medium text-textPrimary">{{ $row['name'] ?? '' }}</td>
                                <td class="py-3 pr-4">
                                    <x-llm-tracking.status-badge :label="!empty($row['mentioned']) ? 'Yes' : 'No'" :tone="$row['tone'] ?? 'slate'" />
                                </td>
                                <td class="py-3 pr-4 text-textSecondary">{{ $row['mentions'] ?? 0 }}</td>
                                <td class="py-3 pr-4 text-textSecondary">{{ isset($row['share']) && is_numeric($row['share']) ? number_format(((float) $row['share']) * 100, 1) . '%' : '-' }}</td>
                                <td class="py-3 pr-4 text-textSecondary">{{ $row['position'] ?? '-' }}</td>
                                <td class="py-3 pr-4">
                                    <x-llm-tracking.status-badge :label="$row['advantage'] ?? '-'" :tone="$row['tone'] ?? 'slate'" />
                                </td>
                                <td class="py-3 text-textSecondary">{{ $row['context'] ?: 'No snippet context captured.' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="mt-4 rounded-lg border border-dashed border-border bg-background px-4 py-10 text-sm text-textMuted">
                No competitor data yet. Run the query to see which competitors show up in the answer and how they compare to the brand.
            </div>
        @endif
    </x-llm-tracking.analysis-card>

    <x-llm-tracking.analysis-card
        title="High-performing entities"
        description="Authority benchmarks and ecosystem entities detected beyond the configured competitor list."
        icon="radar"
    >
        @if (! empty(data_get($detail, 'competitors.candidate_rows', [])))
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-border text-left text-xs uppercase tracking-[0.16em] text-textMuted">
                            <th class="pb-3 pr-4 font-medium">Entity</th>
                            <th class="pb-3 pr-4 font-medium">Category</th>
                            <th class="pb-3 pr-4 font-medium">Mentions</th>
                            <th class="pb-3 pr-4 font-medium">Rank</th>
                            <th class="pb-3 pr-4 font-medium">Providers</th>
                            <th class="pb-3 font-medium">Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ((array) data_get($detail, 'competitors.candidate_rows', []) as $row)
                            <tr class="border-b border-border/60 align-top">
                                <td class="py-3 pr-4 font-medium text-textPrimary">{{ $row['brand_name'] ?? '' }}</td>
                                <td class="py-3 pr-4 text-textSecondary">{{ $row['category'] ?? '-' }}</td>
                                <td class="py-3 pr-4 text-textSecondary">{{ $row['mention_count'] ?? 0 }}</td>
                                <td class="py-3 pr-4 text-textSecondary">
                                    {{ $row['latest_rank'] ?? '-' }}
                                    @if (isset($row['average_rank']) && is_numeric($row['average_rank']))
                                        <span class="text-xs text-textMuted">avg {{ number_format((float) $row['average_rank'], 1) }}</span>
                                    @endif
                                </td>
                                <td class="py-3 pr-4 text-textSecondary">{{ implode(', ', (array) ($row['providers'] ?? [])) ?: '-' }}</td>
                                <td class="py-3 text-textSecondary">
                                    {{ $row['reason'] ?? 'Detected in AI answer evidence.' }}
                                    @if (! empty($row['source_urls']))
                                        <details class="mt-2">
                                            <summary class="cursor-pointer text-textPrimary">Sources</summary>
                                            <div class="mt-1 space-y-1">
                                                @foreach ((array) $row['source_urls'] as $url)
                                                    <p class="break-all text-xs">{{ $url }}</p>
                                                @endforeach
                                            </div>
                                        </details>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="rounded-lg border border-dashed border-border bg-background px-4 py-10 text-sm text-textMuted">
                No high-performing entity candidates detected for this query yet.
            </div>
        @endif
    </x-llm-tracking.analysis-card>

    <x-llm-tracking.analysis-card
        title="Competitor and authority learnings"
        description="Reusable observations extracted from cited sources, positioning, content formats, and provider-specific answer patterns."
        icon="lightbulb"
    >
        <div class="grid gap-3 lg:grid-cols-2">
            @forelse ((array) data_get($detail, 'authority_learnings', []) as $learning)
                <div class="rounded border border-border bg-background p-4">
                    <div class="flex items-center justify-between gap-3">
                        <p class="font-semibold text-textPrimary">{{ $learning['title'] ?? '' }}</p>
                        <span class="text-xs text-textSecondary">{{ $learning['provider'] ?? 'all providers' }}</span>
                    </div>
                    <p class="mt-2 text-sm text-textSecondary">{{ $learning['summary'] ?? '' }}</p>
                    @if (! empty($learning['recommended_action']))
                        <p class="mt-2 text-xs font-medium text-textPrimary">{{ $learning['recommended_action'] }}</p>
                    @endif
                </div>
            @empty
                <p class="text-sm text-textSecondary">No structured learnings extracted yet.</p>
            @endforelse
        </div>
    </x-llm-tracking.analysis-card>
</div>
