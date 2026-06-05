@props([
    'label',
    'value' => null,
    'stacked' => false,
])

<div {{ $attributes->class(['pl-metadata-row', $stacked ? 'flex-col gap-1' : '']) }}>
    <div class="pl-metadata-row__label">{{ $label }}</div>
    <div class="{{ $stacked ? 'pl-metadata-row__value text-left' : 'pl-metadata-row__value' }}">
        {{ $value ?? $slot }}
    </div>
</div>
