@php
    $run = $run ?? null;
    $summary = trim((string) data_get($run?->output_payload ?? [], 'summary', $run?->summary ?? ''));
    $suggestions = collect((array) data_get($run?->output_payload ?? [], 'suggestions', []))->filter()->values();
    $warnings = collect((array) data_get($run?->output_payload ?? [], 'warnings', []))->filter()->values();
    $actions = collect((array) data_get($run?->output_payload ?? [], 'actions', []))->filter()->values();
    $status = $run?->status instanceof \App\Agents\Support\AgentRunStatus ? $run->status->value : (string) ($run?->status ?? '');

    $renderItem = function (mixed $item): array {
        if (is_array($item)) {
            return [
                'title' => trim((string) ($item['title'] ?? $item['label'] ?? $item['action'] ?? 'Item')),
                'description' => trim((string) ($item['description'] ?? $item['reason'] ?? $item['note'] ?? '')),
                'href' => trim((string) ($item['href'] ?? '')),
            ];
        }

        return [
            'title' => trim((string) $item),
            'description' => '',
            'href' => '',
        ];
    };
@endphp

<div class="rounded-lg border border-border bg-surface p-4">
    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
        <div>
            <h3 class="text-sm font-semibold text-textPrimary">{{ $title }}</h3>
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
            <form method="POST" action="{{ $action }}">
                @csrf
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

            @foreach ([
                'Suggestions' => $suggestions,
                'Warnings' => $warnings,
                'Actions' => $actions,
            ] as $sectionLabel => $items)
                @if ($items->isNotEmpty())
                    <div>
                        <div class="text-xs uppercase tracking-wide text-textFaint">{{ $sectionLabel }}</div>
                        <div class="mt-2 space-y-2">
                            @foreach ($items as $item)
                                @php($entry = $renderItem($item))
                                <div class="rounded-md border border-border bg-background px-3 py-2">
                                    <div class="text-sm font-medium text-textPrimary">{{ $entry['title'] }}</div>
                                    @if ($entry['description'] !== '')
                                        <div class="mt-1 text-xs text-textSecondary">{{ $entry['description'] }}</div>
                                    @endif
                                    @if ($entry['href'] !== '')
                                        <div class="mt-2">
                                            <a href="{{ $entry['href'] }}" class="text-xs text-link underline">Open</a>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    @else
        <p class="mt-4 text-sm text-textSecondary">{{ $emptyState }}</p>
    @endif
</div>
