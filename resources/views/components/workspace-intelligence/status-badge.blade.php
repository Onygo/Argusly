@props([
    'label',
    'tone' => 'slate',
    'icon' => null,
])

@php
    $palette = match ($tone) {
        'emerald' => 'border-emerald-500/20 bg-emerald-500/10 text-emerald-700',
        'amber' => 'border-amber-500/20 bg-amber-500/10 text-amber-700',
        'rose' => 'border-rose-500/20 bg-rose-500/10 text-rose-700',
        'blue' => 'border-sky-500/20 bg-sky-500/10 text-sky-700',
        default => 'border-border bg-surfaceSubtle text-textSecondary',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium', $palette]) }}>
    @if ($icon)
        <i data-lucide="{{ $icon }}" class="h-3.5 w-3.5 shrink-0" aria-hidden="true"></i>
    @endif
    <span>{{ $label }}</span>
</span>
