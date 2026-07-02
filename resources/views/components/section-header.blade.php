@props([
    'title' => null,
    'description' => null,
    'eyebrow' => null,
])

<div {{ $attributes->class('pl-section-header') }}>
    <div class="min-w-0">
        @if (filled($eyebrow))
            <p class="pl-section-header__eyebrow">{{ $eyebrow }}</p>
        @endif
        @if (filled($title))
            <h2 class="pl-section-header__title">{{ $title }}</h2>
        @endif
        @if (filled($description))
            <p class="pl-section-header__description">{{ $description }}</p>
        @endif
    </div>
    @isset($actions)
        <x-action-bar>{{ $actions }}</x-action-bar>
    @endif
</div>
