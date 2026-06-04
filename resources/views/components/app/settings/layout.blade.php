@props(['title', 'description' => null])

<x-app.layout :title="$title.' | Argusly'">
    <div class="w-full">
        <div>
            <p class="eyebrow">Administration</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ $title }}</h1>
            @if ($description)
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">{{ $description }}</p>
            @endif
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-[260px_minmax(0,1fr)] lg:items-start">
            <x-settings.nav />

            <div class="min-w-0">
                {{ $slot }}
            </div>
        </div>
    </div>
</x-app.layout>
