@props([
    'label',
    'value',
    'context' => null,
    'helper' => null,
    'tone' => 'slate',
])

<div class="rounded-lg border border-border bg-surface p-4">
    @php
        $contextClass = match ($tone) {
            'emerald' => 'text-emerald-700',
            'amber' => 'text-amber-700',
            'rose' => 'text-rose-700',
            'blue' => 'text-sky-700',
            default => 'text-textSecondary',
        };
    @endphp

    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-textMuted">{{ $label }}</p>
    <p class="mt-2 text-2xl font-semibold text-textPrimary">{{ $value }}</p>

    @if ($context)
        <p class="mt-2 text-xs font-medium {{ $contextClass }}">{{ $context }}</p>
    @endif

    @if ($helper)
        <p class="mt-3 text-sm leading-6 text-textSecondary">{{ $helper }}</p>
    @endif
</div>
