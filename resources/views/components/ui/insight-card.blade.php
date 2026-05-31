@props(['signal', 'title', 'description', 'impact', 'action'])

<x-ui.card class="p-5">
    <div class="flex items-center justify-between gap-3 text-xs text-muted">
        <span>{{ $signal }}</span>
        <span class="font-semibold text-blue">{{ $impact }}</span>
    </div>
    <h3 class="mt-4 text-base font-semibold text-ink">{{ $title }}</h3>
    <p class="mt-2 text-sm leading-6 text-muted">{{ $description }}</p>
    <div class="mt-5 flex items-center justify-between">
        <x-ui.button size="sm">{{ $action }}</x-ui.button>
        <a href="#" class="text-xs font-semibold text-muted hover:text-ink">Details</a>
    </div>
</x-ui.card>
