<x-app.layout title="Reports | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Executive reporting</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Reports</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Static HTML reports for {{ $account->name }}{{ $brand ? ' and '.$brand->name : '' }} generated from current visibility, content, search, social and recommendation metrics.</p>
            </div>
            <form method="POST" action="{{ route('app.reports.store') }}" class="flex flex-wrap items-center gap-2">
                @csrf
                <select name="type" class="h-10 rounded-md border border-line bg-white px-4 text-sm font-semibold text-ink">
                    @foreach ($types as $type)
                        <option value="{{ $type }}">{{ str($type)->headline() }}</option>
                    @endforeach
                </select>
                <x-ui.button type="submit">Generate report</x-ui.button>
            </form>
        </div>

        <div class="mt-8 grid gap-4 md:grid-cols-3">
            <x-dashboard.info-card label="Reports" :value="$reports->count()" />
            <x-dashboard.info-card label="Formats" value="HTML" />
            <x-dashboard.info-card label="Delivery" value="Manual" />
        </div>

        <x-ui.card class="mt-6 overflow-hidden">
            <div class="hidden grid-cols-[1fr_0.5fr_0.7fr_0.5fr] gap-4 border-b border-line bg-panel px-5 py-3 text-xs font-semibold uppercase tracking-[0.1em] text-muted md:grid">
                <span>Report</span>
                <span>Type</span>
                <span>Generated</span>
                <span></span>
            </div>
            @forelse ($reports as $report)
                <div class="grid gap-3 border-b border-line px-5 py-4 last:border-b-0 md:grid-cols-[1fr_0.5fr_0.7fr_0.5fr] md:items-center">
                    <div>
                        <p class="text-sm font-semibold text-ink">{{ $report->title }}</p>
                        <p class="mt-1 text-xs text-muted">{{ $report->summary }}</p>
                    </div>
                    <x-ui.badge>{{ str($report->type)->headline() }}</x-ui.badge>
                    <p class="text-sm text-muted">{{ $report->generated_at?->toDayDateTimeString() }}</p>
                    <div class="md:text-right">
                        <x-ui.button href="{{ route('app.reports.show', $report) }}" variant="secondary" size="sm">Open</x-ui.button>
                    </div>
                </div>
            @empty
                <x-dashboard.empty-state title="No reports yet" message="Generate a report to create a static executive summary from current tenant metrics." />
            @endforelse
        </x-ui.card>
    </div>
</x-app.layout>
