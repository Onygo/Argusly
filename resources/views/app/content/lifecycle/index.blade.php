@extends('layouts.app', ['title' => 'Content Lifecycle', 'pageWidth' => 'wide'])

@section('content')
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-textPrimary">AI Content Operations</h1>
            <p class="mt-1 text-sm text-textSecondary">Workflow execution, content health, AI visibility, and refresh operations in one lifecycle surface.</p>
        </div>
        <div class="flex items-center gap-2">
            <form method="POST" action="{{ route('app.content.lifecycle.analyze') }}">
                @csrf
                <button type="submit" class="pl-btn-primary">
                    <i data-lucide="activity" class="h-4 w-4"></i>
                    <span>Run Analysis</span>
                </button>
            </form>
            <a href="{{ route('app.content.index') }}" class="pl-btn-ghost">
                <i data-lucide="list" class="h-4 w-4"></i>
                <span>List View</span>
            </a>
            <a href="{{ route('app.content.calendar') }}" class="pl-btn-ghost">
                <i data-lucide="calendar-days" class="h-4 w-4"></i>
                <span>Calendar</span>
            </a>
        </div>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    {{-- Filters --}}
    @include('app.content.lifecycle.partials.filters')

    <div class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <div class="rounded-lg border border-border bg-surface px-4 py-3">
            <div class="text-[11px] uppercase tracking-[0.18em] text-textSecondary">Avg Health</div>
            <div class="mt-2 flex items-end gap-2">
                <div class="text-2xl font-semibold text-textPrimary">{{ (int) ($operationsSummary['avg_health_score'] ?? 0) }}</div>
                <div class="text-xs text-textSecondary">/ 100</div>
            </div>
        </div>
        <div class="rounded-lg border border-border bg-surface px-4 py-3">
            <div class="text-[11px] uppercase tracking-[0.18em] text-textSecondary">AI Visibility</div>
            <div class="mt-2 flex items-end gap-2">
                <div class="text-2xl font-semibold text-textPrimary">{{ (int) ($operationsSummary['avg_ai_visibility_score'] ?? 0) }}</div>
                <div class="text-xs text-textSecondary">avg</div>
            </div>
        </div>
        <div class="rounded-lg border border-border bg-surface px-4 py-3">
            <div class="text-[11px] uppercase tracking-[0.18em] text-textSecondary">At Risk</div>
            <div class="mt-2 text-2xl font-semibold text-textPrimary">{{ (int) ($operationsSummary['at_risk_count'] ?? 0) }}</div>
        </div>
        <div class="rounded-lg border border-border bg-surface px-4 py-3">
            <div class="text-[11px] uppercase tracking-[0.18em] text-textSecondary">Refresh Queue</div>
            <div class="mt-2 text-2xl font-semibold text-textPrimary">{{ (int) ($operationsSummary['refresh_candidates'] ?? 0) }}</div>
        </div>
        <div class="rounded-lg border border-border bg-surface px-4 py-3">
            <div class="text-[11px] uppercase tracking-[0.18em] text-textSecondary">AI Optimized</div>
            <div class="mt-2 text-2xl font-semibold text-textPrimary">{{ (int) ($operationsSummary['ai_optimized_count'] ?? 0) }}</div>
        </div>
    </div>

    <div class="mt-6 grid gap-4 xl:grid-cols-[1.2fr_1fr]">
        <section class="rounded-lg border border-border bg-surface">
            <div class="flex items-center justify-between border-b border-border px-4 py-3">
                <div>
                    <h2 class="text-sm font-semibold text-textPrimary">Decay Alerts</h2>
                    <p class="text-xs text-textSecondary">Latest high-risk lifecycle analyses with explainable signal evidence.</p>
                </div>
                <a href="{{ route('app.content.lifecycle.index', ['decay_risk' => 'high']) }}" class="text-xs font-medium text-link hover:text-linkHover">Filter</a>
            </div>
            <div class="divide-y divide-border">
                @forelse ($decayAlerts as $alert)
                    @php
                        $content = $alert->content;
                        $risk = $alert->decay_risk_level;
                        $riskValue = $risk instanceof \App\Enums\ContentDecayRiskLevel ? $risk->value : (string) $risk;
                    @endphp
                    <div class="grid gap-3 px-4 py-3 md:grid-cols-[1fr_auto]">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full {{ $riskValue === 'critical' ? 'bg-rose-100 text-rose-700' : 'bg-orange-100 text-orange-700' }} px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.14em]">
                                    {{ $riskValue }}
                                </span>
                                @if ($content?->clientSite)
                                    <span class="text-xs text-textSecondary">{{ $content->clientSite->name }}</span>
                                @endif
                            </div>
                            <a href="{{ $content ? route('app.content.show', $content) : '#' }}" class="mt-1 block truncate text-sm font-medium text-textPrimary hover:text-link">
                                {{ $content?->title ?? 'Deleted content' }}
                            </a>
                            <div class="mt-2 flex flex-wrap gap-2 text-xs text-textSecondary">
                                <span>Decay {{ number_format((float) $alert->decay_score, 0) }}</span>
                                <span>Lifecycle {{ number_format((float) $alert->lifecycle_score, 0) }}</span>
                                <span>Confidence {{ number_format((float) $alert->confidence_score, 0) }}</span>
                            </div>
                        </div>
                        <div class="text-right text-xs text-textSecondary">
                            <div class="font-medium text-textPrimary">{{ number_format((float) $alert->refresh_priority_score, 0) }} priority</div>
                            <div>{{ optional($alert->analyzed_at)->diffForHumans() }}</div>
                        </div>
                    </div>
                @empty
                    <div class="px-4 py-8 text-center text-sm text-textSecondary">No high-risk decay alerts yet.</div>
                @endforelse
            </div>
        </section>

        <section class="rounded-lg border border-border bg-surface">
            <div class="border-b border-border px-4 py-3">
                <h2 class="text-sm font-semibold text-textPrimary">Content Health Indicators</h2>
                <p class="text-xs text-textSecondary">Operational signal counts for lifecycle triage.</p>
            </div>
            <div class="grid grid-cols-2 gap-3 p-4">
                <a href="{{ route('app.content.lifecycle.index', ['weak_internal_links' => 1]) }}" class="rounded-md border border-border bg-background px-3 py-3 hover:border-primary/40">
                    <div class="text-[11px] uppercase tracking-[0.16em] text-textSecondary">Weak Links</div>
                    <div class="mt-2 text-xl font-semibold text-textPrimary">{{ (int) ($healthIndicators['weak_internal_links'] ?? 0) }}</div>
                </a>
                <a href="{{ route('app.content.lifecycle.index', ['ai_visibility_range' => 'low']) }}" class="rounded-md border border-border bg-background px-3 py-3 hover:border-primary/40">
                    <div class="text-[11px] uppercase tracking-[0.16em] text-textSecondary">AI Decline</div>
                    <div class="mt-2 text-xl font-semibold text-textPrimary">{{ (int) ($healthIndicators['ai_visibility_decline'] ?? 0) }}</div>
                </a>
                <a href="{{ route('app.content.lifecycle.index', ['stale_content' => 1]) }}" class="rounded-md border border-border bg-background px-3 py-3 hover:border-primary/40">
                    <div class="text-[11px] uppercase tracking-[0.16em] text-textSecondary">Stale</div>
                    <div class="mt-2 text-xl font-semibold text-textPrimary">{{ (int) ($healthIndicators['stale_articles'] ?? 0) }}</div>
                </a>
                <a href="{{ route('app.content.lifecycle.index', ['semantic_coverage' => 'weak']) }}" class="rounded-md border border-border bg-background px-3 py-3 hover:border-primary/40">
                    <div class="text-[11px] uppercase tracking-[0.16em] text-textSecondary">Entity Gaps</div>
                    <div class="mt-2 text-xl font-semibold text-textPrimary">{{ (int) ($healthIndicators['missing_entity_coverage'] ?? 0) }}</div>
                </a>
            </div>
        </section>
    </div>

    <section class="mt-4 rounded-lg border border-border bg-surface">
        <div class="flex items-center justify-between border-b border-border px-4 py-3">
            <div>
                <h2 class="text-sm font-semibold text-textPrimary">Refresh Queue</h2>
                <p class="text-xs text-textSecondary">Auto-generated tasks from lifecycle decay analysis.</p>
            </div>
            <span class="rounded-full bg-surfaceSubtle px-2 py-1 text-xs text-textSecondary">{{ $refreshTasks->count() }} shown</span>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-border text-sm">
                <thead class="bg-surfaceSubtle text-left text-xs uppercase tracking-[0.16em] text-textSecondary">
                    <tr>
                        <th class="px-4 py-3 font-medium">Task</th>
                        <th class="px-4 py-3 font-medium">Content</th>
                        <th class="px-4 py-3 font-medium">Campaign</th>
                        <th class="px-4 py-3 font-medium">Priority</th>
                        <th class="px-4 py-3 font-medium">Due</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($refreshTasks as $task)
                        <tr>
                            <td class="px-4 py-3">
                                <div class="font-medium text-textPrimary">{{ $task->title }}</div>
                                <div class="mt-1 text-xs text-textSecondary">{{ $task->type?->label() ?? ucfirst(str_replace('_', ' ', (string) $task->type)) }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <a href="{{ $task->content ? route('app.content.show', $task->content) : '#' }}" class="font-medium text-link hover:text-linkHover">
                                    {{ $task->content?->title ?? 'Deleted content' }}
                                </a>
                                @if ($task->content?->clientSite)
                                    <div class="text-xs text-textSecondary">{{ $task->content->clientSite->name }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-textSecondary">{{ $task->campaign?->name ?? 'No campaign' }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full {{ $task->priority >= 75 ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700' }} px-2 py-1 text-xs font-medium">
                                    {{ $task->priority }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-textSecondary">{{ optional($task->due_at)->toFormattedDateString() ?? 'Unscheduled' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-textSecondary">No refresh tasks are open.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- Stage Tabs Summary --}}
    @include('app.content.lifecycle.partials.stage-tabs')

    {{-- Kanban Board --}}
    <div class="mt-6 overflow-x-auto pb-4">
        <div class="flex min-w-max gap-4">
            @foreach ($stages as $stage)
                @php
                    $stageData = $groupedContents[$stage->value] ?? ['contents' => collect(), 'has_more' => false, 'visible_count' => 0, 'limit' => 10];
                    $stageContents = $stageData['contents'];
                    $stageSummary = $stageSummaries[$stage->value] ?? ['count' => 0, 'overdue' => 0, 'due_soon' => 0];
                    $isFiltered = filled($filters['stage']) && $filters['stage'] !== $stage->value;
                    $loadMoreQuery = array_merge($filters, [
                        'stage_window' => array_merge((array) request()->query('stage_window', []), [
                            $stage->value => min(50, ((int) ($stageData['limit'] ?? 10)) + 10),
                        ]),
                    ]);
                @endphp

                <div
                    class="flex w-80 flex-col rounded-lg border border-border bg-surface {{ $isFiltered ? 'opacity-50' : '' }}"
                    data-lifecycle-column="{{ $stage->value }}"
                >
                    {{-- Column Header --}}
                    <div class="flex items-center justify-between border-b border-border px-4 py-3">
                        <div class="flex items-center gap-2">
                            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-{{ $stage->color() }}-100 text-{{ $stage->color() }}-700">
                                <i data-lucide="{{ $stage->icon() }}" class="h-3.5 w-3.5"></i>
                            </span>
                            <h3 class="font-medium text-textPrimary">{{ $stage->label() }}</h3>
                            <span class="rounded-full bg-surfaceSubtle px-2 py-0.5 text-xs font-medium text-textSecondary">
                                {{ $stageSummary['count'] }}
                            </span>
                        </div>
                        @if ($stageSummary['overdue'] > 0)
                            <span class="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-medium text-rose-700" title="{{ $stageSummary['overdue'] }} overdue">
                                {{ $stageSummary['overdue'] }} overdue
                            </span>
                        @elseif ($stageSummary['due_soon'] > 0)
                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700" title="{{ $stageSummary['due_soon'] }} due soon">
                                {{ $stageSummary['due_soon'] }} soon
                            </span>
                        @endif
                    </div>

                    {{-- Column Content --}}
                    <div class="flex-1 space-y-3 overflow-y-auto p-3" style="max-height: calc(100vh - 360px); min-height: 200px;">
                        @forelse ($stageContents as $content)
                            @include('app.content.lifecycle.partials.content-card', ['content' => $content, 'stage' => $stage, 'cardData' => $cardDataById[(string) $content->id] ?? []])
                        @empty
                            <div class="flex h-32 items-center justify-center rounded-lg border border-dashed border-border bg-background/50">
                                <p class="text-sm text-textFaint">No content in this stage</p>
                            </div>
                        @endforelse

                        @if (($stageData['has_more'] ?? false) === true)
                            <a
                                href="{{ route('app.content.lifecycle.index', $loadMoreQuery) }}"
                                class="flex items-center justify-center rounded-lg border border-dashed border-border px-3 py-2 text-xs font-medium text-textSecondary transition hover:border-primary/40 hover:text-textPrimary"
                            >
                                Load 10 more
                            </a>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Quick Actions Dialog --}}
    <dialog id="lifecycle-action-dialog" class="rounded-lg border border-border bg-surface p-0 text-textPrimary shadow-xl backdrop:bg-black/40">
        <div class="w-full max-w-md p-0">
            <div class="border-b border-border px-5 py-4">
                <h2 class="text-base font-semibold" id="lifecycle-action-title">Action</h2>
            </div>
            <form id="lifecycle-action-form" method="POST" action="">
                @csrf
                <div class="space-y-4 px-5 py-4">
                    <input type="hidden" name="target_stage" id="lifecycle-target-stage">

                    {{-- Dynamic content based on action --}}
                    <div id="lifecycle-action-content"></div>

                    {{-- Notes field (always shown) --}}
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary" for="lifecycle-notes">Notes (optional)</label>
                        <textarea
                            id="lifecycle-notes"
                            name="notes"
                            class="pl-input w-full"
                            rows="2"
                            placeholder="Add a note about this action..."
                        ></textarea>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2 border-t border-border px-5 py-4">
                    <button type="button" class="pl-btn-ghost" onclick="document.getElementById('lifecycle-action-dialog').close()">Cancel</button>
                    <button type="submit" class="pl-btn-primary" id="lifecycle-action-submit">Confirm</button>
                </div>
            </form>
        </div>
    </dialog>

    {{-- Reject Dialog --}}
    <dialog id="lifecycle-reject-dialog" class="rounded-lg border border-border bg-surface p-0 text-textPrimary shadow-xl backdrop:bg-black/40">
        <div class="w-full max-w-md p-0">
            <div class="border-b border-border px-5 py-4">
                <h2 class="text-base font-semibold">Reject Content</h2>
            </div>
            <form id="lifecycle-reject-form" method="POST" action="">
                @csrf
                <div class="space-y-4 px-5 py-4">
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary" for="lifecycle-reject-reason">Reason for rejection <span class="text-rose-500">*</span></label>
                        <textarea
                            id="lifecycle-reject-reason"
                            name="reason"
                            class="pl-input w-full"
                            rows="3"
                            required
                            placeholder="Explain what needs to be changed..."
                        ></textarea>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary" for="lifecycle-reject-notes">Additional notes (optional)</label>
                        <textarea
                            id="lifecycle-reject-notes"
                            name="notes"
                            class="pl-input w-full"
                            rows="2"
                            placeholder="Any additional context..."
                        ></textarea>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2 border-t border-border px-5 py-4">
                    <button type="button" class="pl-btn-ghost" onclick="document.getElementById('lifecycle-reject-dialog').close()">Cancel</button>
                    <button type="submit" class="rounded bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700">Reject</button>
                </div>
            </form>
        </div>
    </dialog>

    {{-- Assign Dialog --}}
    <dialog id="lifecycle-assign-dialog" class="rounded-lg border border-border bg-surface p-0 text-textPrimary shadow-xl backdrop:bg-black/40">
        <div class="w-full max-w-md p-0">
            <div class="border-b border-border px-5 py-4">
                <h2 class="text-base font-semibold" id="lifecycle-assign-title">Assign Content</h2>
            </div>
            <form id="lifecycle-assign-form" method="POST" action="">
                @csrf
                <div class="space-y-4 px-5 py-4">
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary" for="lifecycle-assignee">Assign to</label>
                        <select id="lifecycle-assignee" name="assignee_id" class="pl-select w-full bg-surface" required>
                            <option value="">Select a team member</option>
                            @foreach ($users as $user)
                                <option value="{{ $user->id }}">{{ $user->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary" for="lifecycle-assign-notes">Notes (optional)</label>
                        <textarea
                            id="lifecycle-assign-notes"
                            name="notes"
                            class="pl-input w-full"
                            rows="2"
                            placeholder="Add instructions or context..."
                        ></textarea>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2 border-t border-border px-5 py-4">
                    <button type="button" class="pl-btn-ghost" onclick="document.getElementById('lifecycle-assign-dialog').close()">Cancel</button>
                    <button type="submit" class="pl-btn-primary">Assign</button>
                </div>
            </form>
        </div>
    </dialog>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Handle transition buttons
            document.querySelectorAll('[data-lifecycle-transition]').forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const action = this.dataset.lifecycleTransition;
                    const contentId = this.dataset.contentId;
                    const contentTitle = this.dataset.contentTitle;

                    if (action === 'reject') {
                        const dialog = document.getElementById('lifecycle-reject-dialog');
                        const form = document.getElementById('lifecycle-reject-form');
                        form.action = `/content/${contentId}/lifecycle/reject`;
                        dialog.showModal();
                    } else {
                        const dialog = document.getElementById('lifecycle-action-dialog');
                        const form = document.getElementById('lifecycle-action-form');
                        const titleEl = document.getElementById('lifecycle-action-title');
                        const submitBtn = document.getElementById('lifecycle-action-submit');
                        const targetStageInput = document.getElementById('lifecycle-target-stage');
                        const contentDiv = document.getElementById('lifecycle-action-content');

                        // Set form action based on transition type
                        if (action === 'approve') {
                            form.action = `/content/${contentId}/lifecycle/approve`;
                            titleEl.textContent = 'Approve Content';
                            submitBtn.textContent = 'Approve';
                            contentDiv.innerHTML = `<p class="text-sm text-textSecondary">Approve "${contentTitle}" and move to the approved stage?</p>`;
                        } else {
                            form.action = `/content/${contentId}/lifecycle/transition`;
                            targetStageInput.value = action;
                            titleEl.textContent = `Move to ${action.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}`;
                            submitBtn.textContent = 'Move';
                            contentDiv.innerHTML = `<p class="text-sm text-textSecondary">Move "${contentTitle}" to the ${action.replace('_', ' ')} stage?</p>`;
                        }

                        dialog.showModal();
                    }
                });
            });

            // Handle assign buttons
            document.querySelectorAll('[data-lifecycle-assign]').forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const contentId = this.dataset.contentId;
                    const assignType = this.dataset.lifecycleAssign;

                    const dialog = document.getElementById('lifecycle-assign-dialog');
                    const form = document.getElementById('lifecycle-assign-form');
                    const titleEl = document.getElementById('lifecycle-assign-title');

                    if (assignType === 'reviewer') {
                        form.action = `/content/${contentId}/lifecycle/set-reviewer`;
                        titleEl.textContent = 'Set Reviewer';
                        document.getElementById('lifecycle-assignee').name = 'reviewer_id';
                    } else {
                        form.action = `/content/${contentId}/lifecycle/assign`;
                        titleEl.textContent = 'Assign Content';
                        document.getElementById('lifecycle-assignee').name = 'assignee_id';
                    }

                    dialog.showModal();
                });
            });
        });
    </script>
@endsection
