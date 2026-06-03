<x-app.layout :title="__('campaigns.title').' | Argusly'">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Campaign foundation</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ __('campaigns.title') }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Plan and connect content, topics and intelligence signals for {{ $brand->name }}.</p>
            </div>
            <x-ui.badge variant="blue">{{ $stats['total'] }} campaigns</x-ui.badge>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-md border border-line bg-white p-4 text-sm font-medium text-ink">{{ session('status') }}</div>
        @endif

        <div class="mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-dashboard.info-card label="Planned" :value="$stats['scheduled']" />
            <x-dashboard.info-card label="Active" :value="$stats['active']" />
            <x-dashboard.info-card label="Completed" :value="$stats['completed']" />
            <x-dashboard.info-card label="Total" :value="$stats['total']" />
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[0.8fr_1.2fr]">
            <x-dashboard.section :title="__('campaigns.create')" description="Manual foundation only. Automation can attach later.">
                <form method="POST" action="{{ route('app.campaigns.store') }}" class="space-y-5">
                    @csrf
                    @include('app.campaigns._form', ['campaign' => new \App\Models\Campaign(['status' => 'draft', 'metadata' => ['campaign_type' => 'content']]), 'statuses' => $statuses, 'types' => $types, 'assets' => $assets, 'topics' => $topics, 'signals' => $signals])
                    <x-ui.button type="submit">{{ __('campaigns.create') }}</x-ui.button>
                </form>
            </x-dashboard.section>

            <x-dashboard.section :title="__('campaigns.title')" description="Filter by status or campaign architecture lane.">
                <form method="GET" action="{{ route('app.campaigns') }}" class="mb-5 grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
                    <select name="status" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">{{ __('common.all_statuses') }}</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ str($status)->headline() }}</option>
                        @endforeach
                    </select>
                    <select name="type" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">{{ __('common.all_types') }}</option>
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ str($type)->headline() }}</option>
                        @endforeach
                    </select>
                    <x-ui.button type="submit" variant="secondary">{{ __('common.filter') }}</x-ui.button>
                </form>

                @if ($campaigns->isEmpty())
                    <x-dashboard.empty-state title="No campaigns" message="Create a campaign to connect content assets, topics and intelligence signals." />
                @else
                    <div class="space-y-3">
                        @foreach ($campaigns as $campaign)
                            <a href="{{ route('app.campaigns.show', $campaign) }}" class="block rounded-md border border-line bg-panel p-4 transition hover:border-slate-300 hover:bg-white">
                                <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="truncate text-sm font-semibold text-ink">{{ $campaign->name }}</p>
                                            <x-ui.badge variant="{{ $campaign->status === 'active' ? 'success' : ($campaign->status === 'planned' ? 'blue' : 'default') }}">{{ str($campaign->status)->headline() }}</x-ui.badge>
                                            <x-ui.badge>{{ str($campaign->metadata['campaign_type'] ?? 'content')->headline() }}</x-ui.badge>
                                        </div>
                                        <p class="mt-2 line-clamp-2 text-sm leading-6 text-muted">{{ $campaign->objective ?: $campaign->description ?: 'No objective yet.' }}</p>
                                        <p class="mt-2 text-xs text-muted">{{ $campaign->start_date?->format('M j, Y') ?? 'No start date' }} - {{ $campaign->end_date?->format('M j, Y') ?? 'No end date' }}</p>
                                    </div>
                                    <div class="grid shrink-0 grid-cols-3 gap-2 text-center">
                                        <div class="rounded-md border border-line bg-white px-3 py-2">
                                            <p class="text-sm font-semibold text-ink">{{ $campaign->content_assets_count }}</p>
                                            <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Assets</p>
                                        </div>
                                        <div class="rounded-md border border-line bg-white px-3 py-2">
                                            <p class="text-sm font-semibold text-ink">{{ $campaign->topics_count }}</p>
                                            <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Topics</p>
                                        </div>
                                        <div class="rounded-md border border-line bg-white px-3 py-2">
                                            <p class="text-sm font-semibold text-ink">{{ $campaign->signals_count }}</p>
                                            <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Signals</p>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                    <div class="mt-5">{{ $campaigns->links() }}</div>
                @endif
            </x-dashboard.section>
        </div>

        <div class="mt-6">
            <x-dashboard.section title="Prepared campaign architecture" description="Campaign records are ready for specialized campaign workflows without automation yet.">
                <div class="grid gap-3 md:grid-cols-4">
                    @foreach (['Social Campaigns', 'Influencer Campaigns', 'Content Campaigns', 'PR Campaigns'] as $lane)
                        <div class="rounded-md border border-line bg-panel p-4">
                            <p class="text-sm font-semibold text-ink">{{ $lane }}</p>
                            <p class="mt-1 text-xs text-muted">Ready for future workflows</p>
                        </div>
                    @endforeach
                </div>
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
