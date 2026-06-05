<x-app.layout :title="$signal->title.' | Argusly'">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Signal detail</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ $signal->title }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">{{ $signal->summary }}</p>
            </div>
            <x-ui.button href="{{ route('app.intelligence') }}" variant="light">Back to feed</x-ui.button>
        </div>

        <div class="mt-8">
            <x-intelligence.signal-card :signal="$signal" />
        </div>
    </div>
</x-app.layout>
