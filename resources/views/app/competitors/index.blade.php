<x-app.layout title="Competitors | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Competitive intelligence</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Competitor dashboard</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Track competitors for {{ $brand->name }} and prepare visibility, SERP, mention and brand benchmarks for future monitoring jobs.</p>
            </div>
            <x-ui.badge variant="blue">{{ $comparison['competitors']->count() }} competitors</x-ui.badge>
        </div>

        <div class="mt-8 grid gap-6 xl:grid-cols-[0.8fr_1.2fr]">
            <x-dashboard.section title="Add competitor" description="Create the competitor record now; tracking snapshots can be attached as future data sources come online.">
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

            <x-dashboard.section title="Comparison" description="Latest snapshot metrics across tracked competitors. Empty values are ready for future tracking jobs.">
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

        <div class="mt-6 grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
            <x-dashboard.section title="Competitors">
                @if ($comparison['competitors']->isEmpty())
                    <x-dashboard.empty-state title="No competitors" message="Add competitors to start building the competitive intelligence baseline for this brand." />
                @else
                    <div class="grid gap-4 lg:grid-cols-2">
                        @foreach ($comparison['competitors'] as $competitor)
                            <x-competitors.card :competitor="$competitor" />
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
