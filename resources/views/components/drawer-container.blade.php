@props([
    'open' => false,
    'title' => null,
    'description' => null,
])

<aside
    {{ $attributes->class(['pl-drawer-container', 'pl-drawer-container--open' => $open])->merge(['data-drawer-container' => true]) }}
    @if (! $open) hidden @endif
>
    <div class="pl-drawer-container__panel">
        @if (filled($title) || filled($description) || isset($actions))
            <div class="pl-drawer-container__header">
                <x-section-header :title="$title" :description="$description">
                    @isset($actions)
                        <x-slot:actions>{{ $actions }}</x-slot:actions>
                    @endif
                </x-section-header>
            </div>
        @endif
        <div class="pl-drawer-container__body">
            {{ $slot }}
        </div>
    </div>
</aside>
