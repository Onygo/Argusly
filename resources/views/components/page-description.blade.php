@props([
    'size' => 'md',
])

<p {{ $attributes->class([
    'pl-page-description',
    'pl-page-description--sm' => $size === 'sm',
    'pl-page-description--lg' => $size === 'lg',
]) }}>
    {{ $slot }}
</p>
