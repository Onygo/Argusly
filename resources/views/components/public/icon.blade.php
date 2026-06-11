@props([
    'name',
    'size' => 'md',
])

@php
    $sizes = [
        'xs' => 'h-6 w-6 rounded-md [&_i]:h-3.5 [&_i]:w-3.5',
        'sm' => 'h-9 w-9 rounded-md [&_i]:h-4 [&_i]:w-4',
        'md' => 'h-10 w-10 rounded-md [&_i]:h-4.5 [&_i]:w-4.5',
        'lg' => 'h-12 w-12 rounded-md [&_i]:h-5 [&_i]:w-5',
    ];
@endphp

<span {{ $attributes->class([
    'inline-flex items-center justify-center bg-[#f6f5f2] text-publicPrimary',
    $sizes[$size] ?? $sizes['md'],
]) }}>
    <i data-lucide="{{ $name }}" class="h-4 w-4"></i>
</span>
