@props([
    'title' => null,
    'description' => null,
    'padding' => 'default',
])

<section {{ $attributes->class([
    'pl-section-container',
    'pl-section-container--compact' => $padding === 'compact',
    'pl-section-container--flush' => $padding === 'flush',
]) }}>
    @if (filled($title) || filled($description) || isset($actions))
        <div class="pl-section-container__header">
            <x-section-header :title="$title" :description="$description">
                @isset($actions)
                    <x-slot:actions>{{ $actions }}</x-slot:actions>
                @endif
            </x-section-header>
        </div>
    @endif
    <div class="pl-section-container__body">
        {{ $slot }}
    </div>
</section>
