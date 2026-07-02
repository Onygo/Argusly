@props([
    'title' => null,
    'eyebrow' => null,
    'icon' => null,
])

<header {{ $attributes->class('pl-page-header') }}>
    <div class="min-w-0">
        @if (filled($eyebrow))
            <p class="pl-page-header__eyebrow">{{ $eyebrow }}</p>
        @endif
        <div class="flex min-w-0 items-center gap-3">
            @if (filled($icon))
                <span class="pl-page-header__icon">
                    <i data-lucide="{{ $icon }}" class="h-5 w-5"></i>
                </span>
            @endif
            @if (filled($title))
                <h1 class="pl-page-header__title">{{ $title }}</h1>
            @endif
        </div>
        @isset($description)
            <div class="mt-2">
                <x-page-description>{{ $description }}</x-page-description>
            </div>
        @endif
    </div>

    @isset($actions)
        <x-action-bar>{{ $actions }}</x-action-bar>
    @endif
</header>
