@props([
    'label' => 'Row actions',
    'align' => 'end',
])

<div {{ $attributes->class([
    'pl-data-table__actions',
    'pl-data-table__actions--start' => $align === 'start',
    'pl-data-table__actions--center' => $align === 'center',
]) }} aria-label="{{ $label }}">
    {{ $slot }}
</div>
