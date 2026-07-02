@props([
    'align' => 'start',
])

<div {{ $attributes->class([
    'pl-action-bar',
    'pl-action-bar--end' => $align === 'end',
    'pl-action-bar--between' => $align === 'between',
]) }}>
    {{ $slot }}
</div>
