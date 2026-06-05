@extends('layouts.app', ['title' => 'Autonomous workflows', 'pageWidth' => 'wide'])

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <p class="text-sm text-textSecondary">Agentic Marketing</p>
                <h1 class="text-xl font-semibold text-textPrimary">Autonomous Workflow Orchestration</h1>
                <p class="mt-1 max-w-3xl text-sm text-textSecondary">Run signal-driven marketing workflows with policy gates, confidence thresholds, human overrides, and audit-ready approval checkpoints.</p>
            </div>
            <form method="POST" action="{{ route('app.agentic-marketing.workflows.run', ['workspace_id' => $workspace->id]) }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <label class="block">
                    <span class="text-xs font-medium text-textSecondary">Trigger</span>
                    <select name="trigger_type" class="pl-input mt-1">
                        @foreach ($triggerTypes as $triggerType)
                            <option value="{{ $triggerType }}">{{ str($triggerType)->replace('_', ' ')->title() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-medium text-textSecondary">Campaign topic</span>
                    <input name="topic" class="pl-input mt-1 w-56" placeholder="Agentic Marketing">
                </label>
                <label class="inline-flex items-center gap-2 rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">
                    <input type="checkbox" name="run_inline" value="1" class="rounded border-border text-primary">
                    <span>Run now</span>
                </label>
                <button class="pl-btn-primary">
                    <i data-lucide="play" class="h-4 w-4"></i>
                    <span>Start workflow</span>
                </button>
            </form>
        </div>

        @if (session('status'))
            <div class="rounded-md border border-primary/20 bg-primarySoftBg px-4 py-3 text-sm text-textPrimary">{{ session('status') }}</div>
        @endif

        <div class="grid gap-4 lg:grid-cols-4">
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-textSecondary">Runs</p>
                <p class="mt-2 text-3xl font-semibold text-textPrimary">{{ $runs->count() }}</p>
                <p class="mt-1 text-sm text-textSecondary">Recent orchestration runs</p>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-textSecondary">Rules</p>
                <p class="mt-2 text-3xl font-semibold text-textPrimary">{{ $rules->count() }}</p>
                <p class="mt-1 text-sm text-textSecondary">Active and draft automation policies</p>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-textSecondary">Overrides</p>
                <p class="mt-2 text-3xl font-semibold text-textPrimary">{{ $overrides->count() }}</p>
                <p class="mt-1 text-sm text-textSecondary">Human controls currently active</p>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-textSecondary">Autopublish</p>
                <p class="mt-2 text-3xl font-semibold text-textPrimary">Off</p>
                <p class="mt-1 text-sm text-textSecondary">Publishing requires explicit approval</p>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-[1.5fr_1fr]">
            <section class="rounded-lg border border-border bg-surface">
                <div class="border-b border-border p-4">
                    <h2 class="text-base font-semibold text-textPrimary">Latest Workflow Run</h2>
                    <p class="mt-1 text-sm text-textSecondary">Approval checkpoints, generated proposals, and explainable safety output.</p>
                </div>
                @if ($latestRun)
                    @php($payload = (array) ($latestRun->output_payload ?? []))
                    @php($checkpoints = (array) data_get($payload, 'approval_checkpoints', []))
                    <div class="grid gap-4 p-4 md:grid-cols-3">
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-textSecondary">Status</p>
                            <p class="mt-1 text-sm font-semibold text-textPrimary">{{ str($latestRun->status->value ?? $latestRun->status)->replace('_', ' ')->title() }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-textSecondary">Checkpoints</p>
                            <p class="mt-1 text-sm font-semibold text-textPrimary">{{ count($checkpoints) }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-textSecondary">Queued actions</p>
                            <p class="mt-1 text-sm font-semibold text-textPrimary">{{ count((array) data_get($payload, 'actions.queued_action_ids', [])) }}</p>
                        </div>
                    </div>
                    <div class="border-t border-border p-4">
                        <p class="text-sm text-textPrimary">{{ $latestRun->summary }}</p>
                        <div class="mt-4 grid gap-3 md:grid-cols-2">
                            <div class="rounded-md border border-border bg-background p-3">
                                <p class="text-xs font-medium uppercase tracking-wide text-textSecondary">Campaign proposal</p>
                                <p class="mt-1 text-sm font-semibold text-textPrimary">{{ data_get($payload, 'campaign_proposal.name', data_get($payload, 'campaign_proposal.reason', 'No campaign generated')) }}</p>
                                <p class="mt-1 text-xs text-textSecondary">{{ data_get($payload, 'campaign_proposal.reason') }}</p>
                            </div>
                            <div class="rounded-md border border-border bg-background p-3">
                                <p class="text-xs font-medium uppercase tracking-wide text-textSecondary">Safety</p>
                                <p class="mt-1 text-sm font-semibold text-textPrimary">Human approval required</p>
                                <p class="mt-1 text-xs text-textSecondary">Fully autonomous publishing: {{ data_get($payload, 'safety.fully_autonomous_publishing_enabled') ? 'enabled' : 'disabled' }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="border-t border-border">
                        @forelse ($checkpoints as $checkpoint)
                            <div class="border-b border-border p-4 last:border-b-0">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold text-textPrimary">{{ str(data_get($checkpoint, 'action_type', class_basename((string) data_get($checkpoint, 'subject_type', 'Workflow'))))->replace('_', ' ')->title() }}</p>
                                        <p class="mt-1 text-sm text-textSecondary">{{ data_get($checkpoint, 'reason', 'Review required.') }}</p>
                                    </div>
                                    <span class="rounded-full border border-border bg-background px-3 py-1 text-xs font-medium text-textPrimary">{{ str(data_get($checkpoint, 'decision', 'requires_approval'))->replace('_', ' ')->title() }}</span>
                                </div>
                                @if (! is_null(data_get($checkpoint, 'confidence_score')))
                                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-surfaceMuted">
                                        <div class="h-full rounded-full bg-primary" style="width: {{ max(0, min(100, (int) data_get($checkpoint, 'confidence_score'))) }}%"></div>
                                    </div>
                                @endif
                            </div>
                        @empty
                            <p class="p-4 text-sm text-textSecondary">No checkpoints recorded yet.</p>
                        @endforelse
                    </div>
                @else
                    <p class="p-6 text-sm text-textSecondary">No autonomous marketing workflows have run for this workspace yet.</p>
                @endif
            </section>

            <aside class="space-y-6">
                <section class="rounded-lg border border-border bg-surface">
                    <div class="border-b border-border p-4">
                        <h2 class="text-base font-semibold text-textPrimary">Automation Policy</h2>
                        <p class="mt-1 text-sm text-textSecondary">Rules keep workflows bounded, queue safe, and approval first.</p>
                    </div>
                    <form method="POST" action="{{ route('app.agentic-marketing.workflows.rules.store', ['workspace_id' => $workspace->id]) }}" class="space-y-3 p-4">
                        @csrf
                        <input name="name" class="pl-input w-full" value="{{ old('name', 'Signal governed workflow') }}" required maxlength="180">
                        <select name="trigger_type" class="pl-input w-full">
                            @foreach ($triggerTypes as $triggerType)
                                <option value="{{ $triggerType }}">{{ str($triggerType)->replace('_', ' ')->title() }}</option>
                            @endforeach
                        </select>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label>
                                <span class="text-xs font-medium text-textSecondary">Min confidence</span>
                                <input type="number" name="minimum_confidence_score" min="0" max="100" value="70" class="pl-input mt-1 w-full">
                            </label>
                            <label>
                                <span class="text-xs font-medium text-textSecondary">Max actions</span>
                                <input type="number" name="maximum_actions_per_run" min="1" max="50" value="10" class="pl-input mt-1 w-full">
                            </label>
                        </div>
                        <label class="flex items-start gap-2 text-sm text-textSecondary">
                            <input type="checkbox" name="auto_queue_approved_actions" value="1" class="mt-1 rounded border-border text-primary">
                            <span>Queue approved non-publishing actions automatically. Publishing stays blocked until explicit approval.</span>
                        </label>
                        <button class="pl-btn-ghost w-full justify-center">
                            <i data-lucide="shield-check" class="h-4 w-4"></i>
                            <span>Save rule</span>
                        </button>
                    </form>
                    <div class="border-t border-border">
                        @forelse ($rules as $rule)
                            <div class="border-b border-border p-4 last:border-b-0">
                                <p class="text-sm font-semibold text-textPrimary">{{ $rule->name }}</p>
                                <p class="mt-1 text-xs text-textSecondary">{{ str($rule->trigger_type)->replace('_', ' ')->title() }} · {{ $rule->minimum_confidence_score }}% confidence · {{ $rule->maximum_actions_per_run }} actions</p>
                            </div>
                        @empty
                            <p class="p-4 text-sm text-textSecondary">The default governed rule is used until a custom policy is saved.</p>
                        @endforelse
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-surface">
                    <div class="border-b border-border p-4">
                        <h2 class="text-base font-semibold text-textPrimary">Human Overrides</h2>
                        <p class="mt-1 text-sm text-textSecondary">Pause workflows, force review, or block specific action types.</p>
                    </div>
                    <form method="POST" action="{{ route('app.agentic-marketing.workflows.overrides.store', ['workspace_id' => $workspace->id]) }}" class="space-y-3 p-4">
                        @csrf
                        <select name="override_type" class="pl-input w-full">
                            <option value="pause_workflow">Pause workflow</option>
                            <option value="force_approval">Force approval</option>
                            <option value="block_action">Block action type</option>
                        </select>
                        <input name="action_type" class="pl-input w-full" placeholder="Optional action type">
                        <textarea name="reason" class="pl-input w-full" rows="3" placeholder="Reason for override" required></textarea>
                        <input type="datetime-local" name="expires_at" class="pl-input w-full">
                        <button class="pl-btn-ghost w-full justify-center">
                            <i data-lucide="hand" class="h-4 w-4"></i>
                            <span>Apply override</span>
                        </button>
                    </form>
                    <div class="border-t border-border">
                        @forelse ($overrides as $override)
                            <div class="border-b border-border p-4 last:border-b-0">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold text-textPrimary">{{ str($override->override_type)->replace('_', ' ')->title() }}</p>
                                        <p class="mt-1 text-xs text-textSecondary">{{ $override->reason }}</p>
                                    </div>
                                    <form method="POST" action="{{ route('app.agentic-marketing.workflows.overrides.clear', ['override' => $override, 'workspace_id' => $workspace->id]) }}">
                                        @csrf
                                        <button class="text-xs font-medium text-primary">Clear</button>
                                    </form>
                                </div>
                            </div>
                        @empty
                            <p class="p-4 text-sm text-textSecondary">No active human overrides.</p>
                        @endforelse
                    </div>
                </section>
            </aside>
        </div>

        <section class="rounded-lg border border-border bg-surface">
            <div class="border-b border-border p-4">
                <h2 class="text-base font-semibold text-textPrimary">Run History</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-border text-sm">
                    <thead class="bg-background text-left text-xs font-medium uppercase tracking-wide text-textSecondary">
                        <tr>
                            <th class="px-4 py-3">Started</th>
                            <th class="px-4 py-3">Trigger</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Summary</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($runs as $run)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 text-textSecondary">{{ $run->started_at?->format('Y-m-d H:i') ?? $run->created_at?->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-3 text-textPrimary">{{ str($run->trigger_type)->replace('_', ' ')->title() }}</td>
                                <td class="px-4 py-3 text-textPrimary">{{ str($run->status->value ?? $run->status)->replace('_', ' ')->title() }}</td>
                                <td class="px-4 py-3 text-textSecondary">{{ $run->summary ?: $run->error_message }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-6 text-center text-textSecondary">No workflow history yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
