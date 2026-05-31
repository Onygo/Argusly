@props(['variant' => 'default'])

@php
    $classes = [
        'default' => 'border-line bg-white text-muted',
        'blue' => 'border-blue/15 bg-blue/5 text-blue',
        'dark' => 'border-ink bg-ink text-white',
        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-semibold '.$classes[$variant]]) }}>
    {{ $slot }}
</span>
