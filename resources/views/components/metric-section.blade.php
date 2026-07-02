@props([
    'title' => null,
    'description' => null,
])

<section {{ $attributes->class('pl-metric-section') }}>
    @if (filled($title) || filled($description) || isset($actions))
        <x-section-header :title="$title" :description="$description">
            @isset($actions)
                <x-slot:actions>{{ $actions }}</x-slot:actions>
            @endif
        </x-section-header>
    @endif
    <div class="pl-metric-section__grid">
        {{ $slot }}
    </div>
</section>
