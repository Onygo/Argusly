@php
    $current = $current ?? ['text' => '', 'items' => []];
    $proposed = $proposed ?? ['text' => '', 'items' => []];
@endphp

<div class="grid gap-3 lg:grid-cols-2">
    <div class="rounded-lg border border-border bg-background p-4">
        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-textMuted">Current</p>
        @if (($current['text'] ?? '') !== '')
            <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $current['text'] }}</p>
        @endif
        <div class="mt-2">
            <x-workspace-intelligence.list :items="$current['items'] ?? []" empty="No approved data yet." />
        </div>
    </div>

    <div class="rounded-lg border border-primary/20 bg-primarySoftBg p-4">
        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-primary">Proposed</p>
        @if (($proposed['text'] ?? '') !== '')
            <p class="mt-2 text-sm leading-6 text-textPrimary">{{ $proposed['text'] }}</p>
        @endif
        <div class="mt-2">
            <x-workspace-intelligence.list :items="$proposed['items'] ?? []" empty="No proposed details." />
        </div>
    </div>
</div>
