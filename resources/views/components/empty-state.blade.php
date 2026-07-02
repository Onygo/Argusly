@props([
    'title' => 'No results yet',
    'description' => null,
    'icon' => 'inbox',
])

<div {{ $attributes->class('pl-empty-state') }}>
    <span class="pl-empty-state__icon">
        <i data-lucide="{{ $icon }}" class="h-5 w-5"></i>
    </span>
    <h2 class="pl-empty-state__title">{{ $title }}</h2>
    @if (filled($description))
        <p class="pl-empty-state__description">{{ $description }}</p>
    @endif
    @if (trim((string) $slot) !== '')
        <div class="pl-empty-state__actions">{{ $slot }}</div>
    @endif
</div>
