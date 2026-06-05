@php
    $run = $run ?? null;
    $currentTab = $currentTab ?? null;
    $status = $run?->status instanceof \App\Agents\Support\AgentRunStatus ? $run->status->value : (string) ($run?->status ?? '');
    $summary = trim((string) data_get($run?->output_payload ?? [], 'summary', $run?->summary ?? ''));
    $refreshScore = (int) data_get($run?->output_payload ?? [], 'metrics.refresh_score', 0);
    $urgency = (string) data_get($run?->output_payload ?? [], 'metrics.urgency_level', data_get($run?->output_payload ?? [], 'raw_payload.urgency_level', 'low'));
    $reasons = collect((array) data_get($run?->output_payload ?? [], 'raw_payload.reasons', []))
        ->filter(fn (mixed $item): bool => is_array($item))
        ->values();
    $actions = collect((array) data_get($run?->output_payload ?? [], 'raw_payload.suggested_actions', []))
        ->filter(fn (mixed $item): bool => is_array($item))
        ->values();
    $warnings = collect((array) data_get($run?->output_payload ?? [], 'warnings', []))
        ->filter(fn (mixed $item): bool => is_array($item))
        ->values();
@endphp

<div class="rounded-lg border border-border bg-surface p-4">
    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
        <div>
            <h3 class="text-sm font-semibold text-textPrimary">Refresh recommendations</h3>
            <p class="mt-1 text-sm text-textSecondary">{{ $description }}</p>
            @if ($run)
                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-textSecondary">
                    <span class="inline-flex items-center rounded px-2 py-1
                        {{ $status === 'success' ? 'bg-emerald-500/10 text-emerald-700' : '' }}
                        {{ $status === 'warning' ? 'bg-amber-500/10 text-amber-700' : '' }}
                        {{ $status === 'failed' ? 'bg-rose-500/10 text-rose-700' : '' }}
                        {{ $status === 'skipped' ? 'bg-slate-500/10 text-slate-700' : '' }}">
                        {{ $status !== '' ? ucfirst($status) : 'Completed' }}
                    </span>
                    <span>Updated {{ $run->finished_at?->diffForHumans() ?? $run->created_at?->diffForHumans() }}</span>
                </div>
            @endif
        </div>
        @can($ability, $resource)
            <form method="POST" action="{{ $runAction }}">
                @csrf
                @if ($currentTab)
                    <input type="hidden" name="tab" value="{{ $currentTab }}">
                @endif
                <button class="inline-flex items-center rounded-md border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                    {{ $buttonLabel }}
                </button>
            </form>
        @endcan
    </div>

    @if ($run)
        <div class="mt-4 space-y-4">
            <div class="grid gap-3 md:grid-cols-3">
                <div class="rounded-md border border-border bg-background px-3 py-3">
                    <div class="text-xs uppercase tracking-wide text-textFaint">Refresh Score</div>
                    <div class="mt-2 text-2xl font-semibold text-textPrimary">{{ $refreshScore }}/100</div>
                </div>
                <div class="rounded-md border border-border bg-background px-3 py-3">
                    <div class="text-xs uppercase tracking-wide text-textFaint">Urgency</div>
                    <div class="mt-2 text-sm font-medium capitalize text-textPrimary">{{ $urgency }}</div>
                </div>
                <div class="rounded-md border border-border bg-background px-3 py-3">
                    <div class="text-xs uppercase tracking-wide text-textFaint">Recommendation</div>
                    <div class="mt-2 text-sm text-textPrimary">{{ $summary !== '' ? $summary : 'No summary available.' }}</div>
                </div>
            </div>

            @can($draftAbility, $resource)
                <form method="POST" action="{{ $createDraftAction }}" class="rounded-md border border-border bg-background p-3">
                    @csrf
                    <input type="hidden" name="agent_run_id" value="{{ $run->id }}">
                    @if ($currentTab)
                        <input type="hidden" name="tab" value="{{ $currentTab }}">
                    @endif
                    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div>
                            <div class="text-sm font-medium text-textPrimary">Create refresh draft</div>
                            <div class="mt-1 text-xs text-textSecondary">Create a new editable draft from the current content state so refresh work stays under editorial review.</div>
                        </div>
                        <button class="inline-flex items-center rounded-md border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                            Create refresh draft
                        </button>
                    </div>
                </form>
            @endcan

            @if ($reasons->isNotEmpty())
                <div>
                    <div class="text-xs uppercase tracking-wide text-textFaint">Top Reasons</div>
                    <div class="mt-2 space-y-2">
                        @foreach ($reasons as $reason)
                            <div class="rounded-md border border-border bg-background px-3 py-2">
                                <div class="text-sm font-medium text-textPrimary">{{ data_get($reason, 'title', 'Reason') }}</div>
                                @if (trim((string) data_get($reason, 'description', '')) !== '')
                                    <div class="mt-1 text-xs text-textSecondary">{{ data_get($reason, 'description') }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($actions->isNotEmpty())
                <div>
                    <div class="text-xs uppercase tracking-wide text-textFaint">Suggested Actions</div>
                    <div class="mt-2 space-y-2">
                        @foreach ($actions as $action)
                            <div class="rounded-md border border-border bg-background px-3 py-2">
                                <div class="text-sm font-medium text-textPrimary">{{ data_get($action, 'title', 'Action') }}</div>
                                @if (trim((string) data_get($action, 'description', '')) !== '')
                                    <div class="mt-1 text-xs text-textSecondary">{{ data_get($action, 'description') }}</div>
                                @endif
                                @if (trim((string) data_get($action, 'href', '')) !== '')
                                    <div class="mt-2">
                                        <a href="{{ data_get($action, 'href') }}" class="text-xs text-link underline">Open</a>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($warnings->isNotEmpty())
                <div class="space-y-2">
                    @foreach ($warnings as $warning)
                        <div class="rounded-md border border-amber-500/20 bg-amber-500/5 px-3 py-2">
                            <div class="text-sm font-medium text-textPrimary">{{ data_get($warning, 'title', 'Note') }}</div>
                            @if (trim((string) data_get($warning, 'description', '')) !== '')
                                <div class="mt-1 text-xs text-textSecondary">{{ data_get($warning, 'description') }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @else
        <p class="mt-4 text-sm text-textSecondary">{{ $emptyState }}</p>
    @endif
</div>
