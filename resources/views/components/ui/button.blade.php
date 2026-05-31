@props([
    'href' => null,
    'variant' => 'primary',
    'size' => 'md',
])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-full font-semibold transition focus:outline-none focus:ring-2 focus:ring-blue/20 disabled:pointer-events-none disabled:opacity-50';
    $variants = [
        'primary' => 'bg-ink text-white hover:bg-black',
        'secondary' => 'border border-line bg-white text-ink hover:border-slate-300 hover:bg-panel',
        'ghost' => 'text-muted hover:text-ink',
        'light' => 'bg-white text-ink hover:bg-slate-100',
    ];
    $sizes = [
        'sm' => 'h-8 px-3 text-xs',
        'md' => 'h-10 px-4 text-sm',
        'lg' => 'h-12 px-5 text-sm',
    ];
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $base.' '.$variants[$variant].' '.$sizes[$size]]) }}>
        {{ $slot }}
    </a>
@else
    <button {{ $attributes->merge(['class' => $base.' '.$variants[$variant].' '.$sizes[$size]]) }}>
        {{ $slot }}
    </button>
@endif
