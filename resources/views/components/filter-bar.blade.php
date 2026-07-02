@props([
    'class' => '',
    'title' => null,
    'description' => null,
])

<div {{ $attributes->class(['pl-filter-bar', $class]) }}>
    @if (filled($title) || filled($description) || isset($actions))
        <div class="pl-filter-bar__header">
            <x-section-header :title="$title" :description="$description">
                @isset($actions)
                    <x-slot:actions>{{ $actions }}</x-slot:actions>
                @endif
            </x-section-header>
        </div>
    @endif
    {{ $slot }}
</div>
