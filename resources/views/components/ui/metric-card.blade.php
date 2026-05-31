@props(['label', 'value', 'change' => null, 'tone' => 'up'])

<x-ui.card class="p-4">
    <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ $label }}</p>
    <div class="mt-3 flex items-end justify-between gap-3">
        <p class="text-2xl font-semibold tracking-tight text-ink">{{ $value }}</p>
        @if ($change)
            <span class="text-xs font-semibold {{ $tone === 'up' ? 'text-blue' : 'text-slate-500' }}">{{ $change }}</span>
        @endif
    </div>
</x-ui.card>
