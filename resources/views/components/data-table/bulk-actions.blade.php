@props([
    'label' => 'Bulk actions',
])

<div {{ $attributes->class('pl-data-table-bulk-actions') }} aria-label="{{ $label }}">
    {{ $slot }}
</div>
