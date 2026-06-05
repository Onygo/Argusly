@props([
    'class' => '',
])

<div {{ $attributes->class(['pl-filter-bar', $class]) }}>
    {{ $slot }}
</div>
