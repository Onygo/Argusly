@props([
    'label',
    'value' => null,
    'placeholder' => 'Not defined yet',
])

@php
    $slotValue = trim((string) $slot);
    $resolvedValue = $slotValue !== ''
        ? $slotValue
        : (is_string($value) ? trim($value) : (filled($value) ? (string) $value : ''));
@endphp

<div {{ $attributes->class('space-y-1.5') }}>
    <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-textSecondary">{{ $label }}</div>
    @if ($resolvedValue !== '')
        <div class="text-sm font-medium leading-6 text-textPrimary">{{ $resolvedValue }}</div>
    @else
        <div class="text-sm italic text-textSecondary">{{ $placeholder }}</div>
    @endif
</div>
