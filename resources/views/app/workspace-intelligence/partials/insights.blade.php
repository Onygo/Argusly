<div class="space-y-6" id="pending-runs">
    <x-workspace-intelligence.card
        title="Enrichment runs"
        description="Review what changed, compare proposals with the current approved profile, and apply only the parts you want."
        icon="sparkles"
    >
        <div class="space-y-4">
            @forelse (($hub['runs']['cards'] ?? []) as $run)
                <details class="group rounded-lg border border-border bg-background p-4" @if($run['is_actionable']) open @endif>
                    <summary class="flex cursor-pointer list-none items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-base font-semibold text-textPrimary">{{ $run['type_label'] }}</h3>
                                <x-workspace-intelligence.status-badge :label="$run['status']['label']" :tone="$run['status']['tone']" :icon="$run['status']['icon']" />
                            </div>
                            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-textSecondary">
                                <span>{{ $run['created_at_label'] }}</span>
                                <span>{{ $run['source_type_label'] }}</span>
                                <span>Progress {{ $run['progress_label'] }}</span>
                                @if ($run['created_at_human'])
                                    <span>{{ $run['created_at_human'] }}</span>
                                @endif
                            </div>
                        </div>
                        <i data-lucide="chevron-down" class="mt-1 h-4 w-4 shrink-0 text-textMuted transition group-open:rotate-180"></i>
                    </summary>

                    <div class="mt-4 space-y-4 border-t border-border pt-4">
                        @if ($run['error_message'])
                            <x-alert variant="error">{{ $run['error_message'] }}</x-alert>
                        @endif

                        <div class="grid gap-4 lg:grid-cols-[0.8fr,1.2fr]">
                            <div class="rounded-lg border border-border bg-surface px-4 py-4">
                                <h4 class="text-[11px] font-semibold uppercase tracking-[0.16em] text-textMuted">Source</h4>
                                <p class="mt-2 text-sm font-medium text-textPrimary">{{ $run['source_type_label'] }}</p>
                                <x-workspace-intelligence.list class="mt-3" :items="$run['source_summary'] ?? []" empty="No source details captured." />
                            </div>

                            <div class="rounded-lg border border-border bg-surface px-4 py-4">
                                <h4 class="text-[11px] font-semibold uppercase tracking-[0.16em] text-textMuted">Proposal</h4>
                                <p class="mt-2 text-sm font-medium text-textPrimary">{{ data_get($run, 'proposal.summary', 'No proposal details.') }}</p>
                            </div>
                        </div>

                        @if (($run['proposal']['items'] ?? []) !== [])
                            <details class="group rounded-lg border border-border bg-surface">
                                <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3">
                                    <span class="text-sm font-medium text-textPrimary">Compare with current</span>
                                    <i data-lucide="chevron-down" class="h-4 w-4 text-textMuted transition group-open:rotate-180"></i>
                                </summary>
                                <div class="space-y-3 border-t border-border px-4 py-4">
                                    @foreach (($run['proposal']['items'] ?? []) as $item)
                                        <div class="space-y-2">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <h5 class="text-sm font-semibold text-textPrimary">{{ $item['label'] }}</h5>
                                                @if (($item['meta'] ?? '') !== '')
                                                    <span class="text-xs uppercase tracking-[0.16em] text-textMuted">{{ $item['meta'] }}</span>
                                                @endif
                                            </div>
                                            @include('app.workspace-intelligence.partials.proposal-comparison', [
                                                'current' => $item['current'] ?? [],
                                                'proposed' => $item['proposed'] ?? [],
                                            ])
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endif

                        @if ($run['is_actionable'])
                            @if (($run['proposal']['kind'] ?? '') === 'organization')
                                <form method="POST" action="{{ route('app.workspace-intelligence.runs.approve', $run['id']) }}" class="space-y-3">
                                    @csrf
                                    <div class="grid gap-3 md:grid-cols-2">
                                        @foreach (($run['proposal']['items'] ?? []) as $item)
                                            <label class="rounded-lg border border-border bg-surface px-4 py-3 text-sm">
                                                <span class="flex items-start gap-3">
                                                    <input class="mt-1" type="checkbox" name="sections[]" value="{{ $item['key'] }}" checked>
                                                    <span>
                                                        <span class="block font-medium text-textPrimary">{{ $item['label'] }}</span>
                                                        @if (($item['proposed']['text'] ?? '') !== '')
                                                            <span class="mt-2 block text-textSecondary">{{ $item['proposed']['text'] }}</span>
                                                        @endif
                                                        <div class="mt-2">
                                                            <x-workspace-intelligence.list :items="$item['proposed']['items'] ?? []" empty="No proposal details." />
                                                        </div>
                                                    </span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <label class="flex items-center gap-2 text-sm text-textSecondary">
                                        <input type="checkbox" name="replace_existing" value="1">
                                        Replace existing approved sections
                                    </label>
                                    <div class="flex flex-wrap gap-2">
                                        <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Apply changes</button>
                                        <button formaction="{{ route('app.workspace-intelligence.runs.reject', $run['id']) }}" class="inline-flex items-center justify-center rounded-md border border-border px-4 py-2 text-sm font-medium text-textPrimary transition hover:bg-surfaceSubtle">Reject proposal</button>
                                    </div>
                                </form>
                            @elseif (($run['proposal']['kind'] ?? '') === 'personas')
                                <form method="POST" action="{{ route('app.workspace-intelligence.runs.approve', $run['id']) }}" class="space-y-3">
                                    @csrf
                                    <div class="space-y-3">
                                        @foreach (($run['proposal']['items'] ?? []) as $item)
                                            <label class="rounded-lg border border-border bg-surface px-4 py-3 text-sm">
                                                <span class="flex items-start gap-3">
                                                    <input class="mt-1" type="checkbox" name="persona_indexes[]" value="{{ $item['key'] }}" checked>
                                                    <span>
                                                        <span class="block font-medium text-textPrimary">{{ $item['label'] }}</span>
                                                        @if (($item['meta'] ?? '') !== '')
                                                            <span class="block text-xs uppercase tracking-[0.16em] text-textMuted">{{ $item['meta'] }}</span>
                                                        @endif
                                                        @if (($item['proposed']['text'] ?? '') !== '')
                                                            <span class="mt-2 block text-textSecondary">{{ $item['proposed']['text'] }}</span>
                                                        @endif
                                                        <div class="mt-2">
                                                            <x-workspace-intelligence.list :items="$item['proposed']['items'] ?? []" empty="No persona details." />
                                                        </div>
                                                    </span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Apply changes</button>
                                        <button formaction="{{ route('app.workspace-intelligence.runs.reject', $run['id']) }}" class="inline-flex items-center justify-center rounded-md border border-border px-4 py-2 text-sm font-medium text-textPrimary transition hover:bg-surfaceSubtle">Reject proposal</button>
                                    </div>
                                </form>
                            @elseif (($run['proposal']['kind'] ?? '') === 'team')
                                <form method="POST" action="{{ route('app.workspace-intelligence.runs.approve', $run['id']) }}" class="space-y-3">
                                    @csrf
                                    <div class="grid gap-3 md:grid-cols-2">
                                        @foreach (($run['proposal']['items'] ?? []) as $item)
                                            <label class="rounded-lg border border-border bg-surface px-4 py-3 text-sm">
                                                <span class="flex items-start gap-3">
                                                    <input class="mt-1" type="checkbox" name="sections[]" value="{{ $item['key'] }}" checked>
                                                    <span>
                                                        <span class="block font-medium text-textPrimary">{{ $item['label'] }}</span>
                                                        @if (($item['proposed']['text'] ?? '') !== '')
                                                            <span class="mt-2 block text-textSecondary">{{ $item['proposed']['text'] }}</span>
                                                        @endif
                                                        <div class="mt-2">
                                                            <x-workspace-intelligence.list :items="$item['proposed']['items'] ?? []" empty="No profile details." />
                                                        </div>
                                                    </span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <label class="flex items-center gap-2 text-sm text-textSecondary">
                                        <input type="checkbox" name="replace_existing" value="1">
                                        Replace existing approved sections
                                    </label>
                                    <div class="flex flex-wrap gap-2">
                                        <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Apply changes</button>
                                        <button formaction="{{ route('app.workspace-intelligence.runs.reject', $run['id']) }}" class="inline-flex items-center justify-center rounded-md border border-border px-4 py-2 text-sm font-medium text-textPrimary transition hover:bg-surfaceSubtle">Reject proposal</button>
                                    </div>
                                </form>
                            @endif
                        @else
                            <div class="rounded-lg border border-border bg-surface px-4 py-3 text-sm text-textSecondary">
                                @if (($run['status']['label'] ?? '') === 'Approved' && $run['approved_at_label'])
                                    Applied on {{ $run['approved_at_label'] }}.
                                @elseif (($run['status']['label'] ?? '') === 'Rejected')
                                    This proposal was rejected and kept for history.
                                @elseif (($run['status']['label'] ?? '') === 'Failed')
                                    This run failed before a usable proposal could be reviewed.
                                @else
                                    This run is currently informational only.
                                @endif
                            </div>
                        @endif
                    </div>
                </details>
            @empty
                <div class="rounded-lg border border-dashed border-border bg-background px-4 py-10 text-sm text-textMuted">
                    No enrichment runs yet. Start one from the Brand, Personas, or Team tab.
                </div>
            @endforelse
        </div>
    </x-workspace-intelligence.card>
</div>
