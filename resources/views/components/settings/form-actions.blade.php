@props([
    'align' => 'end',
])

@php
    $alignmentClass = match ($align) {
        'start' => 'justify-start',
        'between' => 'justify-between',
        default => 'justify-end',
    };
@endphp

<div {{ $attributes->class(['mt-4 flex flex-wrap items-center gap-2 border-t border-border pt-3', $alignmentClass]) }}>
    {{ $slot }}
</div>
