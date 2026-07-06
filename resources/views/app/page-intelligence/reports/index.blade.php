@extends('layouts.app', ['title' => 'Page Intelligence Reports'])

@section('pageHeader')
    <x-page-header title="Page Intelligence Reports">
        <x-slot:description>Generate recurring customer briefings from monitored pages, scores, alerts, SERP, GEO, competitors and campaigns.</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
    <a href="{{ route('app.page-intelligence.scheduled-briefings.index', ['workspace' => $workspace->id]) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Scheduled Briefings</a>
    <a href="{{ route('app.page-intelligence.index', ['workspace' => $workspace->id]) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Page Intelligence</a>
@endsection

@section('content')
    <div class="space-y-6">
        <section class="rounded-lg border border-border bg-surface p-4">
            <form method="POST" action="{{ route('app.page-intelligence.reports.store') }}" class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                @csrf
                <label class="block">
                    <span class="text-xs text-textSecondary">Workspace</span>
                    <select name="workspace" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        @foreach ($workspaces as $option)
                            <option value="{{ $option->id }}" @selected((string) $option->id === (string) $workspace->id)>{{ $option->display_name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs text-textSecondary">Report type</span>
                    <select name="report_type" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        @foreach ($reportTypes as $key => $type)
                            <option value="{{ $key }}">{{ $type['label'] }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs text-textSecondary">Market pack</span>
                    <select name="market_pack_key" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        <option value="">All markets</option>
                        @foreach ($marketPacks as $pack)
                            <option value="{{ $pack->key }}">{{ $pack->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs text-textSecondary">From</span>
                    <input name="period_start" type="date" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
                </label>
                <div class="flex items-end gap-2">
                    <label class="block flex-1">
                        <span class="text-xs text-textSecondary">To</span>
                        <input name="period_end" type="date" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
                    </label>
                    <button class="rounded-md border border-textPrimary bg-textPrimary px-3 py-2 text-sm text-white hover:opacity-90">Generate</button>
                </div>
            </form>
            @error('report_type')<p class="mt-2 text-xs text-rose-700">{{ $message }}</p>@enderror
        </section>

        <x-data-table label="Generated reports" description="Versioned report snapshots ready for customer review or export." density="compact">
            <x-data-table.header>
                <x-data-table.row>
                    <x-data-table.cell heading>Report</x-data-table.cell>
                    <x-data-table.cell heading>Type</x-data-table.cell>
                    <x-data-table.cell heading>Market</x-data-table.cell>
                    <x-data-table.cell heading>Version</x-data-table.cell>
                    <x-data-table.cell heading>Generated</x-data-table.cell>
                    <x-data-table.cell heading>Export</x-data-table.cell>
                </x-data-table.row>
            </x-data-table.header>
            <tbody>
                @forelse ($reports as $report)
                    <x-data-table.row>
                        <x-data-table.cell label="Report">
                            <a href="{{ route('app.page-intelligence.reports.show', $report) }}" class="font-medium text-textPrimary hover:underline">{{ $report->title }}</a>
                            <p class="text-xs text-textSecondary">{{ $report->summary }}</p>
                        </x-data-table.cell>
                        <x-data-table.cell label="Type">{{ $reportTypes[$report->report_type]['label'] ?? str($report->report_type)->headline() }}</x-data-table.cell>
                        <x-data-table.cell label="Market">{{ $report->marketPack?->name ?: 'All markets' }}</x-data-table.cell>
                        <x-data-table.cell label="Version">v{{ $report->snapshot_version }}</x-data-table.cell>
                        <x-data-table.cell label="Generated">{{ $report->generated_at?->diffForHumans() ?: '-' }}</x-data-table.cell>
                        <x-data-table.cell label="Export">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xs text-textSecondary">{{ str($report->artifact_status ?: 'pending')->headline() }}</span>
                                @if ($report->artifact_status === \App\Models\PageIntelligenceReport::ARTIFACT_STATUS_READY)
                                    <a href="{{ route('app.page-intelligence.reports.artifact.download', $report) }}" class="text-sm text-textPrimary hover:underline">Download</a>
                                @elseif ($report->artifact_status !== \App\Models\PageIntelligenceReport::ARTIFACT_STATUS_GENERATING)
                                    <form method="POST" action="{{ route('app.page-intelligence.reports.artifact.generate', $report) }}">
                                        @csrf
                                        <button class="text-sm text-textPrimary hover:underline">Generate PDF</button>
                                    </form>
                                @endif
                                <a href="{{ route('app.page-intelligence.reports.export', $report) }}" class="text-sm text-textPrimary hover:underline">Layout</a>
                            </div>
                        </x-data-table.cell>
                    </x-data-table.row>
                @empty
                    <x-data-table.empty colspan="6" title="No reports generated yet" description="Generate a briefing when monitoring data is ready." />
                @endforelse
            </tbody>
        </x-data-table>

        {{ $reports->links() }}
    </div>
@endsection
