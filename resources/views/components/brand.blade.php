@props(['tone' => 'dark', 'href' => null])

@php
    $toneClass = $tone === 'light' ? 'text-white' : 'text-ink';
    $haloClass = $tone === 'light' ? 'bg-white/25 ring-white/35' : 'bg-blue/10 ring-blue/15';
    $signalClass = $tone === 'light' ? 'bg-white' : 'bg-blue';
    $pulseClass = $tone === 'light' ? 'bg-white/35' : 'bg-blue/35';
@endphp

<a href="{{ $href ?? url('/') }}" {{ $attributes->class('inline-flex items-center gap-3 text-[17px] font-bold tracking-tight '.$toneClass) }}>
    <span class="relative flex size-4 shrink-0 items-center justify-center">
        <span class="absolute size-4 animate-ping rounded-full {{ $pulseClass }}"></span>
        <span class="absolute size-4 rounded-full ring-1 {{ $haloClass }}"></span>
        <span class="size-2.5 rounded-full {{ $signalClass }}"></span>
    </span>
    <span>Argusly</span>
</a>
