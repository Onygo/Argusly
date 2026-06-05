@php
    $run = $run ?? null;
    $summary = trim((string) data_get($run?->output_payload ?? [], 'summary', $run?->summary ?? ''));
    $suggestions = collect((array) data_get($run?->output_payload ?? [], 'suggestions', data_get($run?->output_payload ?? [], 'raw_payload.recommendations', [])))
        ->filter(fn (mixed $item): bool => is_array($item))
        ->values();
    $actions = collect((array) data_get($run?->output_payload ?? [], 'actions', data_get($run?->output_payload ?? [], 'raw_payload.actions', [])))
        ->filter(fn (mixed $item): bool => is_array($item))
        ->values();
    $status = $run?->status instanceof \App\Agents\Support\AgentRunStatus ? $run->status->value : (string) ($run?->status ?? '');
    $translationMode = $translationMode ?? 'content';
    $currentTab = $currentTab ?? null;

    $severityClasses = fn (string $severity): string => match ($severity) {
        'high' => 'bg-rose-500/10 text-rose-700',
        'medium' => 'bg-amber-500/10 text-amber-700',
        default => 'bg-slate-500/10 text-slate-700',
    };
@endphp

<div class="rounded-lg border border-border bg-surface p-4">
    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
        <div>
            <h3 class="text-sm font-semibold text-textPrimary">Localization recommendations</h3>
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

            @if ($suggestions->isNotEmpty())
                <div>
                    <div class="text-xs uppercase tracking-wide text-textFaint">Recommendations</div>
                    <div class="mt-2 space-y-3">
                        @foreach ($suggestions as $suggestion)
                            @php
                                $suggestionActions = collect((array) data_get($suggestion, 'actions', []))
                                    ->filter(fn (mixed $item): bool => is_array($item))
                                    ->values();
                                $severity = (string) data_get($suggestion, 'severity', 'low');
                            @endphp
                            <div class="rounded-md border border-border bg-background px-3 py-3">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <div class="text-sm font-medium text-textPrimary">{{ data_get($suggestion, 'title', 'Recommendation') }}</div>
                                        @if (trim((string) data_get($suggestion, 'description', '')) !== '')
                                            <div class="mt-1 text-xs text-textSecondary">{{ data_get($suggestion, 'description') }}</div>
                                        @endif
                                    </div>
                                    <span class="inline-flex items-center rounded px-2 py-1 text-[11px] font-medium uppercase {{ $severityClasses($severity) }}">
                                        {{ $severity }}
                                    </span>
                                </div>

                                @if ($suggestionActions->isNotEmpty())
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        @foreach ($suggestionActions as $action)
                                            @php($actionType = (string) data_get($action, 'type', ''))
                                            @php($actionLabel = trim((string) data_get($action, 'label', 'Open')))

                                            @if ($translationMode === 'content' && in_array($actionType, ['translate_content_locale', 'refresh_content_locale'], true))
                                                @can('update', $resource)
                                                    <form method="POST" action="{{ $translationAction }}">
                                                        @csrf
                                                        <input type="hidden" name="target_locale" value="{{ data_get($action, 'target_locale') }}">
                                                        <button class="inline-flex items-center rounded-md border border-border px-3 py-2 text-xs font-medium text-textPrimary hover:bg-surfaceSubtle">
                                                            {{ $actionLabel }}
                                                        </button>
                                                    </form>
                                                @endcan
                                            @elseif ($translationMode === 'draft' && $actionType === 'translate_draft_locale')
                                                @can('translate', $resource)
                                                    <form method="POST" action="{{ $translationAction }}">
                                                        @csrf
                                                        <input type="hidden" name="target_languages[]" value="{{ data_get($action, 'target_locale') }}">
                                                        <button class="inline-flex items-center rounded-md border border-border px-3 py-2 text-xs font-medium text-textPrimary hover:bg-surfaceSubtle">
                                                            {{ $actionLabel }}
                                                        </button>
                                                    </form>
                                                @endcan
                                            @elseif ($actionType === 'open_content' && trim((string) data_get($action, 'content_id', '')) !== '')
                                                <a href="{{ route('app.content.show', data_get($action, 'content_id')) }}" class="inline-flex items-center rounded-md border border-border px-3 py-2 text-xs font-medium text-textPrimary hover:bg-surfaceSubtle">
                                                    {{ $actionLabel }}
                                                </a>
                                            @elseif ($actionType === 'open_draft' && trim((string) data_get($action, 'draft_id', '')) !== '')
                                                <a href="{{ route('app.drafts.show', data_get($action, 'draft_id')) }}" class="inline-flex items-center rounded-md border border-border px-3 py-2 text-xs font-medium text-textPrimary hover:bg-surfaceSubtle">
                                                    {{ $actionLabel }}
                                                </a>
                                            @elseif (trim((string) data_get($action, 'href', '')) !== '')
                                                <a href="{{ data_get($action, 'href') }}" class="inline-flex items-center rounded-md border border-border px-3 py-2 text-xs font-medium text-textPrimary hover:bg-surfaceSubtle">
                                                    {{ $actionLabel }}
                                                </a>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($actions->isNotEmpty())
                <div>
                    <div class="text-xs uppercase tracking-wide text-textFaint">Suggested Next Steps</div>
                    <div class="mt-2 space-y-2">
                        @foreach ($actions as $action)
                            <div class="rounded-md border border-border bg-background px-3 py-2">
                                <div class="text-sm font-medium text-textPrimary">{{ data_get($action, 'title', data_get($action, 'label', 'Action')) }}</div>
                                @if (trim((string) data_get($action, 'description', '')) !== '')
                                    <div class="mt-1 text-xs text-textSecondary">{{ data_get($action, 'description') }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @else
        <p class="mt-4 text-sm text-textSecondary">{{ $emptyState }}</p>
    @endif
</div>
