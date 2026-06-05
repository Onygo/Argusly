@extends('layouts.app', ['title' => $project->name])

@section('content')
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">{{ $project->name }}</h1>
            <p class="mt-1 text-textSecondary">Status: {{ $project->status?->value ?? $project->status }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('app.research.index', ['workspace_id' => $project->workspace_id]) }}" class="rounded border border-border px-3 py-2 text-sm">Back</a>
            @if ($canRun)
                <form method="POST" action="{{ route('app.research.start', $project) }}">
                    @csrf
                    <button class="rounded border border-border px-3 py-2 text-sm">Start</button>
                </form>
                <form method="POST" action="{{ route('app.research.start', $project) }}">
                    @csrf
                    <input type="hidden" name="force" value="1">
                    <button class="rounded border border-border px-3 py-2 text-sm">Rerun</button>
                </form>
            @endif
        </div>
    </div>

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first() }}</div>
    @endif

    <div class="mb-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs text-textSecondary">Linked brief</p>
            <p class="mt-1 text-sm text-textPrimary">{{ $project->brief?->title ?? '-' }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs text-textSecondary">Linked site</p>
            <p class="mt-1 text-sm text-textPrimary">{{ $project->clientSite?->name ?? '-' }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs text-textSecondary">Sources</p>
            <p class="mt-1 text-sm text-textPrimary">{{ $project->sources->count() }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs text-textSecondary">Findings</p>
            <p class="mt-1 text-sm text-textPrimary">{{ $project->findings->count() }}</p>
        </div>
    </div>

    <div class="mb-4 rounded-lg border border-border bg-surface p-4">
        <h2 class="text-sm font-semibold text-textPrimary">Status timeline</h2>
        <div class="mt-3 grid gap-2 text-sm text-textSecondary md:grid-cols-2">
            <div>Created: {{ optional($project->created_at)->toDateTimeString() ?: '-' }}</div>
            <div>Started: {{ optional($project->started_at)->toDateTimeString() ?: '-' }}</div>
            <div>Completed: {{ optional($project->completed_at)->toDateTimeString() ?: '-' }}</div>
            <div>Failed: {{ optional($project->failed_at)->toDateTimeString() ?: '-' }}</div>
        </div>
        @if ($project->failure_reason)
            <p class="mt-3 text-sm text-rose-700">Failure reason: {{ $project->failure_reason }}</p>
        @endif
    </div>

    <div class="mb-4 rounded-lg border border-border bg-surface p-4">
        <h2 class="text-sm font-semibold text-textPrimary">Summary</h2>
        <div class="mt-2">
            <x-content.rendered-article :content="$project->human_summary ?: 'No summary available yet.'" compact />
        </div>
        @if (is_array($project->summary))
            <div class="mt-3 grid gap-2 text-xs text-textSecondary sm:grid-cols-2 lg:grid-cols-5">
                <div>Insights: {{ (int) data_get($project->summary, 'finding_counts.insights', 0) }}</div>
                <div>Statistics: {{ (int) data_get($project->summary, 'finding_counts.statistics', 0) }}</div>
                <div>Quotes: {{ (int) data_get($project->summary, 'finding_counts.quotes', 0) }}</div>
                <div>Entities: {{ (int) data_get($project->summary, 'finding_counts.entities', 0) }}</div>
                <div>Questions: {{ (int) data_get($project->summary, 'finding_counts.questions', 0) }}</div>
            </div>
        @endif
    </div>

    <div class="mb-4 rounded-lg border border-border bg-surface p-4">
        <h2 class="text-sm font-semibold text-textPrimary">Sources</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full text-sm text-textPrimary">
                <thead>
                    <tr class="text-left text-xs text-textSecondary">
                        <th class="pb-2 font-medium">Title / URL</th>
                        <th class="pb-2 font-medium">Type</th>
                        <th class="pb-2 font-medium">Fetch status</th>
                        <th class="pb-2 font-medium">Fetched at</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($project->sources as $source)
                        <tr class="border-t border-border/70">
                            <td class="py-2">
                                <div class="font-medium">{{ $source->title ?: '-' }}</div>
                                <div class="text-xs text-textSecondary break-all">{{ $source->url ?: '-' }}</div>
                            </td>
                            <td class="py-2">{{ $source->source_classification ?: ($source->source_type?->value ?? $source->source_type) }}</td>
                            <td class="py-2"><span class="pl-badge">{{ $source->fetch_status?->value ?? $source->fetch_status }}</span></td>
                            <td class="py-2">{{ optional($source->fetched_at)->toDateTimeString() ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-3 text-textSecondary">No sources found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mb-4 rounded-lg border border-border bg-surface p-4">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-textPrimary">Findings by type</h2>
            <button class="cursor-not-allowed rounded border border-border px-3 py-1 text-xs text-textSecondary" disabled>Create brief from research (later)</button>
        </div>

        <form method="POST" action="{{ route('app.research.findings.select', $project) }}" class="space-y-4">
            @csrf
            @foreach ($findingGroups as $type => $items)
                <div class="rounded border border-border bg-background p-3">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-textSecondary">{{ $type }} ({{ $items->count() }})</h3>
                    <div class="mt-2 space-y-2">
                        @forelse ($items as $finding)
                            <label class="flex items-start gap-3 text-sm">
                                <input type="checkbox" name="selected_finding_ids[]" value="{{ $finding->id }}" @checked($finding->is_selected)>
                                <div>
                                    <p class="text-textPrimary">{{ $finding->finding_text }}</p>
                                    <p class="mt-1 text-xs text-textSecondary">Confidence: {{ is_numeric($finding->confidence_score) ? number_format((float) $finding->confidence_score, 2) : '-' }}</p>
                                </div>
                            </label>
                        @empty
                            <p class="text-xs text-textSecondary">No findings in this category.</p>
                        @endforelse
                    </div>
                </div>
            @endforeach

            @if ($project->findings->isNotEmpty())
                <button class="rounded border border-border px-3 py-2 text-sm">Save selected findings</button>
            @endif
        </form>
    </div>

    <div class="rounded-lg border border-border bg-surface p-4">
        <h2 class="text-sm font-semibold text-textPrimary">Selected findings</h2>
        <div class="mt-2 space-y-2">
            @forelse ($selectedFindings as $finding)
                <p class="text-sm text-textPrimary">- {{ $finding->finding_text }}</p>
            @empty
                <p class="text-sm text-textSecondary">No selected findings yet.</p>
            @endforelse
        </div>
    </div>
@endsection
