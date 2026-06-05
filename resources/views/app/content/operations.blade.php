<x-app.layout title="Content Operations | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Content Engine</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Content operations</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Briefings, plans, drafts, generation, publishing handoffs, social, newsletters and lifecycle work for {{ $brand->name }}.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button href="{{ route('app.content.index') }}" variant="secondary">Library</x-ui.button>
                <x-ui.button href="{{ route('app.distribution') }}" variant="secondary">Distribution</x-ui.button>
                <x-ui.button href="{{ route('app.newsletters') }}" variant="secondary">Newsletters</x-ui.button>
            </div>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-md border border-line bg-white p-4 text-sm font-medium text-ink">{{ session('status') }}</div>
        @endif

        <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-dashboard.info-card label="Briefings ready" :value="$operations['stats']['briefings_ready']" />
            <x-dashboard.info-card label="Content plans" :value="$operations['stats']['content_plans']" />
            <x-dashboard.info-card label="Drafts" :value="$operations['stats']['drafts']" />
            <x-dashboard.info-card label="Refresh recommendations" :value="$operations['stats']['refresh_recommendations']" />
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
            <x-dashboard.section title="Briefing workflows" description="Turn approved or review-ready briefings into plans and drafts.">
                <div class="space-y-3">
                    @forelse ($operations['briefings'] as $briefing)
                        <div class="rounded-md border border-line bg-panel p-4">
                            <div class="flex flex-col justify-between gap-3 lg:flex-row lg:items-start">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-sm font-semibold text-ink">{{ $briefing->title }}</p>
                                        <x-ui.badge>{{ str($briefing->status)->headline() }}</x-ui.badge>
                                    </div>
                                    <p class="mt-2 line-clamp-2 text-xs leading-5 text-muted">{{ $briefing->objective ?: $briefing->key_message ?: 'No objective.' }}</p>
                                    <p class="mt-2 text-xs text-muted">{{ $briefing->campaign?->name ?? 'No campaign' }}</p>
                                </div>
                                <div class="flex shrink-0 flex-wrap gap-2">
                                    <form method="POST" action="{{ route('app.content.operations.briefings.plan', $briefing) }}">
                                        @csrf
                                        <x-ui.button type="submit" size="sm" variant="secondary">Plan</x-ui.button>
                                    </form>
                                    <form method="POST" action="{{ route('app.content.operations.briefings.draft', $briefing) }}">
                                        @csrf
                                        <x-ui.button type="submit" size="sm">Draft</x-ui.button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @empty
                        <x-dashboard.empty-state title="No briefings ready" message="Review and approved briefings will appear here." />
                    @endforelse
                </div>
            </x-dashboard.section>

            <x-dashboard.section title="Content planning" description="Planning tasks created from briefings.">
                <div class="space-y-3">
                    @forelse ($operations['contentPlans'] as $task)
                        <div class="rounded-md border border-line bg-panel p-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-semibold text-ink">{{ $task->title }}</p>
                                <x-ui.badge>{{ str($task->status)->headline() }}</x-ui.badge>
                                <x-ui.badge variant="blue">{{ str($task->priority)->headline() }}</x-ui.badge>
                            </div>
                            <p class="mt-2 line-clamp-2 text-xs leading-5 text-muted">{{ $task->description ?: 'No plan description.' }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-muted">No content plans yet.</p>
                    @endforelse
                </div>
            </x-dashboard.section>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-2">
            <x-dashboard.section title="Draft management" description="Drafts and review assets currently in progress.">
                <div class="space-y-3">
                    @forelse ($operations['drafts'] as $asset)
                        <a href="{{ route('app.content.show', $asset) }}" class="block rounded-md border border-line bg-panel p-4 transition hover:bg-white">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-semibold text-ink">{{ $asset->title }}</p>
                                <x-ui.badge>{{ str($asset->status)->headline() }}</x-ui.badge>
                                <x-ui.badge variant="blue">{{ strtoupper($asset->language) }}</x-ui.badge>
                            </div>
                            <p class="mt-2 text-xs text-muted">{{ $asset->source }} · {{ $asset->generatedAssets->first()?->status ? 'Latest generation: '.str($asset->generatedAssets->first()->status)->headline() : 'No generation yet' }}</p>
                        </a>
                    @empty
                        <p class="text-sm text-muted">No drafts in progress.</p>
                    @endforelse
                </div>
            </x-dashboard.section>

            <x-dashboard.section title="Generation runs" description="Recent draft generation activity.">
                <div class="space-y-3">
                    @forelse ($operations['generationRuns'] as $run)
                        <div class="rounded-md border border-line bg-panel p-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-semibold text-ink">{{ $run->title ?: str($run->type)->headline().' run' }}</p>
                                <x-ui.badge :variant="in_array($run->status, ['completed', 'approved'], true) ? 'success' : ($run->status === 'failed' ? 'dark' : 'default')">{{ str($run->status)->headline() }}</x-ui.badge>
                            </div>
                            <p class="mt-2 text-xs text-muted">{{ $run->contentAsset?->title ?? 'No content asset' }} · {{ $run->provider ?? 'No provider' }} · {{ $run->model ?? 'No model' }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-muted">No generation runs yet.</p>
                    @endforelse
                </div>
            </x-dashboard.section>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-3">
            <x-dashboard.section title="Publishing and social" description="Approved, scheduled and published assets ready for distribution.">
                <div class="space-y-3">
                    @forelse ($operations['distributionQueue'] as $asset)
                        <a href="{{ route('app.content.show', $asset) }}" class="block rounded-md border border-line bg-panel p-4 transition hover:bg-white">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-semibold text-ink">{{ $asset->title }}</p>
                                <x-ui.badge>{{ str($asset->status)->headline() }}</x-ui.badge>
                            </div>
                            <p class="mt-2 text-xs text-muted">{{ $asset->publishingActions->count() }} publishing actions · {{ $asset->socialPosts->count() }} social posts</p>
                        </a>
                    @empty
                        <p class="text-sm text-muted">No assets ready for distribution.</p>
                    @endforelse
                </div>
            </x-dashboard.section>

            <x-dashboard.section title="Newsletter flows" description="Draft and review newsletters connected to content work.">
                <div class="space-y-3">
                    @forelse ($operations['newsletters'] as $newsletter)
                        <a href="{{ route('app.newsletters.show', $newsletter) }}" class="block rounded-md border border-line bg-panel p-4 transition hover:bg-white">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-semibold text-ink">{{ $newsletter->title }}</p>
                                <x-ui.badge>{{ str($newsletter->status)->headline() }}</x-ui.badge>
                            </div>
                            <p class="mt-2 text-xs text-muted">{{ $newsletter->sections->count() }} sections · {{ strtoupper($newsletter->language) }}</p>
                        </a>
                    @empty
                        <p class="text-sm text-muted">No newsletters yet.</p>
                    @endforelse
                </div>
            </x-dashboard.section>

            <x-dashboard.section title="Lifecycle optimization" description="Decaying content that can become refresh recommendations.">
                <div class="space-y-3">
                    @forelse ($operations['lifecycleScores'] as $score)
                        <div class="rounded-md border border-line bg-panel p-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-semibold text-ink">{{ $score->contentAsset?->title ?? 'Content asset' }}</p>
                                <x-ui.badge :variant="in_array($score->status, ['critical', 'needs_refresh'], true) ? 'dark' : 'default'">{{ str($score->status)->replace('_', ' ')->headline() }}</x-ui.badge>
                            </div>
                            <p class="mt-2 text-xs text-muted">Health {{ $score->health_score }}/100 · Priority {{ $score->refresh_priority }}/100</p>
                            <form method="POST" action="{{ route('app.content.operations.lifecycle.recommendation', $score) }}" class="mt-3">
                                @csrf
                                <x-ui.button type="submit" size="sm" variant="secondary">Recommend refresh</x-ui.button>
                            </form>
                        </div>
                    @empty
                        <p class="text-sm text-muted">No decaying lifecycle scores.</p>
                    @endforelse
                </div>
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
