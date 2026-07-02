@props([
    'title' => 'Something went wrong',
    'description' => null,
    'icon' => 'circle-alert',
])

<div {{ $attributes->class('pl-error-state') }} role="alert">
    <span class="pl-error-state__icon">
        <i data-lucide="{{ $icon }}" class="h-5 w-5"></i>
    </span>
    <div class="min-w-0">
        <h2 class="pl-error-state__title">{{ $title }}</h2>
        @if (filled($description))
            <p class="pl-error-state__description">{{ $description }}</p>
        @endif
        @if (trim((string) $slot) !== '')
            <div class="mt-4">{{ $slot }}</div>
        @endif
    </div>
</div>
