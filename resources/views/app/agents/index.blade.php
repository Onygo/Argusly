<x-app.layout title="Agents | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Agent framework</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Agents</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Execution architecture for {{ $account->name }}{{ $brand ? ' and '.$brand->name : '' }}. Agents can receive tasks and create placeholder runs; AI execution is not enabled yet.</p>
            </div>
            <x-ui.badge variant="blue">{{ $agents->count() }} agents</x-ui.badge>
        </div>

        <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-dashboard.info-card label="Agents" :value="$agents->count()" />
            <x-dashboard.info-card label="Latest runs" :value="$latestRuns->count()" />
            <x-dashboard.info-card label="Recommendations" :value="$latestRecommendations->count()" />
            <x-dashboard.info-card label="Execution" value="Placeholder" />
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
            <x-dashboard.section title="Agent roster" description="Default system agents and their planned capabilities.">
                <div class="grid gap-4 lg:grid-cols-2">
                    @foreach ($agents as $agent)
                        <x-agents.card :agent="$agent" />
                    @endforeach
                </div>
            </x-dashboard.section>

            <div class="space-y-6">
                <x-dashboard.section title="Latest runs">
                    @if ($latestRuns->isEmpty())
                        <x-dashboard.empty-state title="No runs yet" message="Agent runs will appear here once tasks are dispatched or placeholder runs are created." />
                    @else
                        <div class="space-y-3">
                            @foreach ($latestRuns as $run)
                                <div class="rounded-md border border-line bg-white p-4">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold text-ink">{{ $run->agent->name }}</p>
                                            <p class="mt-1 text-xs text-muted">{{ str($run->status)->headline() }}{{ $run->brand ? ' · '.$run->brand->name : '' }}</p>
                                        </div>
                                        <time class="shrink-0 text-xs text-muted" datetime="{{ $run->started_at?->toIso8601String() }}">
                                            {{ $run->started_at?->diffForHumans() }}
                                        </time>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-dashboard.section>

                <x-dashboard.section title="Latest recommendations">
                    @if ($latestRecommendations->isEmpty())
                        <x-dashboard.empty-state title="No recommendations" message="Recommendations generated from intelligence signals will be ready for future agent dispatch." />
                    @else
                        <div class="space-y-3">
                            @foreach ($latestRecommendations as $recommendation)
                                <x-recommendations.card :recommendation="$recommendation" compact />
                            @endforeach
                        </div>
                    @endif
                </x-dashboard.section>
            </div>
        </div>
    </div>
</x-app.layout>
