@extends('layouts.app', ['title' => 'Scheduled Page Intelligence Briefings'])

@section('pageHeader')
    <x-page-header title="Scheduled Briefings">
        <x-slot:description>Recurring Page Intelligence report snapshots for workspace review.</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
    <a href="{{ route('app.page-intelligence.reports.index', ['workspace' => $workspace->id]) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Reports</a>
    <a href="{{ route('app.page-intelligence.index', ['workspace' => $workspace->id]) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Page Intelligence</a>
@endsection

@section('content')
    <div class="space-y-6">
        <section class="rounded-lg border border-border bg-surface p-4">
            <form method="POST" action="{{ route('app.page-intelligence.scheduled-briefings.store') }}" class="space-y-4">
                @csrf
                @include('app.page-intelligence.scheduled-briefings._form', ['briefing' => null])
                <div class="flex justify-end">
                    <button class="rounded-md border border-textPrimary bg-textPrimary px-3 py-2 text-sm text-white hover:opacity-90">Create schedule</button>
                </div>
            </form>
            @if ($errors->any())
                <div class="mt-3 rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                    {{ $errors->first() }}
                </div>
            @endif
        </section>

        <form method="GET" action="{{ route('app.page-intelligence.scheduled-briefings.index') }}" class="rounded-lg border border-border bg-surface p-4">
            <label class="block max-w-sm">
                <span class="text-xs text-textSecondary">Workspace</span>
                <select name="workspace" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
                    @foreach ($workspaces as $option)
                        <option value="{{ $option->id }}" @selected((string) $option->id === (string) $workspace->id)>{{ $option->display_name }}</option>
                    @endforeach
                </select>
            </label>
            <div class="mt-3">
                <button class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Switch workspace</button>
            </div>
        </form>

        <x-data-table label="Scheduled briefings" description="Active and paused recurring Page Intelligence snapshot schedules." density="compact">
            <x-data-table.header>
                <x-data-table.row>
                    <x-data-table.cell heading>Schedule</x-data-table.cell>
                    <x-data-table.cell heading>Report</x-data-table.cell>
                    <x-data-table.cell heading>Last run</x-data-table.cell>
                    <x-data-table.cell heading>Next run</x-data-table.cell>
                    <x-data-table.cell heading>Generated reports</x-data-table.cell>
                    <x-data-table.cell heading>Status</x-data-table.cell>
                    <x-data-table.cell heading>Actions</x-data-table.cell>
                </x-data-table.row>
            </x-data-table.header>
            <tbody>
                @forelse ($briefings as $briefing)
                    @php
                        $latestReport = $briefing->generatedReports->first();
                        $localTimezone = $briefing->timezone ?: 'UTC';
                    @endphp
                    <x-data-table.row>
                        <x-data-table.cell label="Schedule">
                            <div class="font-medium text-textPrimary">{{ str($briefing->frequency)->headline() }}</div>
                            <p class="text-xs text-textSecondary">
                                @if ($briefing->frequency === 'weekly')
                                    {{ $daysOfWeek[(int) $briefing->day_of_week] ?? 'Monday' }}
                                @else
                                    Day {{ $briefing->day_of_month ?: 1 }}
                                @endif
                                · {{ $localTimezone }}
                            </p>
                        </x-data-table.cell>
                        <x-data-table.cell label="Report">
                            {{ $reportTypes[$briefing->report_type]['label'] ?? str($briefing->report_type)->headline() }}
                            <p class="text-xs text-textSecondary">{{ $briefing->clientSite?->name ?: 'All sites' }} · {{ $briefing->market_pack_key ?: 'All markets' }}</p>
                        </x-data-table.cell>
                        <x-data-table.cell label="Last run">
                            {{ $briefing->last_generated_at ? $briefing->last_generated_at->copy()->timezone($localTimezone)->format('Y-m-d H:i') : '-' }}
                        </x-data-table.cell>
                        <x-data-table.cell label="Next run">
                            {{ $briefing->next_run_at ? $briefing->next_run_at->copy()->timezone($localTimezone)->format('Y-m-d H:i') : '-' }}
                        </x-data-table.cell>
                        <x-data-table.cell label="Generated reports">
                            @if ($latestReport)
                                <a href="{{ route('app.page-intelligence.reports.show', $latestReport) }}" class="text-sm font-medium text-textPrimary hover:underline">{{ $latestReport->title }}</a>
                                <p class="text-xs text-textSecondary">{{ $latestReport->generated_at?->diffForHumans() }}</p>
                            @else
                                <span class="text-sm text-textSecondary">No snapshots yet</span>
                            @endif
                        </x-data-table.cell>
                        <x-data-table.cell label="Status">
                            <span class="inline-flex rounded-full border px-2 py-1 text-xs {{ $briefing->is_active ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-border bg-surfaceMuted text-textSecondary' }}">
                                {{ $briefing->is_active ? 'Active' : 'Paused' }}
                            </span>
                        </x-data-table.cell>
                        <x-data-table.cell label="Actions">
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ route('app.page-intelligence.scheduled-briefings.edit', $briefing) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Edit</a>
                                @if ($briefing->is_active)
                                    <form method="POST" action="{{ route('app.page-intelligence.scheduled-briefings.deactivate', $briefing) }}">
                                        @csrf
                                        <button class="rounded-md border border-border px-3 py-2 text-sm text-textSecondary hover:bg-surfaceSubtle">Deactivate</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('app.page-intelligence.scheduled-briefings.activate', $briefing) }}">
                                        @csrf
                                        <button class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Activate</button>
                                    </form>
                                @endif
                            </div>
                        </x-data-table.cell>
                    </x-data-table.row>
                @empty
                    <x-data-table.empty colspan="7" title="No scheduled briefings" description="Create a schedule when the workspace is ready for recurring report snapshots." />
                @endforelse
            </tbody>
        </x-data-table>

        {{ $briefings->links() }}
    </div>
@endsection
