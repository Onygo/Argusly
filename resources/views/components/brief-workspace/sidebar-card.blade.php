@props([
    'title',
    'icon' => null,
    'subtitle' => null,
    'tone' => 'slate',
])

@php
    $classes = match ($tone) {
        'primary' => 'border-primary/15 bg-gradient-to-br from-primary/10 via-surface to-background',
        'warm' => 'border-amber-200/70 bg-gradient-to-br from-amber-50/80 via-surface to-background',
        'sky' => 'border-sky-200/70 bg-gradient-to-br from-sky-50/80 via-surface to-background',
        default => 'border-border bg-surface',
    };
@endphp

<section {{ $attributes->class(['rounded-lg border p-5', $classes]) }}>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                @if ($icon)
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-border bg-background text-textSecondary">
                        <i data-lucide="{{ $icon }}" class="h-4 w-4" aria-hidden="true"></i>
                    </span>
                @endif
                <div>
                    <h2 class="text-sm font-semibold text-textPrimary">{{ $title }}</h2>
                    @if ($subtitle)
                        <p class="mt-0.5 text-xs text-textSecondary">{{ $subtitle }}</p>
                    @endif
                </div>
            </div>
        </div>

        @isset($action)
            <div class="shrink-0">
                {{ $action }}
            </div>
        @endisset
    </div>

    <div class="mt-4">
        {{ $slot }}
    </div>
</section>
