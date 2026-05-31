<x-app.layout title="Search Performance | Argusly">
    <div class="mx-auto max-w-7xl">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Search performance foundation</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Search performance</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Search Console placeholders for {{ $brand->name }} content lifecycle scoring, AI visibility correlation, topic authority, recommendations and campaign reporting.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.badge variant="blue">{{ $sites->count() }} Search Console sites</x-ui.badge>
                <x-ui.button href="{{ route('settings.integrations.search-console') }}" variant="secondary">Search Console settings</x-ui.button>
            </div>
        </div>

        <div class="mt-8 grid gap-4 md:grid-cols-4">
            <x-ui.card class="p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Clicks</p>
                <p class="mt-2 text-2xl font-semibold text-ink">{{ number_format((int) ($totals->clicks_total ?? 0)) }}</p>
            </x-ui.card>
            <x-ui.card class="p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Impressions</p>
                <p class="mt-2 text-2xl font-semibold text-ink">{{ number_format((int) ($totals->impressions_total ?? 0)) }}</p>
            </x-ui.card>
            <x-ui.card class="p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Avg CTR</p>
                <p class="mt-2 text-2xl font-semibold text-ink">{{ $totals->ctr_average !== null ? number_format(((float) $totals->ctr_average) * 100, 2).'%' : '0.00%' }}</p>
            </x-ui.card>
            <x-ui.card class="p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Avg position</p>
                <p class="mt-2 text-2xl font-semibold text-ink">{{ $totals->position_average !== null ? number_format((float) $totals->position_average, 2) : 'n/a' }}</p>
            </x-ui.card>
        </div>

        <x-ui.card class="mt-6 p-5">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                <div>
                    <h2 class="text-base font-semibold text-ink">Search Console data pipeline</h2>
                    <p class="mt-1 text-sm text-muted">Synced Search Analytics data powers lifecycle, authority and recommendation experiments.</p>
                </div>
                <x-ui.badge>{{ $latestSnapshots->count() }} recent snapshots</x-ui.badge>
            </div>

            <div class="mt-5 overflow-hidden rounded-lg border border-line">
                <div class="hidden grid-cols-[1.1fr_0.9fr_0.7fr_0.6fr_0.6fr_0.6fr_0.6fr] gap-3 border-b border-line bg-panel px-4 py-3 text-xs font-semibold uppercase tracking-[0.1em] text-muted lg:grid">
                    <span>Content / Query</span>
                    <span>Page</span>
                    <span>Date</span>
                    <span>Clicks</span>
                    <span>Impr.</span>
                    <span>CTR</span>
                    <span>Position</span>
                </div>
                @forelse ($latestSnapshots as $snapshot)
                    <div class="grid gap-3 border-b border-line px-4 py-3 last:border-b-0 lg:grid-cols-[1.1fr_0.9fr_0.7fr_0.6fr_0.6fr_0.6fr_0.6fr] lg:items-center">
                        <div>
                            <p class="text-sm font-semibold text-ink">{{ $snapshot->contentAsset?->title ?? $snapshot->searchConsoleSite?->site_url ?? 'Search snapshot' }}</p>
                            <p class="mt-1 text-xs text-muted">{{ $snapshot->query ?? 'No query dimension' }}</p>
                        </div>
                        <p class="truncate text-sm text-muted">{{ $snapshot->page ?? $snapshot->searchConsoleSite?->site_url }}</p>
                        <p class="text-sm text-muted">{{ $snapshot->date?->toFormattedDateString() }}</p>
                        <p class="text-sm text-muted">{{ number_format((int) $snapshot->clicks) }}</p>
                        <p class="text-sm text-muted">{{ number_format((int) $snapshot->impressions) }}</p>
                        <p class="text-sm text-muted">{{ $snapshot->ctr !== null ? number_format(((float) $snapshot->ctr) * 100, 2).'%' : 'n/a' }}</p>
                        <p class="text-sm text-muted">{{ $snapshot->position ?? 'n/a' }}</p>
                    </div>
                @empty
                    <x-dashboard.empty-state title="No Search Console snapshots yet" message="Run Search Console sync to populate query, page, country and device performance." />
                @endforelse
            </div>
        </x-ui.card>

        <div class="mt-6 grid gap-4 lg:grid-cols-3">
            <x-ui.card class="p-5">
                <h2 class="text-sm font-semibold text-ink">Lifecycle scoring</h2>
                <p class="mt-2 text-sm leading-6 text-muted">Clicks, impressions, CTR and position are ready to become freshness, decay and opportunity inputs.</p>
            </x-ui.card>
            <x-ui.card class="p-5">
                <h2 class="text-sm font-semibold text-ink">AI visibility correlation</h2>
                <p class="mt-2 text-sm leading-6 text-muted">Search demand can later be compared with visibility checks and answer readiness signals for the same topics.</p>
            </x-ui.card>
            <x-ui.card class="p-5">
                <h2 class="text-sm font-semibold text-ink">Topic authority</h2>
                <p class="mt-2 text-sm leading-6 text-muted">Query and page snapshots are structured for authority rollups, recommendations and campaign reporting.</p>
            </x-ui.card>
        </div>
    </div>
</x-app.layout>
