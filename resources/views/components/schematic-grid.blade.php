@props([
    'variant' => 'landing',
    'schematic' => null,
    'class' => '',
])

@php
    $containerClass = trim('pl-schematic '.$class);
    $schematicName = $schematic ?? $variant;
    $blockBaseClass = 'pl-schematic-block rounded-md border border-transparent bg-surfaceMuted transition-colors transition-[border-color] duration-700 ease-in-out hover:bg-accentYellow-100 hover:border-border [&.is-active]:bg-accentYellow-100';
@endphp

<div class="{{ $containerClass }}" data-schematic="{{ $schematicName }}" aria-hidden="true">
    @if ($variant === 'product-overview')
        <div class="grid gap-3 md:grid-cols-4">
            <div class="{{ $blockBaseClass }} h-9"></div>
            <div class="{{ $blockBaseClass }} h-9"></div>
            <div class="{{ $blockBaseClass }} h-9"></div>
            <div class="{{ $blockBaseClass }} h-9"></div>
            <div class="md:col-span-3">
                <div class="{{ $blockBaseClass }} h-20"></div>
            </div>
            <div>
                <div class="{{ $blockBaseClass }} h-20"></div>
            </div>
            <div class="md:col-span-4">
                <div class="{{ $blockBaseClass }} h-14"></div>
            </div>
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-3">
            <div class="{{ $blockBaseClass }} h-10"></div>
            <div class="{{ $blockBaseClass }} h-10"></div>
            <div class="{{ $blockBaseClass }} h-10"></div>
            <div class="md:col-span-2">
                <div class="{{ $blockBaseClass }} h-28"></div>
            </div>
            <div>
                <div class="{{ $blockBaseClass }} h-28"></div>
            </div>
            <div class="md:col-span-3">
                <div class="{{ $blockBaseClass }} h-20"></div>
            </div>
        </div>
    @endif
</div>
