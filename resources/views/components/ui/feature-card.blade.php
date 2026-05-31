@props(['title', 'description', 'icon' => ''])

<x-ui.card class="p-6">
    <div class="mb-5 grid size-8 place-items-center rounded-full border border-line bg-panel text-sm font-bold text-ink">{{ $icon }}</div>
    <h3 class="text-base font-semibold text-ink">{{ $title }}</h3>
    <p class="mt-2 text-sm leading-6 text-muted">{{ $description }}</p>
</x-ui.card>
