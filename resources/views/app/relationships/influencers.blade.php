<x-app.layout title="Influencer Intelligence | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Relationship intelligence</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Influencer Intelligence</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Creator database, discovery, campaign tracking, performance scoring, media value and CRM for {{ $brand->name }}.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button href="{{ route('app.relationships') }}" variant="secondary">Relationship Graph</x-ui.button>
                <x-ui.button href="{{ route('app.campaigns') }}" variant="secondary">Campaigns</x-ui.button>
            </div>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-md border border-line bg-white p-4 text-sm font-medium text-ink">{{ session('status') }}</div>
        @endif

        @if ($errors->has('creator'))
            <div class="mt-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">{{ $errors->first('creator') }}</div>
        @endif

        <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <x-dashboard.info-card label="Creators" :value="$dashboard['stats']['creators']" />
            <x-dashboard.info-card label="Monitored" :value="$dashboard['stats']['monitored']" />
            <x-dashboard.info-card label="Campaigns" :value="$dashboard['stats']['campaigns']" />
            <x-dashboard.info-card label="Avg score" :value="$dashboard['stats']['avg_performance_score']" />
            <x-dashboard.info-card label="Media value" :value="'EUR '.number_format($dashboard['stats']['media_value'])" />
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[0.8fr_1.2fr]">
            <x-dashboard.section title="Add creator" description="Create a tenant-safe creator profile in the relationship CRM.">
                <form method="POST" action="{{ route('app.relationships.influencers.store') }}" class="space-y-4">
                    @csrf
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">First name</span>
                            <input name="first_name" required value="{{ old('first_name') }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Last name</span>
                            <input name="last_name" required value="{{ old('last_name') }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        </label>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Display name</span>
                            <input name="display_name" value="{{ old('display_name') }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Email</span>
                            <input name="email" type="email" value="{{ old('email') }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        </label>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Website</span>
                            <input name="website" type="url" value="{{ old('website') }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">LinkedIn URL</span>
                            <input name="linkedin_url" type="url" value="{{ old('linkedin_url') }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        </label>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Category</span>
                            <input name="category" value="{{ old('category') }}" placeholder="AI, SaaS, PR" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Audience</span>
                            <input name="audience" value="{{ old('audience') }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        </label>
                    </div>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Channels</span>
                        <input name="channels" value="{{ old('channels') }}" placeholder="linkedin, youtube, newsletter" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Followers</span>
                            <input name="followers" type="number" min="0" value="{{ old('followers') }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Engagement %</span>
                            <input name="engagement_rate" type="number" min="0" max="100" step="0.1" value="{{ old('engagement_rate') }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Avg views</span>
                            <input name="avg_views" type="number" min="0" value="{{ old('avg_views') }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        </label>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">CRM stage</span>
                            <select name="stage" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach ($crmStages as $stage)
                                    <option value="{{ $stage }}">{{ str($stage)->replace('_', ' ')->headline() }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Next action</span>
                            <input name="next_action" value="{{ old('next_action') }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        </label>
                    </div>
                    <x-ui.button type="submit">Add creator</x-ui.button>
                </form>
            </x-dashboard.section>

            <x-dashboard.section title="Creator database" description="Creators and influencers scoped to the current brand.">
                @if ($dashboard['creators']->isEmpty())
                    <x-dashboard.empty-state title="No creators yet" message="Add creators to start discovery, campaign tracking and relationship management." />
                @else
                    <div class="space-y-3">
                        @foreach ($dashboard['creators'] as $creator)
                            <div class="rounded-md border border-line bg-panel p-4">
                                <div class="flex flex-col justify-between gap-3 lg:flex-row lg:items-start">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="truncate text-sm font-semibold text-ink">{{ $creator->display_name }}</p>
                                            <x-ui.badge>{{ str(data_get($creator->metadata, 'crm.stage', 'discovered'))->replace('_', ' ')->headline() }}</x-ui.badge>
                                            @if (data_get($creator->metadata, 'monitoring.enabled'))
                                                <x-ui.badge variant="success">Monitored</x-ui.badge>
                                            @endif
                                        </div>
                                        <p class="mt-2 text-xs leading-5 text-muted">
                                            {{ data_get($creator->metadata, 'category', 'No category') }}
                                            · {{ number_format((int) data_get($creator->metadata, 'metrics.followers', 0)) }} followers
                                            · {{ data_get($creator->metadata, 'metrics.engagement_rate', 0) }}% engagement
                                        </p>
                                        <p class="mt-1 text-xs text-muted">Score {{ data_get($creator->metadata, 'performance.score', 0) }}/100 · EUR {{ number_format((int) data_get($creator->metadata, 'performance.media_value', 0)) }} media value</p>
                                    </div>
                                    <div class="flex shrink-0 flex-wrap gap-2">
                                        <form method="POST" action="{{ route('app.relationships.influencers.monitor', $creator) }}">
                                            @csrf
                                            <x-ui.button type="submit" size="sm" variant="secondary">Monitor</x-ui.button>
                                        </form>
                                    </div>
                                </div>

                                <div class="mt-4 grid gap-3 lg:grid-cols-2">
                                    <form method="POST" action="{{ route('app.relationships.influencers.crm', $creator) }}" class="grid gap-2 sm:grid-cols-[0.7fr_1fr_auto]">
                                        @csrf
                                        <select name="stage" class="rounded-md border border-line bg-white px-3 py-2 text-xs text-ink">
                                            @foreach ($crmStages as $stage)
                                                <option value="{{ $stage }}" @selected(data_get($creator->metadata, 'crm.stage', 'discovered') === $stage)>{{ str($stage)->replace('_', ' ')->headline() }}</option>
                                            @endforeach
                                        </select>
                                        <input name="next_action" value="{{ data_get($creator->metadata, 'crm.next_action') }}" placeholder="Next action" class="rounded-md border border-line bg-white px-3 py-2 text-xs text-ink">
                                        <x-ui.button type="submit" size="sm" variant="secondary">Save CRM</x-ui.button>
                                    </form>

                                    @if ($dashboard['campaigns']->isNotEmpty())
                                        <form method="POST" action="{{ route('app.relationships.influencers.campaigns.store', $creator) }}" class="grid gap-2 sm:grid-cols-[1fr_auto]">
                                            @csrf
                                            <select name="campaign_id" class="rounded-md border border-line bg-white px-3 py-2 text-xs text-ink">
                                                @foreach ($dashboard['campaigns'] as $campaign)
                                                    <option value="{{ $campaign->id }}">{{ $campaign->name }}</option>
                                                @endforeach
                                            </select>
                                            <x-ui.button type="submit" size="sm">Track campaign</x-ui.button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-dashboard.section>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-3">
            <x-dashboard.section title="Discovery engine" description="Ranked creator candidates based on relationship fit, audience and engagement data.">
                <div class="space-y-3">
                    @forelse ($dashboard['discovery'] as $candidate)
                        <div class="rounded-md border border-line bg-panel p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-ink">{{ $candidate['creator']->display_name }}</p>
                                    <p class="mt-1 text-xs text-muted">{{ implode(', ', $candidate['reasons']) ?: 'No discovery reasons' }}</p>
                                </div>
                                <x-ui.badge variant="blue">{{ $candidate['score'] }}</x-ui.badge>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-muted">No discovery candidates yet.</p>
                    @endforelse
                </div>
            </x-dashboard.section>

            <x-dashboard.section title="Campaign tracking" description="Influencer campaigns found in the current brand.">
                <div class="space-y-3">
                    @forelse ($dashboard['campaigns'] as $campaign)
                        <a href="{{ route('app.campaigns.show', $campaign) }}" class="block rounded-md border border-line bg-panel p-4 transition hover:bg-white">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-semibold text-ink">{{ $campaign->name }}</p>
                                <x-ui.badge>{{ str($campaign->status)->headline() }}</x-ui.badge>
                            </div>
                            <p class="mt-2 line-clamp-2 text-xs leading-5 text-muted">{{ $campaign->objective ?: $campaign->description ?: 'No campaign objective.' }}</p>
                        </a>
                    @empty
                        <p class="text-sm text-muted">No influencer campaigns yet.</p>
                    @endforelse
                </div>
            </x-dashboard.section>

            <x-dashboard.section title="Creator CRM" description="Pipeline distribution for relationship management.">
                <div class="space-y-3">
                    @forelse ($dashboard['crmPipeline'] as $stage => $count)
                        <div class="flex items-center justify-between gap-3 rounded-md border border-line bg-panel px-4 py-3">
                            <span class="text-sm font-semibold text-ink">{{ str($stage)->replace('_', ' ')->headline() }}</span>
                            <x-ui.badge>{{ $count }}</x-ui.badge>
                        </div>
                    @empty
                        <p class="text-sm text-muted">No CRM stages yet.</p>
                    @endforelse
                </div>
            </x-dashboard.section>
        </div>

        <div class="mt-6">
            <x-dashboard.section title="Performance leaders" description="Top creators by scoring, mention impact and estimated media value.">
                @if ($dashboard['performanceLeaders']->isEmpty())
                    <x-dashboard.empty-state title="No performance data" message="Run monitoring to calculate creator performance and media value." />
                @else
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ($dashboard['performanceLeaders'] as $creator)
                            <div class="rounded-md border border-line bg-panel p-4">
                                <p class="truncate text-sm font-semibold text-ink">{{ $creator->display_name }}</p>
                                <p class="mt-2 text-2xl font-semibold tracking-tight text-ink">{{ data_get($creator->metadata, 'performance.score', 0) }}</p>
                                <p class="mt-1 text-xs text-muted">EUR {{ number_format((int) data_get($creator->metadata, 'performance.media_value', 0)) }} media value</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
