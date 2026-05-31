<x-app.layout title="{{ $item->title }} | Argusly">
    <div class="mx-auto max-w-4xl">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Calendar detail</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ $item->title }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">{{ $item->description ?: $item->campaign?->name ?: 'No description provided.' }}</p>
            </div>
            <x-ui.button href="{{ route('app.calendar') }}" variant="secondary">Back to calendar</x-ui.button>
        </div>

        <div class="mt-8 grid gap-6 md:grid-cols-2">
            <x-dashboard.section title="Schedule" description="Timing and operating state.">
                <dl class="space-y-4 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted">Starts</dt>
                        <dd class="font-medium text-ink">{{ $item->start_at->format('M j, Y H:i') }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted">Ends</dt>
                        <dd class="font-medium text-ink">{{ $item->end_at?->format('M j, Y H:i') ?? 'Not set' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted">Type</dt>
                        <dd><x-ui.badge>{{ str($item->type)->replace('_', ' ')->headline() }}</x-ui.badge></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted">Status</dt>
                        <dd><x-ui.badge>{{ str($item->status)->replace('_', ' ')->headline() }}</x-ui.badge></dd>
                    </div>
                </dl>
            </x-dashboard.section>

            <x-dashboard.section title="Ownership" description="Tenant, campaign and assignee context.">
                <dl class="space-y-4 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted">Brand</dt>
                        <dd class="font-medium text-ink">{{ $item->brand?->name ?? 'Account-wide' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted">Campaign</dt>
                        <dd class="font-medium text-ink">{{ $item->campaign?->name ?? 'No campaign' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted">Assignee</dt>
                        <dd class="font-medium text-ink">{{ $item->assignee?->name ?? 'Unassigned' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted">Related</dt>
                        <dd class="font-medium text-ink">{{ $item->related_type ? class_basename($item->related_type).' #'.$item->related_id : 'None' }}</dd>
                    </div>
                </dl>
            </x-dashboard.section>
        </div>

        <div class="mt-6">
            <x-dashboard.section title="Metadata" description="Calendar projection data for the related marketing record.">
                @if ($item->metadata)
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        @foreach ($item->metadata as $key => $value)
                            <div class="rounded-lg border border-line bg-panel p-3">
                                <dt class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ str($key)->replace('_', ' ')->headline() }}</dt>
                                <dd class="mt-1 break-words font-medium text-ink">{{ is_scalar($value) ? $value : json_encode($value) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @else
                    <x-dashboard.empty-state title="No metadata" message="This calendar item has no projection metadata." />
                @endif
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
