@php
    $columns = collect($successfulVariantRows ?? [])->values();
    $matrixRows = collect($scoreMatrixRows ?? [])->values();
    $contentProfile = is_array($scoreContextProfile ?? null) ? $scoreContextProfile : [];
    $providerColors = [
        'anthropic' => 'text-orange-600',
        'openai' => 'text-emerald-600',
        'google' => 'text-blue-600',
        'mistral' => 'text-violet-600',
        'deepseek' => 'text-cyan-600',
    ];

    $statusClasses = [
        'excellent' => 'bg-emerald-500/15 text-emerald-700 border-emerald-500/30',
        'ideal_for_context' => 'bg-sky-500/15 text-sky-700 border-sky-500/30',
        'good' => 'bg-primary/15 text-primary border-primary/30',
        'acceptable' => 'bg-amber-500/15 text-amber-700 border-amber-500/30',
        'misaligned' => 'bg-orange-500/15 text-orange-700 border-orange-500/30',
        'needs_improvement' => 'bg-rose-500/15 text-rose-700 border-rose-500/30',
    ];
@endphp

<div class="rounded-lg border border-border bg-surface p-6">
    <div class="flex items-center justify-between gap-4 mb-5">
        <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gradient-to-br from-primary/20 to-primary/10 text-primary">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
            </div>
            <div>
                <h2 class="text-base font-semibold text-textPrimary">Contextual Score Matrix</h2>
                <p class="text-sm text-textSecondary">Scoring is interpreted against this content profile.</p>
            </div>
        </div>
        @if ($columns->isNotEmpty())
            <span class="text-xs text-textSecondary px-3 py-1.5 rounded-full bg-background border border-border">{{ $columns->count() }} model{{ $columns->count() === 1 ? '' : 's' }}</span>
        @endif
    </div>

    <div class="mb-5 grid gap-3 lg:grid-cols-2">
        <div class="rounded-lg border border-border bg-background p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary mb-3">Content Profile</p>
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div>
                    <dt class="text-textSecondary">Content type</dt>
                    <dd class="font-medium text-textPrimary">{{ $contentProfile['content_type_label'] ?? 'Blog' }}</dd>
                </div>
                <div>
                    <dt class="text-textSecondary">Audience</dt>
                    <dd class="font-medium text-textPrimary">{{ $contentProfile['audience_label'] ?? 'General audience' }}</dd>
                </div>
                <div>
                    <dt class="text-textSecondary">Funnel stage</dt>
                    <dd class="font-medium text-textPrimary">{{ $contentProfile['funnel_stage_label'] ?? 'Consideration' }}</dd>
                </div>
                <div>
                    <dt class="text-textSecondary">Search intent</dt>
                    <dd class="font-medium text-textPrimary">{{ $contentProfile['search_intent_label'] ?? 'Not specified' }}</dd>
                </div>
            </dl>
        </div>

        <div class="rounded-lg border border-border bg-background p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary mb-3">Content Strategy Fit</p>
            @if ($columns->isEmpty())
                <p class="text-sm text-textSecondary">Strategy fit appears when at least one variant has scores.</p>
            @else
                <div class="space-y-2">
                    @foreach ($columns as $column)
                        @php
                            $strategyFit = is_array($column['strategy_fit'] ?? null) ? $column['strategy_fit'] : [];
                            $fitLevel = (string) ($strategyFit['status_level'] ?? 'acceptable');
                            $fitBadgeClass = $statusClasses[$fitLevel] ?? 'bg-background text-textSecondary border-border';
                            $fitScore = $strategyFit['score'] ?? null;
                        @endphp
                        <div class="flex items-center justify-between gap-3 rounded-lg border border-border/60 px-3 py-2">
                            <div>
                                <p class="text-sm font-medium text-textPrimary">{{ $column['display_name'] ?? $column['model'] }}</p>
                                <p class="text-xs text-textSecondary">{{ $strategyFit['summary'] ?? 'Strategy fit summary unavailable.' }}</p>
                            </div>
                            <div class="text-right shrink-0">
                                <p class="text-sm font-semibold text-textPrimary">{{ is_numeric($fitScore) ? number_format((float) $fitScore, 1) : '—' }}</p>
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold {{ $fitBadgeClass }}">{{ $strategyFit['status_label'] ?? 'Acceptable' }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    @if ($columns->isEmpty())
        <div class="text-center py-12">
            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-lg bg-background">
                <svg class="h-7 w-7 text-textSecondary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
            </div>
            <p class="text-sm font-medium text-textPrimary mb-1">Contextual scores will appear here</p>
            <p class="text-sm text-textSecondary">Waiting for at least one model to complete successfully.</p>
        </div>
    @else
        <div class="overflow-x-auto -mx-6 px-6">
            <table class="min-w-full">
                <thead>
                    <tr class="border-b-2 border-border">
                        <th class="sticky left-0 z-10 bg-surface py-4 pr-6 text-left text-xs font-semibold text-textSecondary uppercase tracking-wider">Metric</th>
                        @foreach ($columns as $column)
                            @php
                                $providerKey = strtolower((string) ($column['provider'] ?? 'openai'));
                                $providerTextColor = $providerColors[$providerKey] ?? 'text-gray-600';
                            @endphp
                            <th class="px-4 py-4 text-center min-w-[210px]">
                                <div class="flex flex-col items-center gap-1">
                                    <span class="text-xs font-bold {{ $providerTextColor }}">{{ \Illuminate\Support\Str::headline((string) ($column['provider'] ?? '')) }}</span>
                                    <span class="text-[10px] text-textSecondary font-normal max-w-[180px] truncate">{{ $column['model'] }}</span>
                                </div>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($matrixRows as $row)
                        @php
                            $metricKey = (string) ($row['key'] ?? '');
                            $rowCells = collect($row['cells'] ?? [])->keyBy('variant_id');
                            $isContextual = (bool) ($row['is_contextual'] ?? false);
                        @endphp
                        <tr class="border-b border-border/50 align-top">
                            <td class="sticky left-0 z-10 bg-surface py-4 pr-6">
                                <div class="space-y-1.5">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-medium text-textPrimary">{{ $row['label'] }}</span>
                                        @if ($isContextual)
                                            <span class="inline-flex items-center rounded-full border border-sky-500/30 bg-sky-500/10 px-2 py-0.5 text-[10px] font-semibold text-sky-700">Context-aware</span>
                                        @endif
                                    </div>
                                    <p class="text-xs text-textSecondary" title="{{ $row['helper_text'] ?? '' }}">{{ $row['helper_text'] ?? 'Directional signal.' }}</p>
                                </div>
                            </td>
                            @foreach ($columns as $column)
                                @php
                                    $cell = $rowCells->get((string) ($column['id'] ?? ''));
                                    $displayValue = $cell['display_value'] ?? null;
                                    $interpretation = is_array($cell['interpretation'] ?? null) ? $cell['interpretation'] : [];
                                    $statusLevel = (string) ($interpretation['status_level'] ?? 'acceptable');
                                    $statusClass = $statusClasses[$statusLevel] ?? 'bg-background text-textSecondary border-border';
                                @endphp
                                <td class="px-4 py-4">
                                    @if ($cell)
                                        <div class="space-y-2 rounded-lg border border-border/60 bg-background/60 p-3">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="text-lg font-semibold text-textPrimary">{{ $displayValue ?? '—' }}</span>
                                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold {{ $statusClass }}">{{ $interpretation['status_label'] ?? 'Acceptable' }}</span>
                                            </div>
                                            <p class="text-[11px] text-textSecondary">Expected: {{ $interpretation['expected_range_label'] ?? 'Reference metric' }}</p>
                                            <p class="text-[11px] text-textFaint leading-relaxed">{{ $interpretation['explanation'] ?? 'Used as a directional signal for comparison.' }}</p>
                                        </div>
                                    @else
                                        <span class="text-xs text-textFaint">—</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-5 pt-5 border-t border-border/50 flex flex-wrap items-center gap-4 text-xs text-textSecondary">
            <span class="flex items-center gap-2">
                <span class="inline-flex items-center rounded-full border border-sky-500/30 bg-sky-500/10 px-2 py-0.5 text-[10px] font-semibold text-sky-700">Context-aware</span>
                CTA strength, readability, and structure are evaluated against the content profile.
            </span>
            <span class="flex items-center gap-2">
                <span class="inline-block text-textFaint">—</span>
                Score not available
            </span>
        </div>
    @endif
</div>
