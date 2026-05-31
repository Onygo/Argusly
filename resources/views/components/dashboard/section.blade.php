@props(['title', 'description' => null])

<x-ui.card {{ $attributes->merge(['class' => 'p-6']) }}>
    <div>
        <h2 class="text-base font-semibold text-ink">{{ $title }}</h2>
        @if ($description)
            <p class="mt-1 text-sm leading-6 text-muted">{{ $description }}</p>
        @endif
    </div>

    <div class="mt-5">
        {{ $slot }}
    </div>
</x-ui.card>
