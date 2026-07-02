@props([
    'label',
    'value' => null,
    'helper' => null,
    'icon' => null,
    'tone' => 'neutral',
])

<article {{ $attributes->class(['pl-metric-card', 'pl-metric-card--'.$tone]) }}>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="pl-metric-card__label">{{ $label }}</p>
            @if (! is_null($value))
                <p class="pl-metric-card__value">{{ $value }}</p>
            @endif
        </div>
        @if (filled($icon))
            <span class="pl-metric-card__icon">
                <i data-lucide="{{ $icon }}" class="h-4 w-4"></i>
            </span>
        @endif
    </div>
    @if (filled($helper) || trim((string) $slot) !== '')
        <div class="pl-metric-card__helper">
            {{ filled($helper) ? $helper : $slot }}
        </div>
    @endif
</article>
