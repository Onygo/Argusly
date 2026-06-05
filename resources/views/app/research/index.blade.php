@extends('layouts.app', ['title' => 'Research'])

@section('content')
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Research projects</h1>
            <p class="mt-1 text-textSecondary">Workspace: {{ $workspace->display_name }}</p>
        </div>
        @if ($canCreate)
            <a href="{{ route('app.research.create', ['workspace_id' => $workspace->id]) }}" class="rounded border border-border px-3 py-2 text-sm">New research project</a>
        @endif
    </div>

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first() }}</div>
    @endif

    <div class="rounded-lg border border-border bg-surface p-4">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm text-textPrimary">
                <thead>
                    <tr class="text-left text-xs text-textSecondary">
                        <th class="pb-2 font-medium">Project</th>
                        <th class="pb-2 font-medium">Status</th>
                        <th class="pb-2 font-medium">Linked context</th>
                        <th class="pb-2 font-medium">Sources</th>
                        <th class="pb-2 font-medium">Findings</th>
                        <th class="pb-2 font-medium">Created</th>
                        <th class="pb-2 font-medium">Quick actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($projects as $project)
                        <tr class="border-t border-border/70">
                            <td class="py-2">
                                <a href="{{ route('app.research.show', $project) }}" class="font-medium hover:underline">{{ $project->name }}</a>
                            </td>
                            <td class="py-2">
                                <span class="pl-badge">{{ $project->status?->value ?? $project->status }}</span>
                            </td>
                            <td class="py-2 text-xs text-textSecondary">
                                @if ($project->brief)
                                    Brief: {{ $project->brief->title }}
                                @elseif ($project->clientSite)
                                    Site: {{ $project->clientSite->name }}
                                @else
                                    -
                                @endif
                            </td>
                            <td class="py-2">{{ (int) $project->sources_count }}</td>
                            <td class="py-2">{{ (int) $project->findings_count }}</td>
                            <td class="py-2">{{ optional($project->created_at)->toDateTimeString() }}</td>
                            <td class="py-2">
                                <div class="flex flex-wrap gap-2">
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
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-3 text-textSecondary">No research projects yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($projects->hasPages())
            <div class="mt-4">{{ $projects->links() }}</div>
        @endif
    </div>
@endsection
