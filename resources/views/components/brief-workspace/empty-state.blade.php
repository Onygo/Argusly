@props([
    'message' => 'Not defined yet',
    'actionHref' => null,
    'actionLabel' => 'Edit brief',
    'tone' => 'slate',
])

@php
    $classes = match ($tone) {
        'warm' => 'border-amber-200/80 bg-amber-50/70',
        'primary' => 'border-primary/15 bg-primary/6',
        default => 'border-border bg-surfaceSubtle/70',
    };
@endphp

<div {{ $attributes->class(['rounded-lg border border-dashed px-4 py-4', $classes]) }}>
    <p class="text-sm italic text-textSecondary">{{ $message }}</p>
    @if ($actionHref)
        <a href="{{ $actionHref }}" class="mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-textPrimary hover:underline">
            <i data-lucide="square-pen" class="h-3.5 w-3.5" aria-hidden="true"></i>
            <span>{{ $actionLabel }}</span>
        </a>
    @endif
</div>
