<x-app.layout title="Social calendar | Argusly">
    <div class="mx-auto max-w-7xl">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Marketing calendar</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Social calendar</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Monthly and weekly planning placeholder for {{ $brand->name }}.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.badge variant="blue">{{ $items->total() }} calendar items</x-ui.badge>
                <x-ui.button href="{{ route('app.social-posts.index') }}" variant="secondary">Social posts</x-ui.button>
            </div>
        </div>

        <x-ui.card class="mt-8 p-4">
            <form method="GET" action="{{ route('app.calendar') }}" class="grid gap-3 lg:grid-cols-[1fr_1fr_1fr_1fr_auto]">
                <label>
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">View</span>
                    <select name="mode" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="month" @selected($mode === 'month')>Monthly</option>
                        <option value="week" @selected($mode === 'week')>Weekly</option>
                    </select>
                </label>
                <label>
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Type</span>
                    <select name="type" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All types</option>
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ str($type)->replace('_', ' ')->headline() }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Status</span>
                    <select name="status" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ str($status)->replace('_', ' ')->headline() }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Starts after</span>
                    <input type="date" name="starts" value="{{ $filters['starts'] ?? '' }}" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink">
                </label>
                <div class="flex items-end gap-2">
                    <x-ui.button type="submit">Filter</x-ui.button>
                    <x-ui.button href="{{ route('app.calendar') }}" variant="light">Reset</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <div class="mt-6 grid gap-6 xl:grid-cols-[1fr_0.42fr]">
            <x-ui.card class="p-5">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-base font-semibold text-ink">{{ $mode === 'week' ? 'Weekly' : 'Monthly' }} calendar placeholder</h2>
                        <p class="mt-1 text-sm text-muted">Scheduled social posts, content publishing, and campaign items appear here.</p>
                    </div>
                    <x-ui.badge>{{ $mode === 'week' ? 'Weekly' : 'Monthly' }}</x-ui.badge>
                </div>

                <div class="mt-5 overflow-hidden rounded-xl border border-line">
                    <div class="hidden grid-cols-[0.8fr_1.4fr_0.7fr_0.7fr] gap-4 border-b border-line bg-panel px-4 py-3 text-xs font-semibold uppercase tracking-[0.1em] text-muted md:grid">
                        <span>Date</span>
                        <span>Item</span>
                        <span>Type</span>
                        <span>Status</span>
                    </div>
                    @forelse ($items as $item)
                        <div class="grid gap-3 border-b border-line px-4 py-4 last:border-b-0 md:grid-cols-[0.8fr_1.4fr_0.7fr_0.7fr] md:items-center">
                            <span class="text-sm font-medium text-ink">{{ $item->start_at->format('M j, Y H:i') }}</span>
                            <span>
                                <span class="block text-sm font-semibold text-ink">{{ $item->title }}</span>
                                <span class="mt-1 block text-xs text-muted">{{ $item->description ?: $item->campaign?->name ?: 'No description' }}</span>
                            </span>
                            <span class="text-sm text-muted">{{ str($item->type)->replace('_', ' ')->headline() }}</span>
                            <span><x-ui.badge>{{ str($item->status)->replace('_', ' ')->headline() }}</x-ui.badge></span>
                        </div>
                    @empty
                        <x-dashboard.empty-state title="No calendar items" message="Schedule social posts, schedule content publishing, or add dated campaigns to populate the calendar." />
                    @endforelse
                </div>

                <div class="mt-5">{{ $items->links() }}</div>
            </x-ui.card>

            <x-ui.card class="p-5">
                <h2 class="text-base font-semibold text-ink">Upcoming</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($upcoming as $item)
                        <div class="rounded-lg border border-line bg-panel p-4">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-sm font-semibold text-ink">{{ $item->title }}</p>
                                <x-ui.badge>{{ str($item->type)->replace('_', ' ')->headline() }}</x-ui.badge>
                            </div>
                            <p class="mt-2 text-xs text-muted">{{ $item->start_at->format('M j, Y H:i') }} · {{ str($item->status)->headline() }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-muted">No upcoming calendar items yet.</p>
                    @endforelse
                </div>
            </x-ui.card>
        </div>
    </div>
</x-app.layout>
