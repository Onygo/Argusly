@props(['label', 'value' => null, 'empty' => 'Not available'])

<x-ui.card {{ $attributes->merge(['class' => 'p-5']) }}>
    <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ $label }}</p>
    <p class="mt-3 truncate text-lg font-semibold text-ink">{{ $value !== null && $value !== '' ? $value : $empty }}</p>
</x-ui.card>
