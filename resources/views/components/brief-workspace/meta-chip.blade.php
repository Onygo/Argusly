@props([
    'label',
    'icon' => null,
    'tone' => 'slate',
])

@php
    $classes = match ($tone) {
        'primary' => 'border-primary/15 bg-primary/8 text-textPrimary',
        'warm' => 'border-orange-200/80 bg-orange-50 text-orange-800',
        'sky' => 'border-sky-200 bg-sky-50 text-sky-800',
        'emerald' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        default => 'border-border bg-background text-textSecondary',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium', $classes]) }}>
    @if ($icon)
        <i data-lucide="{{ $icon }}" class="h-3.5 w-3.5 shrink-0" aria-hidden="true"></i>
    @endif
    <span>{{ $label }}</span>
</span>
