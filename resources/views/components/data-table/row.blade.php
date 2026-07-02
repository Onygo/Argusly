@props([
    'interactive' => false,
])

<tr {{ $attributes->class([
    'pl-data-table__row',
    'pl-data-table__row--interactive' => $interactive,
]) }}>
    {{ $slot }}
</tr>
