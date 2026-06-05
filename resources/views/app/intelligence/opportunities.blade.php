<x-app.layout title="Opportunity Discovery | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Opportunity Discovery</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Strategic opportunities</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Prioritize emerging topics, trends, content gaps, AI gaps, competitor pressure and market opportunities for {{ $brand->name }}.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('app.intelligence.opportunities.project') }}">
                    @csrf
                    <x-ui.button type="submit" variant="secondary">Project to recommendations</x-ui.button>
                </form>
                <x-ui.badge variant="blue">{{ $dashboard['stats']['total'] }} opportunities</x-ui.badge>
            </div>
        </div>

        <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-dashboard.info-card label="Total opportunities" :value="$dashboard['stats']['total']" />
            <x-dashboard.info-card label="Critical" :value="$dashboard['stats']['critical']" />
            <x-dashboard.info-card label="High priority" :value="$dashboard['stats']['high']" />
            <x-dashboard.info-card label="Projected signals" :value="$dashboard['stats']['projected']" />
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
            <x-dashboard.section title="Prioritization model" description="Ranked by impact score, urgency, evidence strength and implementation complexity.">
                @if ($dashboard['opportunities']->isEmpty())
                    <x-dashboard.empty-state title="No opportunities found" message="Opportunities appear as topics, mentions, content, visibility and competitor signals build up for this brand." />
                @else
                    <div class="space-y-3">
                        @foreach ($dashboard['opportunities']->take(12) as $opportunity)
                            <div class="rounded-md border border-line bg-white p-4">
                                <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                                    <div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <x-ui.badge variant="{{ in_array($opportunity['priority'], ['critical', 'high'], true) ? 'blue' : 'default' }}">{{ str($opportunity['priority'])->headline() }}</x-ui.badge>
                                            <x-ui.badge>{{ str($opportunity['type'])->replace('_', ' ')->headline() }}</x-ui.badge>
                                            <span class="text-xs font-semibold text-muted">{{ str($opportunity['complexity'])->headline() }} complexity</span>
                                        </div>
                                        <p class="mt-3 text-sm font-semibold text-ink">{{ $opportunity['title'] }}</p>
                                        <p class="mt-1 text-sm leading-6 text-muted">{{ $opportunity['summary'] }}</p>
                                    </div>
                                    <div class="shrink-0 text-right">
                                        <p class="text-2xl font-semibold text-ink">{{ $opportunity['score'] }}</p>
                                        <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Score</p>
                                    </div>
                                </div>
                                <p class="mt-3 border-t border-line pt-3 text-xs text-muted">{{ $opportunity['recommended_action'] }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-dashboard.section>

            <x-dashboard.section title="Strategic recommendations">
                @if ($dashboard['recommendations']->isEmpty())
                    <x-dashboard.empty-state title="No projected recommendations" message="Project opportunities to create signals and recommendations in the existing Intelligence workflow." />
                @else
                    <div class="space-y-3">
                        @foreach ($dashboard['recommendations'] as $recommendation)
                            <x-recommendations.card :recommendation="$recommendation" />
                        @endforeach
                    </div>
                @endif
            </x-dashboard.section>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-3">
            <x-dashboard.section title="Emerging topics">
                @include('app.intelligence.partials.opportunity-list', ['items' => $dashboard['emergingTopics']])
            </x-dashboard.section>
            <x-dashboard.section title="Trend detection">
                @include('app.intelligence.partials.opportunity-list', ['items' => $dashboard['trends']])
            </x-dashboard.section>
            <x-dashboard.section title="Market opportunities">
                @include('app.intelligence.partials.opportunity-list', ['items' => $dashboard['marketOpportunities']])
            </x-dashboard.section>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-3">
            <x-dashboard.section title="Content gaps">
                @include('app.intelligence.partials.opportunity-list', ['items' => $dashboard['contentGaps']])
            </x-dashboard.section>
            <x-dashboard.section title="AI gaps">
                @include('app.intelligence.partials.opportunity-list', ['items' => $dashboard['aiGaps']])
            </x-dashboard.section>
            <x-dashboard.section title="Competitor gaps">
                @include('app.intelligence.partials.opportunity-list', ['items' => $dashboard['competitorGaps']])
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
