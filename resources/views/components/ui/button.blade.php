@props([
    'href' => null,
    'variant' => 'primary',
    'size' => 'md',
    'shape' => 'md',
])

@php
    $base = 'inline-flex items-center justify-center gap-2 font-semibold transition focus:outline-none focus:ring-2 focus:ring-blue/20 disabled:pointer-events-none disabled:opacity-50';
    $variants = [
        'primary' => 'bg-blue text-white hover:bg-blue/90',
        'secondary' => 'border border-line bg-white text-ink hover:border-slate-300 hover:bg-panel',
        'ghost' => 'text-muted hover:bg-panel hover:text-ink',
        'light' => 'border border-line bg-white text-ink hover:bg-panel',
        'dark' => 'bg-ink text-white hover:bg-ink/90',
        'glass' => 'border border-white/25 bg-white/10 text-white hover:bg-white/20',
    ];
    $sizes = [
        'sm' => 'h-8 px-3 text-xs',
        'md' => 'h-10 px-4 text-sm',
        'lg' => 'h-12 px-5 text-sm',
    ];
    $shapes = [
        'md' => 'rounded-md',
        'lg' => 'rounded-lg',
        'pill' => 'rounded-full',
    ];
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $base.' '.$shapes[$shape].' '.$variants[$variant].' '.$sizes[$size]]) }}>
        {{ $slot }}
    </a>
@else
    <button {{ $attributes->merge(['class' => $base.' '.$shapes[$shape].' '.$variants[$variant].' '.$sizes[$size]]) }}>
        {{ $slot }}
    </button>
@endif
