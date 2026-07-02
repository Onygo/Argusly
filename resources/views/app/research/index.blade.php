@extends('layouts.app', ['title' => 'Research'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Research projects</x-slot:title>
        <x-slot:description>Workspace: {{ $workspace->display_name }}</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
        @if ($canCreate)
            <a href="{{ route('app.research.create', ['workspace_id' => $workspace->id]) }}" class="rounded border border-border px-3 py-2 text-sm">New research project</a>
        @endif
@endsection

@section('content')

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first() }}</div>
    @endif

    <x-data-table label="Research projects" description="Research projects with status, linked context, source and finding counts, creation time, and quick actions.">
        <x-data-table.header>
            <x-data-table.row>
                <x-data-table.cell heading>Project</x-data-table.cell>
                <x-data-table.cell heading>Status</x-data-table.cell>
                <x-data-table.cell heading>Linked context</x-data-table.cell>
                <x-data-table.cell heading>Sources</x-data-table.cell>
                <x-data-table.cell heading>Findings</x-data-table.cell>
                <x-data-table.cell heading>Created</x-data-table.cell>
                <x-data-table.cell heading>Quick actions</x-data-table.cell>
            </x-data-table.row>
        </x-data-table.header>
        <tbody>
            @forelse ($projects as $project)
                <x-data-table.row>
                    <x-data-table.cell label="Project">
                        <a href="{{ route('app.research.show', $project) }}" class="font-medium hover:underline">{{ $project->name }}</a>
                    </x-data-table.cell>
                    <x-data-table.cell label="Status">
                        <x-data-table.badge :tone="($project->status?->value ?? $project->status) === 'failed' ? 'danger' : 'neutral'" :label="$project->status?->value ?? $project->status" />
                    </x-data-table.cell>
                    <x-data-table.cell label="Linked context" class="text-xs text-textSecondary">
                        @if ($project->brief)
                            Brief: {{ $project->brief->title }}
                        @elseif ($project->clientSite)
                            Site: {{ $project->clientSite->name }}
                        @else
                            -
                        @endif
                    </x-data-table.cell>
                    <x-data-table.cell label="Sources">{{ (int) $project->sources_count }}</x-data-table.cell>
                    <x-data-table.cell label="Findings">{{ (int) $project->findings_count }}</x-data-table.cell>
                    <x-data-table.cell label="Created">{{ optional($project->created_at)->toDateTimeString() }}</x-data-table.cell>
                    <x-data-table.cell label="Quick actions">
                        <x-data-table.actions align="start">
                            <a href="{{ route('app.research.show', $project) }}" class="rounded border border-border px-2 py-1 text-xs">Open</a>
                            @if (in_array((string) ($project->status?->value ?? $project->status), ['draft', 'failed'], true))
                                <form method="POST" action="{{ route('app.research.start', $project) }}">
                                    @csrf
                                    @if (($project->status?->value ?? $project->status) === 'failed')
                                        <input type="hidden" name="force" value="1">
                                    @endif
                                    <button class="rounded border border-border px-2 py-1 text-xs">
                                        {{ ($project->status?->value ?? $project->status) === 'failed' ? 'Rerun' : 'Start' }}
                                    </button>
                                </form>
                            @endif
                        </x-data-table.actions>
                    </x-data-table.cell>
                </x-data-table.row>
            @empty
                <x-data-table.empty colspan="7" title="No research projects yet" />
            @endforelse
        </tbody>
        @if ($projects->hasPages())
            <x-slot:pagination>{{ $projects->links() }}</x-slot:pagination>
        @endif
    </x-data-table>
@endsection
