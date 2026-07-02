@extends('layouts.app', ['title' => 'Agentic Marketing action', 'pageWidth' => 'wide'])

@php
    $statusClasses = [
        'proposed' => 'bg-slate-100 text-slate-700',
        'approved' => 'bg-blue-100 text-blue-800',
        'running' => 'bg-amber-100 text-amber-800',
        'completed' => 'bg-emerald-100 text-emerald-800',
        'failed' => 'bg-rose-100 text-rose-800',
        'dismissed' => 'bg-slate-100 text-slate-500',
    ];
    $planning = (array) data_get($action->payload, 'planning', []);
    $scoreExplanation = (array) data_get($action->opportunity?->payload, 'score_explanation', []);
    $result = (array) ($action->result ?? []);
    $suggestions = collect((array) data_get($result, 'suggestions', data_get($action->payload, 'proposal_details.items', [])))->values();
    $appliedChanges = (array) data_get($result, 'applied_changes', []);
    $rollback = (array) data_get($result, 'rollback', []);
    $createdContentId = data_get($result, 'created_content_id') ?: $action->content_id;
    $createdDraftId = data_get($result, 'created_draft_id') ?: $action->draft_id;
    $proposalSummary = data_get($action->payload, 'recommendation')
        ?: data_get($action->payload, 'reason')
        ?: 'No proposal summary was recorded.';
    $outcomeSummary = data_get($result, 'summary')
        ?: ($action->status === 'completed' ? 'Execution completed.' : 'No execution result recorded yet.');
    $changedLiveContent = $appliedChanges !== [] || data_get($result, 'created_draft_id') || data_get($result, 'created_content_id');
@endphp

@section('pageHeader')
    <x-page-header :title="$action->opportunity?->title ?? str_replace('_', ' ', $action->action_type)" :eyebrow="$action->objective?->name ?? 'Agentic Marketing'">
        <x-slot:actions>
            <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $statusClasses[$action->status] ?? 'bg-slate-100 text-slate-700' }}">{{ ucfirst((string) $action->status) }}</span>
            <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', (string) $action->action_type) }}</span>
        </x-slot:actions>
    </x-page-header>
@endsection

@section('pageDescription')
    <x-page-description>{{ data_get($action->payload, 'recommendation', 'Supervised Agentic Marketing action.') }}</x-page-description>
@endsection

@section('primaryActions')
    @if ($action->opportunity)
        <a href="{{ route('app.agentic-marketing.opportunities.execution.show', $action->opportunity) }}" class="pl-btn-ghost">
            <i data-lucide="workflow" class="h-4 w-4"></i><span>Execution pipeline</span>
        </a>
    @endif
    @if ($action->status === 'proposed')
        <form method="POST" action="{{ route('app.agentic-marketing.actions.approve', $action) }}">
            @csrf
            <button class="pl-btn-primary" type="submit"><i data-lucide="check" class="h-4 w-4"></i><span>Approve</span></button>
        </form>
    @elseif ($action->status === 'approved')
        <form method="POST" action="{{ route('app.agentic-marketing.actions.execute', $action) }}">
            @csrf
            <button class="pl-btn-primary" type="submit"><i data-lucide="play" class="h-4 w-4"></i><span>Execute</span></button>
        </form>
    @elseif ($action->status === 'failed')
        <form method="POST" action="{{ route('app.agentic-marketing.actions.retry', $action) }}">
            @csrf
            <button class="pl-btn-primary" type="submit"><i data-lucide="rotate-cw" class="h-4 w-4"></i><span>Retry</span></button>
        </form>
    @endif
@endsection

@section('metricSection')
    <x-metric-section>
        <x-metric-card label="Cost" :value="(int) ($action->estimated_credits ?? data_get($planning, 'estimated_credits', 0))" helper="estimated credits" />
        <x-metric-card label="Risk" :value="ucfirst((string) data_get($planning, 'risk_level', 'n/a'))" :helper="data_get($planning, 'approval_required') ? 'approval required' : 'policy pre-cleared'" />
        <x-metric-card label="Prerequisites" :value="data_get($planning, 'prerequisites.met') === false ? 'Blocked' : 'Ready'" :helper="data_get($planning, 'approval_reason', 'No policy note recorded.')" />
        <x-metric-card label="Credits" :value="$action->credit_status === 'skipped' ? 'No charge' : ucfirst((string) ($action->credit_status ?? 'unreserved'))">
            @if ($action->credit_status === 'skipped')
                proposal-only execution
            @elseif ($action->credits_captured)
                captured {{ number_format((int) $action->credits_captured) }}
            @elseif ($action->credits_reserved)
                reserved {{ number_format((int) $action->credits_reserved) }}
            @else
                no reservation yet
            @endif
        </x-metric-card>
    </x-metric-section>
