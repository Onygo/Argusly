@props([
    'tone' => 'neutral',
    'label' => null,
])

@php
    $toneClass = match ($tone) {
        'success', 'green', 'emerald' => 'pl-data-table-badge--success',
        'warning', 'amber', 'yellow' => 'pl-data-table-badge--warning',
        'danger', 'error', 'red', 'rose' => 'pl-data-table-badge--danger',
        'info', 'blue', 'sky', 'indigo' => 'pl-data-table-badge--info',
        default => 'pl-data-table-badge--neutral',
    };
@endphp

<span {{ $attributes->class(['pl-data-table-badge', $toneClass]) }}>
    <span class="pl-data-table-badge__label">{{ $label ?? $slot }}</span>
</span>
