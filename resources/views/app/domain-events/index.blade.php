<x-app.layout title="Domain Events | Argusly">
    <div class="mx-auto max-w-7xl">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Admin</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Domain events</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Durable product events for projections, automation and product history. Activity logs remain the human-readable audit trail.</p>
            </div>
            <x-ui.badge variant="blue">{{ $events->total() }} events</x-ui.badge>
        </div>

        <div class="mt-8">
            <x-dashboard.section title="Recent events" description="Filter product events in the current tenant context.">
                <form method="GET" action="{{ route('app.domain-events') }}" class="mb-5 grid gap-3 md:grid-cols-[1fr_auto]">
                    <select name="event_type" class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All event types</option>
                        @foreach ($eventTypes as $eventType)
                            <option value="{{ $eventType }}" @selected(($filters['event_type'] ?? '') === $eventType)>{{ str($eventType)->headline() }}</option>
                        @endforeach
                    </select>
                    <x-ui.button type="submit" variant="secondary">Filter</x-ui.button>
                </form>

                @if ($events->isEmpty())
                    <x-dashboard.empty-state title="No domain events" message="Product events will appear here as tenant workflows run." />
                @else
                    <div class="space-y-3">
                        @foreach ($events as $event)
                            <article class="rounded-lg border border-line bg-panel p-4">
                                <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <x-ui.badge variant="blue">{{ str($event->event_type)->headline() }}</x-ui.badge>
                                            @if ($event->brand)
                                                <x-ui.badge>{{ $event->brand->name }}</x-ui.badge>
                                            @else
                                                <x-ui.badge>Account</x-ui.badge>
                                            @endif
                                            @if ($event->processed_at)
                                                <x-ui.badge variant="success">Processed</x-ui.badge>
                                            @else
                                                <x-ui.badge>Unprocessed</x-ui.badge>
                                            @endif
                                        </div>
                                        <p class="mt-2 text-sm font-semibold text-ink">{{ $event->subject_type ? class_basename($event->subject_type).' #'.$event->subject_id : 'No subject' }}</p>
                                        @if ($event->payload)
                                            <pre class="mt-3 max-h-36 overflow-auto rounded-lg border border-line bg-white p-3 text-xs leading-5 text-muted">{{ json_encode($event->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        @endif
                                    </div>
                                    <div class="shrink-0 text-left md:text-right">
                                        <time class="text-xs text-muted" datetime="{{ $event->occurred_at?->toIso8601String() }}">{{ $event->occurred_at?->format('M j, Y H:i') }}</time>
                                        <p class="mt-2 text-xs text-muted">{{ $event->actor?->name ?? 'System' }}</p>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>

                    <div class="mt-5">{{ $events->links() }}</div>
                @endif
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
