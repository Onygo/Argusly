<x-app.layout title="Source Sync History | Argusly">
    <div class="mx-auto max-w-7xl">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Source registry</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Sync history</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Planned, completed, failed or skipped source sync records. No external sync implementation is active.</p>
            </div>
            <x-ui.button href="{{ route('app.sources.index') }}" variant="secondary">Back to sources</x-ui.button>
        </div>

        <div class="mt-8">
            <x-dashboard.section title="Sync records">
                @if ($syncs->isEmpty())
                    <x-dashboard.empty-state title="No sync history" message="Source sync records will appear here once planned or future workers write them." />
                @else
                    <div class="space-y-3">
                        @foreach ($syncs as $sync)
                            <a href="{{ route('app.sources.show', $sync->source) }}" class="block rounded-lg border border-line bg-panel p-4 hover:bg-white">
                                <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="truncate text-sm font-semibold text-ink">{{ $sync->source->name }}</p>
                                            <x-ui.badge>{{ str($sync->status)->headline() }}</x-ui.badge>
                                        </div>
                                        <p class="mt-2 text-xs text-muted">{{ $sync->started_at?->format('M j, Y H:i') ?? 'Not started' }} · {{ $sync->completed_at?->format('M j, Y H:i') ?? 'Not completed' }}</p>
                                    </div>
                                    <div class="shrink-0 text-sm font-semibold text-ink">{{ $sync->records_found ?? 0 }} records</div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                    <div class="mt-5">{{ $syncs->links() }}</div>
                @endif
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
