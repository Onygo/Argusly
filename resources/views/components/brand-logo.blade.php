@props([
    'label' => \App\Support\Brand::product(),
    'showText' => true,
    'size' => 'default',
    'tone' => 'default',
    'textClass' => 'pl-brand-logo-text text-sm text-textPrimary',
])

@php
    $dotClass = $size === 'lg' ? 'h-5 w-5' : 'h-4 w-4';
    $innerDotClass = $size === 'lg' ? 'h-3 w-3' : 'h-2.5 w-2.5';
    $pingClass = $tone === 'inverse' ? 'bg-white/35' : 'bg-publicPrimary/35';
    $ringClass = $tone === 'inverse' ? 'bg-white/10 ring-white/35' : 'bg-publicPrimary/10 ring-publicPrimary/15';
    $fillClass = $tone === 'inverse' ? 'bg-white' : 'bg-publicPrimary';
@endphp

<span {{ $attributes->class('inline-flex items-center gap-3') }}>
    <span class="relative flex {{ $dotClass }} shrink-0 items-center justify-center" aria-hidden="true">
        <span class="absolute {{ $dotClass }} animate-ping rounded-full {{ $pingClass }}"></span>
        <span class="absolute {{ $dotClass }} rounded-full ring-1 {{ $ringClass }}"></span>
        <span class="{{ $innerDotClass }} rounded-full {{ $fillClass }}"></span>
    </span>
    @if ($showText)
        <span class="{{ $textClass }}">{{ $label }}</span>
    @endif
</span>
