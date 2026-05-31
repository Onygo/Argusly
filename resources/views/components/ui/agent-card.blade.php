@props(['name', 'status', 'action', 'impact'])

<x-ui.card class="p-5">
    <div class="flex items-start justify-between gap-3">
        <h3 class="text-sm font-semibold text-ink">{{ $name }}</h3>
        <x-ui.badge variant="blue">{{ $status }}</x-ui.badge>
    </div>
    <p class="mt-5 text-xs font-semibold uppercase tracking-[0.1em] text-muted">Last action</p>
    <p class="mt-1 text-sm text-ink">{{ $action }}</p>
    <p class="mt-4 text-xs font-semibold uppercase tracking-[0.1em] text-blue">Impact</p>
    <p class="mt-1 text-sm font-semibold text-blue">{{ $impact }}</p>
</x-ui.card>
