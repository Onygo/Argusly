@props(['title', 'description', 'icon' => ''])

<div class="bg-white p-7 transition-colors hover:bg-panel">
    <div class="mb-5 text-ink">
        <x-app.icon :name="$icon" class="size-5" />
    </div>
    <h3 class="text-base font-semibold text-ink">{{ $title }}</h3>
    <p class="mt-2 text-sm leading-6 text-muted">{{ $description }}</p>
</div>
