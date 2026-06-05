@props([
    'label' => 'More actions',
    'align' => 'right',
])

@php
    $panelAlignment = $align === 'left' ? 'left-0' : 'right-0';
@endphp

<details {{ $attributes->class(['pl-action-menu']) }}>
    <summary class="pl-action-menu__trigger" aria-label="{{ $label }}" title="{{ $label }}">
        <i data-lucide="ellipsis" class="h-4 w-4"></i>
    </summary>
    <div class="pl-action-menu__panel {{ $panelAlignment }}">
        {{ $slot }}
    </div>
</details>
