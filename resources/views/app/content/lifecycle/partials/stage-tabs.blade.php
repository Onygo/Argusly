<div class="mt-6 flex flex-wrap gap-2 border-b border-border pb-4">
    @foreach ($stages as $stage)
        @php
            $summary = $stageSummaries[$stage->value] ?? ['count' => 0, 'overdue' => 0, 'due_soon' => 0];
            $isActive = $filters['stage'] === $stage->value;
            $hasIssues = $summary['overdue'] > 0;
        @endphp
        <a
            href="{{ route('app.content.lifecycle.index', array_merge($filters, ['stage' => $isActive ? '' : $stage->value])) }}"
            class="group inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition
                {{ $isActive
                    ? 'border-primary bg-primary text-textInverse shadow-sm'
                    : 'border-border bg-surface text-textSecondary hover:border-primary/50 hover:bg-surfaceSubtle hover:text-textPrimary'
                }}"
        >
            <span class="flex h-5 w-5 items-center justify-center rounded-full {{ $isActive ? 'bg-white/20' : 'bg-' . $stage->color() . '-100' }} {{ $isActive ? 'text-textInverse' : 'text-' . $stage->color() . '-700' }}">
                <i data-lucide="{{ $stage->icon() }}" class="h-3 w-3"></i>
            </span>
            <span>{{ $stage->label() }}</span>
            <span class="rounded-full {{ $isActive ? 'bg-white/20 text-textInverse' : 'bg-surfaceSubtle text-textSecondary' }} px-2 py-0.5 text-xs font-semibold">
                {{ $summary['count'] }}
            </span>
            @if ($hasIssues && ! $isActive)
                <span class="h-2 w-2 rounded-full bg-rose-500" title="{{ $summary['overdue'] }} overdue"></span>
            @endif
        </a>
    @endforeach

    {{-- Total summary --}}
    <div class="ml-auto flex items-center gap-4 text-xs text-textSecondary">
        @if (($stageSummaries['_total']['overdue'] ?? 0) > 0)
            <span class="inline-flex items-center gap-1 text-rose-600">
                <i data-lucide="alert-circle" class="h-3.5 w-3.5"></i>
                {{ $stageSummaries['_total']['overdue'] }} overdue
            </span>
        @endif
        @if (($stageSummaries['_total']['due_soon'] ?? 0) > 0)
            <span class="inline-flex items-center gap-1 text-amber-600">
                <i data-lucide="clock" class="h-3.5 w-3.5"></i>
                {{ $stageSummaries['_total']['due_soon'] }} due soon
            </span>
        @endif
        @if (($operationsSummary['at_risk_count'] ?? 0) > 0)
            <span class="inline-flex items-center gap-1 text-rose-600">
                <i data-lucide="activity" class="h-3.5 w-3.5"></i>
                {{ $operationsSummary['at_risk_count'] }} at risk
            </span>
        @endif
        <span>{{ $stageSummaries['_total']['count'] ?? 0 }} total</span>
    </div>
</div>
