<div class="space-y-6">
    <div class="grid gap-6 xl:grid-cols-2">
        @foreach ((array) data_get($detail, 'findings', []) as $card)
            <x-llm-tracking.analysis-card :title="$card['title'] ?? null" :icon="match ($card['tone'] ?? 'slate') { 'emerald' => 'badge-check', 'rose' => 'alert-triangle', 'amber' => 'sparkles', 'blue' => 'scan-search', default => 'circle' }">
                @if (! empty($card['items']))
                    <ul class="space-y-3">
                        @foreach ((array) $card['items'] as $item)
                            <li class="flex gap-3 text-sm leading-6 text-textSecondary">
                                <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-primary/70"></span>
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm leading-6 text-textMuted">No findings captured for this section yet.</p>
                @endif
            </x-llm-tracking.analysis-card>
        @endforeach
    </div>

    <x-llm-tracking.analysis-card
        title="Recommended actions"
        description="Action plan grouped by quick wins, strategic improvements, and measurement follow-up."
        icon="list-todo"
    >
        <div class="grid gap-6 xl:grid-cols-3">
            @foreach ([
                'quick_wins' => 'Quick wins',
                'strategic' => 'Strategic improvements',
                'measurement' => 'Measurement follow up',
            ] as $key => $label)
                <div class="rounded-lg border border-border bg-background p-4">
                    <h3 class="text-sm font-semibold text-textPrimary">{{ $label }}</h3>
                    @if (! empty(data_get($detail, 'recommended_actions.' . $key, [])))
                        <ul class="mt-3 space-y-3">
                            @foreach ((array) data_get($detail, 'recommended_actions.' . $key, []) as $item)
                                <li class="flex gap-3 text-sm leading-6 text-textSecondary">
                                    <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-primary/70"></span>
                                    <span>{{ $item }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-3 text-sm leading-6 text-textMuted">No actions suggested yet.</p>
                    @endif
                </div>
            @endforeach
        </div>
    </x-llm-tracking.analysis-card>
</div>
