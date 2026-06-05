<x-app.layout title="Competitors | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Competitive intelligence</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Competitor dashboard</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Track competitors for {{ $brand->name }} across mentions, AI visibility, narratives, trends, signals and alerts.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('app.competitors.monitor') }}">
                    @csrf
                    <x-ui.button type="submit" variant="secondary">Run monitoring</x-ui.button>
                </form>
                <x-ui.badge variant="blue">{{ $comparison['competitors']->count() }} competitors</x-ui.badge>
            </div>
        </div>

        <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-dashboard.info-card label="Active competitors" :value="$comparison['monitoring']['active_competitors']" />
            <x-dashboard.info-card label="Monitoring coverage" :value="$comparison['monitoring']['coverage'].'%'" />
            <x-dashboard.info-card label="Open alerts" :value="$comparison['alerts']->count()" />
            <x-dashboard.info-card label="Last monitored" :value="$comparison['monitoring']['last_monitored_at']?->diffForHumans()" empty="No snapshots" />
        </div>

        <div class="mt-8 grid gap-6 xl:grid-cols-[0.8fr_1.2fr]">
            <x-dashboard.section title="Competitor management" description="Create competitors and keep their tracking status current.">
                <form method="POST" action="{{ route('app.competitors.store') }}" class="space-y-4">
                    @csrf
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Name</span>
                        <input name="name" value="{{ old('name') }}" required class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Website</span>
                        <input name="website" value="{{ old('website') }}" required placeholder="example.com" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Industry</span>
                        <input name="industry" value="{{ old('industry') }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Status</span>
                        <select name="status" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                            @foreach ($statuses as $status)
                                <option value="{{ $status }}" @selected(old('status', 'active') === $status)>{{ str($status)->headline() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <x-ui.button type="submit">Add competitor</x-ui.button>
                </form>
            </x-dashboard.section>

            <x-dashboard.section title="Benchmark dashboard" description="Latest snapshot metrics across tracked competitors.">
                <div class="grid gap-3 sm:grid-cols-3">
                    <x-dashboard.info-card label="Avg. visibility" :value="$comparison['averages']['visibility_score'] === null ? null : round($comparison['averages']['visibility_score'], 1)" empty="No data" />
                    <x-dashboard.info-card label="Avg. mentions" :value="$comparison['averages']['mention_score'] === null ? null : round($comparison['averages']['mention_score'], 1)" empty="No data" />
                    <x-dashboard.info-card label="Avg. share of voice" :value="$comparison['averages']['share_of_voice'] === null ? null : round($comparison['averages']['share_of_voice'], 1)" empty="No data" />
                </div>

                <div class="mt-5 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-md border border-line bg-panel p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Visibility leader</p>
                        <p class="mt-2 truncate text-sm font-semibold text-ink">{{ $comparison['leaders']['visibility']?->name ?? 'No data' }}</p>
                    </div>
                    <div class="rounded-md border border-line bg-panel p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Mention leader</p>
                        <p class="mt-2 truncate text-sm font-semibold text-ink">{{ $comparison['leaders']['mentions']?->name ?? 'No data' }}</p>
                    </div>
                    <div class="rounded-md border border-line bg-panel p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">SOV leader</p>
                        <p class="mt-2 truncate text-sm font-semibold text-ink">{{ $comparison['leaders']['share_of_voice']?->name ?? 'No data' }}</p>
                    </div>
                </div>
            </x-dashboard.section>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
            <x-dashboard.section title="Executive summaries">
                <div class="space-y-3">
                    @foreach ($comparison['executiveSummaries'] as $summary)
                        <div class="rounded-md border border-line bg-white p-4">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-sm font-semibold text-ink">{{ $summary['title'] }}</p>
                                <x-ui.badge>{{ str($summary['priority'])->headline() }}</x-ui.badge>
                            </div>
                            <p class="mt-2 text-sm leading-6 text-muted">{{ $summary['summary'] }}</p>
                        </div>
                    @endforeach
                </div>
            </x-dashboard.section>

            <x-dashboard.section title="Competitor alerts">
                @if ($comparison['alerts']->isEmpty())
                    <x-dashboard.empty-state title="No competitor alerts" message="Alerts appear when monitoring detects competitor movement or pressure." />
                @else
                    <div class="space-y-3">
                        @foreach ($comparison['alerts'] as $alert)
                            <div class="rounded-md border border-line bg-white p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <p class="text-sm font-semibold text-ink">{{ $alert->title }}</p>
                                    <x-ui.badge>{{ str($alert->priority)->headline() }}</x-ui.badge>
                                </div>
                                <p class="mt-2 text-sm leading-6 text-muted">{{ $alert->summary }}</p>
                                <p class="mt-3 text-xs text-muted">{{ $alert->detected_at?->diffForHumans() }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-dashboard.section>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-3">
            <x-dashboard.section title="AI Visibility comparison">
                <div class="space-y-3">
                    @forelse ($comparison['visibilityComparison'] as $row)
                        <div class="rounded-md border border-line bg-white p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-ink">{{ $row['competitor']->name }}</p>
                                    <p class="mt-1 text-xs text-muted">{{ $row['providers']->implode(', ') ?: 'No providers' }}</p>
                                </div>
                                <x-ui.badge>{{ $row['mentions'] }} appearances</x-ui.badge>
                            </div>
                            <p class="mt-3 text-xs text-muted">{{ $row['positive'] }} positive · avg. position {{ $row['avg_position'] ?? '-' }}</p>
                        </div>
                    @empty
                        <x-dashboard.empty-state title="No visibility comparison" message="AI visibility comparison appears when provider runs extract competitor entities." />
                    @endforelse
                </div>
            </x-dashboard.section>

            <x-dashboard.section title="Trend comparison">
                <div class="space-y-3">
                    @forelse ($comparison['trendComparison'] as $row)
                        <div class="rounded-md border border-line bg-white p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-ink">{{ $row['competitor']->name }}</p>
                                    <p class="mt-1 text-xs text-muted">{{ $row['points']->count() }} snapshot points</p>
                                </div>
                                @php($sovDelta = $row['delta']['share_of_voice'])
                                <x-ui.badge>SOV {{ $sovDelta !== null && $sovDelta >= 0 ? '+' : '' }}{{ $sovDelta ?? '-' }}</x-ui.badge>
                            </div>
                            <div class="mt-3 grid grid-cols-3 gap-2 text-xs text-muted">
                                <span>Visibility {{ $row['delta']['visibility_score'] ?? '-' }}</span>
                                <span>Mentions {{ $row['delta']['mention_score'] ?? '-' }}</span>
                                <span>SOV {{ $row['delta']['share_of_voice'] ?? '-' }}</span>
                            </div>
                        </div>
                    @empty
                        <x-dashboard.empty-state title="No trend data" message="Trend comparison appears after competitor monitoring captures snapshots." />
                    @endforelse
                </div>
            </x-dashboard.section>

            <x-dashboard.section title="Narrative comparison">
                <div class="space-y-3">
                    @forelse ($comparison['narrativeComparison'] as $row)
                        <div class="rounded-md border border-line bg-white p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-ink">{{ $row['competitor']->name }}</p>
                                    <p class="mt-1 text-xs text-muted">{{ $row['titles']->implode(', ') ?: 'No linked narratives' }}</p>
                                </div>
                                <x-ui.badge>{{ $row['narratives'] }} narratives</x-ui.badge>
                            </div>
                            <p class="mt-3 text-xs text-muted">{{ $row['observations'] }} observations · {{ $row['open_gaps'] }} gaps</p>
                        </div>
                    @empty
                        <x-dashboard.empty-state title="No narrative comparison" message="Narrative comparison uses linked narratives and competitor references." />
                    @endforelse
                </div>
            </x-dashboard.section>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
            <x-dashboard.section title="Competitors">
                @if ($comparison['competitors']->isEmpty())
                    <x-dashboard.empty-state title="No competitors" message="Add competitors to start building the competitive intelligence baseline for this brand." />
                @else
                    <div class="grid gap-4 lg:grid-cols-2">
                        @foreach ($comparison['competitors'] as $competitor)
                            <div class="space-y-3">
                                <x-competitors.card :competitor="$competitor" />
                                <form method="POST" action="{{ route('app.competitors.update', $competitor) }}" class="grid gap-3 rounded-md border border-line bg-panel p-4 md:grid-cols-2">
                                    @csrf
                                    @method('PUT')
                                    <input name="name" value="{{ $competitor->name }}" required class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                    <input name="website" value="{{ $competitor->website }}" required class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                    <input name="industry" value="{{ $competitor->industry }}" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Industry">
                                    <select name="status" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                        @foreach ($statuses as $status)
                                            <option value="{{ $status }}" @selected($competitor->status === $status)>{{ str($status)->headline() }}</option>
                                        @endforeach
                                    </select>
                                    <div class="md:col-span-2">
                                        <x-ui.button type="submit" size="sm" variant="secondary">Save competitor</x-ui.button>
                                    </div>
                                </form>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-dashboard.section>

            <x-dashboard.section title="Tracking architecture" description="These source lanes are prepared for later workers and external integrations.">
                <div class="space-y-3">
                    @foreach ($comparison['tracking'] as $source)
                        <div class="flex items-center justify-between gap-4 rounded-md border border-line bg-panel p-4">
                            <div>
                                <p class="text-sm font-semibold text-ink">{{ $source['label'] }}</p>
                                <p class="mt-1 text-xs text-muted">{{ $source['key'] }}</p>
                            </div>
                            <x-ui.badge>{{ str($source['status'])->headline() }}</x-ui.badge>
                        </div>
                    @endforeach
                </div>
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
