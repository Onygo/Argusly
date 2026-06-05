<x-app.layout title="Executive Reporting | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Executive reporting</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Executive dashboard</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Board-ready KPIs, snapshots, summaries, trend reports and exports for {{ $account->name }}{{ $brand ? ' and '.$brand->name : '' }}.</p>
            </div>
            <form method="POST" action="{{ route('app.reports.store') }}" class="flex flex-wrap items-center gap-2">
                @csrf
                <select name="type" class="h-10 rounded-md border border-line bg-white px-4 text-sm font-semibold text-ink">
                    @foreach ($types as $type)
                        <option value="{{ $type }}" @selected($type === 'executive')>{{ str($type)->headline() }}</option>
                    @endforeach
                </select>
                <x-ui.button type="submit">Generate snapshot</x-ui.button>
            </form>
        </div>

        <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-dashboard.info-card label="Reports" :value="$dashboard['stats']['reports']" />
            <x-dashboard.info-card label="Snapshots" :value="$dashboard['stats']['snapshots']" />
            <x-dashboard.info-card label="Scheduled" :value="$dashboard['stats']['scheduled']" />
            <x-dashboard.info-card label="Risk level" :value="str($dashboard['board']['risk_level'])->headline()" />
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[1fr_0.9fr]">
            <x-dashboard.section title="KPI tracking" description="Latest tracked executive KPIs from the most recent report snapshot.">
                @if (empty($dashboard['stats']['kpis']))
                    <x-dashboard.empty-state title="No KPIs yet" message="Generate an executive report to create KPI tracking snapshots." />
                @else
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($dashboard['stats']['kpis'] as $kpi)
                            <div class="rounded-md border border-line bg-panel p-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ $kpi['label'] }}</p>
                                <p class="mt-2 text-2xl font-semibold tracking-tight text-ink">{{ $kpi['value'] }}</p>
                                <p class="mt-1 text-xs text-muted">Target {{ $kpi['target'] }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-dashboard.section>

            <x-dashboard.section title="Board summary" description="Campaign execution and operational risk for leadership review.">
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-dashboard.info-card label="Active campaigns" :value="$dashboard['board']['active_campaigns']" />
                    <x-dashboard.info-card label="Open board items" :value="$dashboard['board']['open_board_items']" />
                    <x-dashboard.info-card label="Completed items" :value="$dashboard['board']['completed_board_items']" />
                    <x-dashboard.info-card label="Risk level" :value="str($dashboard['board']['risk_level'])->headline()" />
                </div>
                <div class="mt-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Focus</p>
                    <p class="mt-2 text-sm text-muted">{{ collect($dashboard['board']['focus'])->implode(', ') ?: 'No active campaign focus yet.' }}</p>
                </div>
            </x-dashboard.section>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-2">
            <x-dashboard.section title="Trend report" description="Directional movement across visibility, search, content and social.">
                <div class="space-y-3">
                    @foreach ($dashboard['trends'] as $label => $trend)
                        <div class="rounded-md border border-line bg-panel p-4">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-sm font-semibold text-ink">{{ str($label)->replace('_', ' ')->headline() }}</p>
                                <x-ui.badge :variant="$trend['change'] > 0 ? 'success' : ($trend['change'] < 0 ? 'dark' : 'default')">{{ $trend['change'] }}</x-ui.badge>
                            </div>
                            <p class="mt-1 text-xs text-muted">Previous {{ $trend['previous'] }} · Current {{ $trend['current'] }}</p>
                        </div>
                    @endforeach
                </div>
            </x-dashboard.section>

            <x-dashboard.section title="Reports and exports" description="Generated report snapshots with HTML, PDF and PowerPoint exports.">
                <div class="space-y-3">
                    @forelse ($dashboard['reports'] as $report)
                        <div class="rounded-md border border-line bg-panel p-4">
                            <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                                <div>
                                    <p class="text-sm font-semibold text-ink">{{ $report->title }}</p>
                                    <p class="mt-1 text-xs text-muted">{{ $report->generated_at?->toDayDateTimeString() }}</p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <x-ui.button href="{{ route('app.reports.show', $report) }}" size="sm" variant="secondary">Open</x-ui.button>
                                    <x-ui.button href="{{ route('app.reports.export.pdf', $report) }}" size="sm" variant="secondary">PDF</x-ui.button>
                                    <x-ui.button href="{{ route('app.reports.export.powerpoint', $report) }}" size="sm" variant="secondary">PPTX</x-ui.button>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-muted">No reports yet.</p>
                    @endforelse
                </div>
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
