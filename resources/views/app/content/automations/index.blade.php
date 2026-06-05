@extends('layouts.app', ['title' => 'Automations'])

@section('content')
    <x-app.content-area-header mode="automations">
        <a href="{{ route('app.content.automations.create', array_filter(['workspace' => $selectedWorkspaceId, 'site' => $selectedSiteId])) }}" class="rounded border border-border bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90">New automation</a>
    </x-app.content-area-header>

    @if (session('status'))
        <x-alert class="my-4">{{ session('status') }}</x-alert>
    @endif

    <div class="mt-6 mb-4 rounded-lg border border-border bg-surface p-4">
        <form method="GET" action="{{ route('app.content.automations.index') }}" class="grid gap-3 md:grid-cols-4">
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Workspace</label>
                <select name="workspace" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
                    <option value="">All workspaces</option>
                    @foreach ($workspaces as $workspace)
                        <option value="{{ $workspace->id }}" @selected($selectedWorkspaceId === (string) $workspace->id)>{{ $workspace->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Client site</label>
                <select name="site" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
                    <option value="">All sites</option>
                    @foreach ($sites as $site)
                        <option value="{{ $site->id }}" @selected($selectedSiteId === (string) $site->id)>{{ $site->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end gap-2 md:col-span-2">
                <button class="rounded border border-border px-4 py-2 text-sm">Filter</button>
                <a href="{{ route('app.content.automations.index') }}" class="rounded border border-border px-4 py-2 text-sm">Reset</a>
            </div>
        </form>
    </div>

    <div class="space-y-4">
        @forelse ($automations as $automation)
            @php
                $statusLabel = ucfirst($automation->lifecycleStatus());
                $latestRun = $automation->latestRun;
                $latestFailureItem = $latestRun?->items?->filter(fn ($item) => filled($item->last_error_message))->sortByDesc('updated_at')->first();
                $latestFailureMessage = $automation->last_failure_message ?: ($latestFailureItem?->last_error_message ?: $latestRun?->error_message);
            @endphp
            <div class="rounded-lg border border-border bg-surface p-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <a href="{{ route('app.content.automations.show', $automation) }}" class="text-base font-semibold text-textPrimary hover:underline">{{ $automation->name }}</a>
                            <span class="rounded border border-border px-2 py-0.5 text-xs text-textSecondary">{{ $statusLabel }}</span>
                            <span class="rounded border border-border px-2 py-0.5 text-xs text-textSecondary">{{ $automation->publication_mode?->label() ?? $automation->publication_mode }}</span>
                        </div>
                        <p class="mt-2 text-sm text-textSecondary">{{ \Illuminate\Support\Str::limit($automation->topic_scope, 180) }}</p>
                        @if ($latestRun)
                            <p class="mt-2 text-xs text-textSecondary">Last run: <span class="font-mono">{{ $latestRun->id }}</span> · {{ $latestRun->status?->label() ?? $latestRun->status }}</p>
                        @endif
                        @if ($latestFailureMessage)
                            <p class="mt-1 text-sm text-rose-800">Last failure: {{ \Illuminate\Support\Str::limit($latestFailureMessage, 180) }}</p>
                            @if ($latestRun)
                                <a href="{{ route('app.content.automations.show', $automation) }}#run-{{ $latestRun->id }}" class="mt-1 inline-block text-xs text-rose-700 hover:underline">View run details</a>
                            @endif
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if ($automation->isActive())
                            <form method="POST" action="{{ route('app.content.automations.run', $automation) }}">
                                @csrf
                                <button class="rounded border border-border px-3 py-1.5 text-xs">Run now</button>
                            </form>
                        @endif
                        <a href="{{ route('app.content.automations.edit', $automation) }}" class="rounded border border-border px-3 py-1.5 text-xs">Edit</a>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 md:grid-cols-4">
                    <div class="rounded border border-border bg-background p-3">
                        <p class="text-xs text-textSecondary">Cadence</p>
                        <p class="mt-1 text-sm font-medium text-textPrimary">Every {{ $automation->generation_frequency_value }} {{ $automation->generation_frequency_unit?->label() ?? $automation->generation_frequency_unit }}</p>
                    </div>
                    <div class="rounded border border-border bg-background p-3">
                        <p class="text-xs text-textSecondary">Mode</p>
                        <p class="mt-1 text-sm font-medium text-textPrimary">{{ $automation->mode?->label() ?? $automation->mode }} · {{ $automation->chain_size }} item(s)</p>
                    </div>
                    <div class="rounded border border-border bg-background p-3">
                        <p class="text-xs text-textSecondary">Site</p>
                        <p class="mt-1 text-sm font-medium text-textPrimary">{{ $automation->clientSite?->name ?? $automation->workspace?->name ?? 'Unknown scope' }}</p>
                    </div>
                    <div class="rounded border border-border bg-background p-3">
                        <p class="text-xs text-textSecondary">Next run</p>
                        <p class="mt-1 text-sm font-medium text-textPrimary">{{ optional($automation->next_run_at)->diffForHumans() ?? 'Not scheduled' }}</p>
                    </div>
                    <div class="rounded border border-border bg-background p-3">
                        <p class="text-xs text-textSecondary">Last run</p>
                        <p class="mt-1 text-sm font-medium text-textPrimary">{{ optional($automation->last_run_at)->diffForHumans() ?? 'Never' }}</p>
                    </div>
                    <div class="rounded border border-border bg-background p-3">
                        <p class="text-xs text-textSecondary">Run count</p>
                        <p class="mt-1 text-sm font-medium text-textPrimary">{{ (int) $automation->run_count }} / {{ $automation->max_runs ?? 'unlimited' }}</p>
                    </div>
                    <div class="rounded border border-border bg-background p-3">
                        <p class="text-xs text-textSecondary">Generated</p>
                        <p class="mt-1 text-sm font-medium text-textPrimary">{{ $automation->contents_count ?? 0 }} items</p>
                    </div>
                    @php
                        $failedRuns = $automation->runs->filter(fn ($run) => $run->status?->value === 'failed' || $run->status?->value === 'partial')->count();
                        $failedItems = $automation->runs->flatMap(fn ($run) => $run->items)->filter(fn ($item) => in_array((string) $item->status, ['failed', 'partial'], true))->count();
                    @endphp
                    <div class="rounded border border-border bg-background p-3 {{ $failedRuns > 0 ? 'border-amber-300' : '' }}">
                        <p class="text-xs text-textSecondary">Issues</p>
                        <p class="mt-1 text-sm font-medium {{ $failedRuns > 0 ? 'text-amber-600' : 'text-textPrimary' }}">
                            @if ($failedRuns > 0)
                                {{ $failedRuns }} run(s), {{ $failedItems }} item(s)
                            @else
                                None
                            @endif
                        </p>
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-dashed border-border bg-surface px-4 py-8 text-center text-sm text-textSecondary">
                No content automations found for the current filter.
            </div>
        @endforelse
    </div>

    @if (method_exists($automations, 'links'))
        <div class="mt-6">
            {{ $automations->links() }}
        </div>
    @endif
@endsection
