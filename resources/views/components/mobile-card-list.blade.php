@props([
    'class' => '',
])

<div {{ $attributes->class(['pl-mobile-card-list', $class]) }}>
    {{ $slot }}
</div>