@endsection

@section('content')
    <div class="space-y-6">

        @if (session('status'))
            <x-alert class="mb-4">{{ session('status') }}</x-alert>
        @endif

        <section class="grid gap-4 xl:grid-cols-2">
            <div class="rounded-lg border border-border bg-surface">
                <div class="border-b border-border px-5 py-4">
                    <h2 class="text-base font-semibold text-textPrimary">What was proposed?</h2>
                    <p class="mt-1 text-sm text-textSecondary">{{ $proposalSummary }}</p>
                </div>
                <div class="space-y-4 p-5">
                    <div class="grid gap-3 text-sm sm:grid-cols-2">
                        <div class="rounded-md border border-border bg-background px-3 py-2">
                            <div class="text-xs text-textSecondary">Action type</div>
                            <div class="mt-1 font-medium text-textPrimary">{{ str_replace('_', ' ', (string) $action->action_type) }}</div>
                        </div>
                        <div class="rounded-md border border-border bg-background px-3 py-2">
                            <div class="text-xs text-textSecondary">Source content</div>
                            <div class="mt-1 truncate font-medium text-textPrimary">{{ $action->content?->title ?? $action->opportunity?->title ?? 'Not attached' }}</div>
                        </div>
                    </div>

                    @if ($suggestions->isNotEmpty())
                        <div class="space-y-3">
                            @foreach ($suggestions as $suggestion)
                                @php
                                    $suggestion = (array) $suggestion;
                                    $schema = (array) ($suggestion['schema'] ?? []);
                                @endphp
                                <div class="rounded-md border border-border bg-background px-3 py-3">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="rounded-full border border-border bg-surface px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', (string) ($suggestion['type'] ?? 'suggestion')) }}</span>
                                        @if (($suggestion['review_required'] ?? false) === true)
                                            <span class="rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs text-amber-800">Review required</span>
                                        @endif
                                    </div>

                                    @if ($schema)
                                        <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-3">
                                            @foreach ($schema as $key => $value)
                                                <div>
                                                    <dt class="text-xs text-textSecondary">{{ $key }}</dt>
                                                    <dd class="mt-1 text-textPrimary">{{ is_scalar($value) ? $value : json_encode($value) }}</dd>
                                                </div>
                                            @endforeach
                                        </dl>
                                    @endif

                                    @if (! empty($suggestion['text']) || ! empty($suggestion['reason']))
                                        <p class="mt-3 text-sm text-textSecondary">{{ $suggestion['text'] ?? $suggestion['reason'] }}</p>
                                    @endif

                                    @php
                                        $detailFields = collect($suggestion)
                                            ->except(['type', 'review_required', 'schema', 'text', 'reason'])
                                            ->filter(fn ($value) => filled($value));
                                    @endphp
                                    @if ($detailFields->isNotEmpty())
                                        <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                                            @foreach ($detailFields as $key => $value)
                                                <div>
                                                    <dt class="text-xs text-textSecondary">{{ str_replace('_', ' ', (string) $key) }}</dt>
                                                    <dd class="mt-1 text-textPrimary">{{ is_scalar($value) ? $value : json_encode($value) }}</dd>
                                                </div>
                                            @endforeach
                                        </dl>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="rounded-md border border-border bg-background px-3 py-3 text-sm text-textSecondary">No structured proposal details were recorded for this action.</p>
                    @endif
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface">
                <div class="border-b border-border px-5 py-4">
                    <h2 class="text-base font-semibold text-textPrimary">What changed after execution?</h2>
                    <p class="mt-1 text-sm text-textSecondary">{{ $outcomeSummary }}</p>
                </div>
                <div class="space-y-4 p-5">
                    @if ($appliedChanges)
                        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-3">
                            <p class="text-sm font-medium text-emerald-900">Live metadata was updated.</p>
                            <dl class="mt-3 space-y-2 text-sm">
                                @foreach ($appliedChanges as $field => $value)
                                    <div>
                                        <dt class="text-xs text-emerald-800">{{ str_replace('_', ' ', (string) $field) }}</dt>
                                        <dd class="mt-1 text-emerald-950">{{ is_scalar($value) ? $value : json_encode($value) }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    @elseif ($createdDraftId || data_get($result, 'created_content_id'))
                        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-3 text-sm text-emerald-900">
                            @if (data_get($result, 'created_content_id'))
                                <p>Created content item: <span class="font-mono text-xs">{{ data_get($result, 'created_content_id') }}</span></p>
                            @endif
                            @if ($createdDraftId)
                                <p class="mt-1">Created draft: <span class="font-mono text-xs">{{ $createdDraftId }}</span></p>
                            @endif
                        </div>
                    @elseif ($action->status === 'completed')
                        <div class="rounded-md border border-border bg-background px-3 py-3">
                            <p class="text-sm font-medium text-textPrimary">No live content was changed.</p>
                            <p class="mt-1 text-sm text-textSecondary">This execution produced a reviewable proposal only. Nothing was published, overwritten, or applied to the source content.</p>
                        </div>
                    @else
                        <div class="rounded-md border border-border bg-background px-3 py-3">
                            <p class="text-sm text-textSecondary">This action has not completed yet.</p>
                        </div>
                    @endif

                    <div class="grid gap-3 text-sm sm:grid-cols-2">
                        <div class="rounded-md border border-border bg-background px-3 py-2">
                            <div class="text-xs text-textSecondary">Service used</div>
                            <div class="mt-1 font-medium text-textPrimary">{{ $result['service_used'] ?? 'Not recorded' }}</div>
                        </div>
                        <div class="rounded-md border border-border bg-background px-3 py-2">
                            <div class="text-xs text-textSecondary">Credits charged</div>
                            <div class="mt-1 font-medium text-textPrimary">
                                {{ data_get($result, 'billing.credits_charged') !== null ? number_format((int) data_get($result, 'billing.credits_charged')) : (($action->credits_captured ?? null) !== null ? number_format((int) $action->credits_captured) : '0') }}
                            </div>
                        </div>
                        <div class="rounded-md border border-border bg-background px-3 py-2 sm:col-span-2">
                            <div class="text-xs text-textSecondary">Executed at</div>
                            <div class="mt-1 font-medium text-textPrimary">{{ ! empty($result['executed_at']) ? \Illuminate\Support\Carbon::parse($result['executed_at'])->diffForHumans() : 'Not recorded' }}</div>
                        </div>
                    </div>

                    @if ($rollback)
                        <details class="rounded-md border border-border bg-background px-3 py-2 text-sm">
                            <summary class="cursor-pointer font-medium text-textPrimary">Rollback metadata</summary>
                            <pre class="mt-3 overflow-auto rounded-md bg-surface p-3 text-xs text-textSecondary">{{ json_encode($rollback, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </details>
                    @endif
                </div>
            </div>
        </section>

        <section class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
            <div class="rounded-lg border border-border bg-surface">
                <div class="border-b border-border px-5 py-4">
                    <h2 class="text-base font-semibold text-textPrimary">Timeline</h2>
                    <p class="mt-1 text-sm text-textSecondary">Planning, approval, retry, execution, and failure events for this action.</p>
                </div>
                <div class="divide-y divide-border">
                    @forelse ($timeline as $event)
                        <article class="px-5 py-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <p class="text-sm font-medium text-textPrimary">{{ $event['label'] }}</p>
                                <span class="text-xs text-textSecondary">{{ optional($event['time'])->diffForHumans() }}</span>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-1.5 text-[11px] text-textSecondary">
                                <span class="rounded-md border border-border bg-background px-2 py-1">{{ $event['kind'] }}</span>
                                <span class="rounded-md border border-border bg-background px-2 py-1">{{ $event['status'] }}</span>
                            </div>
                            @if (! empty($event['message']))
                                <p class="mt-2 text-xs text-textSecondary">{{ $event['message'] }}</p>
                            @endif
                        </article>
                    @empty
                        <p class="px-5 py-6 text-sm text-textSecondary">No timeline events recorded yet.</p>
                    @endforelse
                </div>
            </div>

            <aside class="space-y-4">
                <div class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-base font-semibold text-textPrimary">Why this action?</h2>
                    <p class="mt-2 text-sm text-textSecondary">{{ $scoreExplanation['summary'] ?? data_get($action->payload, 'reason', 'This action was generated from stored AM planning metadata.') }}</p>
                    @if (! empty($scoreExplanation['reasons']))
                        <ul class="mt-3 list-disc space-y-1 pl-4 text-sm text-textSecondary">
                            @foreach (array_slice((array) $scoreExplanation['reasons'], 0, 4) as $reason)
                                <li>{{ $reason }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-base font-semibold text-textPrimary">Failure & retry</h2>
                    @if ($action->error_message)
                        <p class="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ $action->error_message }}</p>
                    @else
                        <p class="text-sm text-textSecondary">No failure recorded for this action.</p>
                    @endif
                </div>
            </aside>
        </section>
    </div>
@endsection
