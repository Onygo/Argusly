@props(['label', 'value' => null, 'empty' => 'Not set'])

<div {{ $attributes->merge(['class' => 'rounded-md border border-line bg-panel p-4']) }}>
    <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ $label }}</p>
    <p class="mt-2 break-words text-sm font-semibold text-ink">{{ filled($value) ? $value : $empty }}</p>
</div>
