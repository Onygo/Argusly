<x-app.layout title="Analytics | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Analytics foundation</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Analytics</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">GA4 placeholders for {{ $brand->name }} content performance, lifecycle scoring, recommendations and campaign reporting.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.badge variant="blue">{{ $properties->count() }} GA4 properties</x-ui.badge>
                <x-ui.button href="{{ route('settings.integrations.google-analytics') }}" variant="secondary">GA4 settings</x-ui.button>
            </div>
        </div>

        <div class="mt-8 grid gap-4 md:grid-cols-4">
            <x-ui.card class="p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Sessions</p>
                <p class="mt-2 text-2xl font-semibold text-ink">{{ number_format((int) ($totals->sessions_total ?? 0)) }}</p>
            </x-ui.card>
            <x-ui.card class="p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Users</p>
                <p class="mt-2 text-2xl font-semibold text-ink">{{ number_format((int) ($totals->users_total ?? 0)) }}</p>
            </x-ui.card>
            <x-ui.card class="p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Pageviews</p>
                <p class="mt-2 text-2xl font-semibold text-ink">{{ number_format((int) ($totals->pageviews_total ?? 0)) }}</p>
            </x-ui.card>
            <x-ui.card class="p-5">
                <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Conversions</p>
                <p class="mt-2 text-2xl font-semibold text-ink">{{ number_format((int) ($totals->conversions_total ?? 0)) }}</p>
            </x-ui.card>
        </div>

        <x-ui.card class="mt-6 p-5">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                <div>
                    <h2 class="text-base font-semibold text-ink">GA4 data pipeline placeholder</h2>
                    <p class="mt-1 text-sm text-muted">OAuth and live API sync are not implemented yet. Seeded or imported snapshots can already power UI, lifecycle and recommendation experiments.</p>
                </div>
                <x-ui.badge>{{ $latestSnapshots->count() }} recent snapshots</x-ui.badge>
            </div>

            <div class="mt-5 overflow-hidden rounded-md border border-line">
                <div class="hidden grid-cols-[1.2fr_0.8fr_0.6fr_0.6fr_0.6fr_0.6fr] gap-3 border-b border-line bg-panel px-4 py-3 text-xs font-semibold uppercase tracking-[0.1em] text-muted md:grid">
                    <span>Content / Property</span>
                    <span>Date</span>
                    <span>Sessions</span>
                    <span>Users</span>
                    <span>Views</span>
                    <span>Engagement</span>
                </div>
                @forelse ($latestSnapshots as $snapshot)
                    <div class="grid gap-3 border-b border-line px-4 py-3 last:border-b-0 md:grid-cols-[1.2fr_0.8fr_0.6fr_0.6fr_0.6fr_0.6fr] md:items-center">
                        <div>
                            <p class="text-sm font-semibold text-ink">{{ $snapshot->contentAsset?->title ?? $snapshot->ga4Property?->display_name ?? 'Property snapshot' }}</p>
                            <p class="mt-1 text-xs text-muted">{{ $snapshot->ga4Property?->display_name }}</p>
                        </div>
                        <p class="text-sm text-muted">{{ $snapshot->date?->toFormattedDateString() }}</p>
                        <p class="text-sm text-muted">{{ number_format((int) $snapshot->sessions) }}</p>
                        <p class="text-sm text-muted">{{ number_format((int) $snapshot->users) }}</p>
                        <p class="text-sm text-muted">{{ number_format((int) $snapshot->pageviews) }}</p>
                        <p class="text-sm text-muted">{{ $snapshot->engagement_rate !== null ? $snapshot->engagement_rate.'%' : 'n/a' }}</p>
                    </div>
                @empty
                    <x-dashboard.empty-state title="No GA4 snapshots yet" message="GA4 metric snapshots will appear here after OAuth and sync jobs are added." />
                @endforelse
            </div>
        </x-ui.card>

        <div class="mt-6 grid gap-4 lg:grid-cols-3">
            <x-ui.card class="p-5">
                <h2 class="text-sm font-semibold text-ink">Content performance</h2>
                <p class="mt-2 text-sm leading-6 text-muted">Snapshots are scoped to content assets so future dashboards can compare sessions, users, views, engagement and conversions per article or page.</p>
            </x-ui.card>
            <x-ui.card class="p-5">
                <h2 class="text-sm font-semibold text-ink">Lifecycle scoring</h2>
                <p class="mt-2 text-sm leading-6 text-muted">Performance metrics are ready to become lifecycle score inputs once live GA4 sync is enabled.</p>
            </x-ui.card>
            <x-ui.card class="p-5">
                <h2 class="text-sm font-semibold text-ink">Campaign reporting</h2>
                <p class="mt-2 text-sm leading-6 text-muted">Campaign views can later aggregate GA4 snapshots for assigned content assets.</p>
            </x-ui.card>
        </div>
    </div>
</x-app.layout>
