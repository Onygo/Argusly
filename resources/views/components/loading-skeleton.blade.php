@props([
    'rows' => 3,
])

<div {{ $attributes->class('pl-loading-skeleton') }} aria-busy="true" aria-live="polite">
    @for ($i = 0; $i < (int) $rows; $i++)
        <span class="pl-loading-skeleton__row" style="width: {{ max(42, 100 - ($i * 13)) }}%"></span>
    @endfor
</div>
