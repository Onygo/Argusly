{{-- Content Load Stats --}}
<div class="mb-6 grid gap-4 sm:grid-cols-3">
    {{-- Planned Articles --}}
    <div class="rounded-lg border border-border bg-surface p-4">
        <p class="text-xs font-medium uppercase tracking-wider text-textMuted">Gepland</p>
        <p class="mt-1 text-2xl font-semibold text-textPrimary">{{ $stats['planned_count'] }}</p>
        <p class="mt-1 text-xs text-textSecondary">
            {{ $stats['planned_count'] === 1 ? 'artikel' : 'artikelen' }} in deze periode
        </p>
    </div>

    {{-- Planning Gaps --}}
    <div class="rounded-lg border border-border bg-surface p-4">
        <p class="text-xs font-medium uppercase tracking-wider text-textMuted">Gaten in planning</p>
        <p @class([
            'mt-1 text-2xl font-semibold',
            'text-amber-600' => $stats['empty_days'] > 3,
            'text-textPrimary' => $stats['empty_days'] <= 3,
        ])>
            {{ $stats['empty_days'] }}
        </p>
        <p class="mt-1 text-xs text-textSecondary">
            lege {{ $stats['empty_days'] === 1 ? 'dag' : 'dagen' }} zonder content
        </p>
    </div>

    {{-- Suggestion --}}
    <div @class([
        'rounded-lg border p-4',
        'border-amber-200 bg-amber-50' => $stats['suggestion'] !== null,
        'border-emerald-200 bg-emerald-50' => $stats['suggestion'] === null,
    ])>
        <p @class([
            'text-xs font-medium uppercase tracking-wider',
            'text-amber-700' => $stats['suggestion'] !== null,
            'text-emerald-700' => $stats['suggestion'] === null,
        ])>Suggestie</p>
        @if ($stats['suggestion'])
            <p class="mt-1 text-sm text-amber-800">{{ $stats['suggestion'] }}</p>
            @if ($stats['week_empty_days'] > 0 && isset($generateChainedUrl))
                <a
                    href="{{ $generateChainedUrl }}"
                    class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-amber-700 hover:text-amber-900"
                >
                    <i data-lucide="sparkles" class="h-3 w-3"></i>
                    Genereer content suggesties
                </a>
            @endif
        @else
            <p class="mt-1 text-sm text-emerald-700">Je planning ziet er goed uit!</p>
        @endif
    </div>
</div>
