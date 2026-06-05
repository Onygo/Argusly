@props([
    'label',
    'tone' => 'slate',
    'source' => false,
    'href' => null,
    'tooltip' => null,
])

@php
    $toneClasses = match ($tone) {
        'source', 'green', 'emerald' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'variant', 'sky', 'blue' => 'border-sky-200 bg-sky-50 text-sky-700',
        'amber', 'yellow', 'warning' => 'border-amber-200 bg-amber-50 text-amber-800',
        'rose', 'red', 'danger' => 'border-rose-200 bg-rose-50 text-rose-700',
        default => 'border-border bg-surfaceSubtle text-textSecondary',
    };

    $tag = $href ? 'a' : 'span';
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" @endif
    @if ($tooltip) title="{{ $tooltip }}" @endif
    {{ $attributes->class(['pl-badge', $toneClasses]) }}
>
    <span class="pl-badge__label">{{ $label }}</span>
    @if ($source)
        <span class="rounded-full bg-white/70 px-1 py-0.5 text-[9px] uppercase tracking-wide">Src</span>
    @endif
</{{ $tag }}>
