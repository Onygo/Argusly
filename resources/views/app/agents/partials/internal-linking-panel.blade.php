@php
    $run = $run ?? null;
    $currentTab = $currentTab ?? null;
    $status = $run?->status instanceof \App\Agents\Support\AgentRunStatus ? $run->status->value : (string) ($run?->status ?? '');
    $summary = trim((string) data_get($run?->output_payload ?? [], 'summary', $run?->summary ?? ''));
    $suggestions = collect((array) data_get($run?->output_payload ?? [], 'suggestions', []))
        ->filter(fn (mixed $item): bool => is_array($item))
        ->values();
    $warnings = collect((array) data_get($run?->output_payload ?? [], 'warnings', []))
        ->filter(fn (mixed $item): bool => is_array($item))
        ->values();
    $actions = collect((array) data_get($run?->output_payload ?? [], 'actions', []))
        ->filter(fn (mixed $item): bool => is_array($item))
        ->values();
@endphp

<div class="rounded-lg border border-border bg-surface p-4">
    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
        <div>
            <h3 class="text-sm font-semibold text-textPrimary">Suggested internal links</h3>
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
            @if ($summary !== '')
                <div>
                    <div class="text-xs uppercase tracking-wide text-textFaint">Summary</div>
                    <p class="mt-1 text-sm text-textPrimary">{{ $summary }}</p>
                </div>
            @endif

            @if ($actions->isNotEmpty())
                <div class="space-y-2">
                    @foreach ($actions as $action)
                        <div class="rounded-md border border-border bg-background px-3 py-2">
                            <div class="text-sm font-medium text-textPrimary">{{ data_get($action, 'title', 'Action') }}</div>
                            @if (trim((string) data_get($action, 'description', '')) !== '')
                                <div class="mt-1 text-xs text-textSecondary">{{ data_get($action, 'description') }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($suggestions->isNotEmpty())
                <div class="space-y-3">
                    @foreach ($suggestions as $index => $suggestion)
                        @php
                            $confidence = data_get($suggestion, 'confidence_score');
                            $isApplied = filled(data_get($suggestion, 'applied_at'));
                        @endphp
                        <div class="rounded-md border border-border bg-background p-3">
                            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                <div class="min-w-0">
                                    <div class="text-sm font-medium text-textPrimary">
                                        {{ data_get($suggestion, 'anchor_text', 'Suggested anchor') }}
                                    </div>
                                    <div class="mt-1 text-sm text-textSecondary">
                                        Target article:
                                        @if (trim((string) data_get($suggestion, 'target_url', '')) !== '')
                                            <a href="{{ data_get($suggestion, 'target_url') }}" class="text-link underline" target="_blank" rel="noopener">
                                                {{ data_get($suggestion, 'target_title', 'Untitled target') }}
                                            </a>
                                        @else
                                            <span class="text-textPrimary">{{ data_get($suggestion, 'target_title', 'Untitled target') }}</span>
                                        @endif
                                    </div>
                                    <div class="mt-2 text-xs text-textSecondary">{{ data_get($suggestion, 'reason', 'Suggested from same-site topic overlap.') }}</div>
                                    <div class="mt-2 flex flex-wrap gap-3 text-[11px] text-textSecondary">
                                        @if (is_numeric($confidence))
                                            <span>Confidence {{ number_format((float) $confidence, 2) }}</span>
                                        @endif
                                        @if (trim((string) data_get($suggestion, 'insertion_hint', '')) !== '')
                                            <span>Insertion hint {{ data_get($suggestion, 'insertion_hint') }}</span>
                                        @endif
                                        @if ($isApplied)
                                            <span class="text-emerald-700">Applied {{ \Illuminate\Support\Carbon::parse((string) data_get($suggestion, 'applied_at'))->diffForHumans() }}</span>
                                        @endif
                                    </div>
                                </div>
                                @can($ability, $resource)
                                    @if (! $isApplied)
                                        <form method="POST" action="{{ $applyAction }}">
                                            @csrf
                                            <input type="hidden" name="agent_run_id" value="{{ $run->id }}">
                                            <input type="hidden" name="suggestion_index" value="{{ $index }}">
                                            @if ($currentTab)
                                                <input type="hidden" name="tab" value="{{ $currentTab }}">
                                            @endif
                                            <button class="inline-flex items-center rounded-md border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                                                Apply suggestion
                                            </button>
                                        </form>
                                    @else
                                        <span class="inline-flex items-center rounded-md bg-emerald-500/10 px-3 py-2 text-sm font-medium text-emerald-700">Applied</span>
                                    @endif
                                @endcan
                            </div>
                        </div>
                    @endforeach
                </div>
            @elseif ($warnings->isEmpty())
                <p class="text-sm text-textSecondary">{{ $emptyState }}</p>
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
