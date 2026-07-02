@props([
    'sticky' => false,
])

<thead {{ $attributes->class([
    'pl-data-table__header',
    'pl-data-table__header--sticky' => $sticky,
]) }}>
    {{ $slot }}
</thead>
