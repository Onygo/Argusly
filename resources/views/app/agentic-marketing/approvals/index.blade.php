@extends('layouts.app', ['title' => 'Agentic Marketing approvals', 'pageWidth' => 'wide'])

@php
    $statusClasses = [
        'approval_required' => 'bg-amber-100 text-amber-800',
        'approved' => 'bg-blue-100 text-blue-800',
        'rejected' => 'bg-rose-100 text-rose-800',
        'queued' => 'bg-blue-100 text-blue-800',
    ];
@endphp

@section('content')
    <div class="space-y-6">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <a href="{{ route('app.agentic-marketing.index') }}" class="text-sm text-textSecondary hover:text-textPrimary">Agentic Marketing</a>
                <h1 class="mt-2 text-xl font-semibold text-textPrimary">Approval Inbox</h1>
                <p class="mt-1 max-w-3xl text-sm text-textSecondary">Review proposed Agentic Marketing actions before they can run. Approval is recorded with the reviewer and timestamp.</p>
            </div>
        </header>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        <section class="rounded-lg border border-border bg-surface p-4">
            <form method="GET" action="{{ route('app.agentic-marketing.approvals.index') }}" class="grid gap-3 md:grid-cols-4">
                <select name="workspace_id" class="pl-input text-sm">
                    <option value="">All workspaces</option>
                    @foreach ($workspaces as $workspace)
                        <option value="{{ $workspace->id }}" @selected(($filters['workspace_id'] ?? '') === (string) $workspace->id)>{{ $workspace->display_name ?? $workspace->name }}</option>
                    @endforeach
                </select>
                <select name="action_type" class="pl-input text-sm">
                    <option value="">All action types</option>
                    @foreach ($actionTypes as $type)
                        <option value="{{ $type }}" @selected(($filters['action_type'] ?? '') === $type)>{{ str_replace('_', ' ', $type) }}</option>
                    @endforeach
                </select>
                <button class="pl-btn-primary justify-center" type="submit">
                    <i data-lucide="filter" class="h-4 w-4"></i>
                    <span>Filter</span>
                </button>
                <a href="{{ route('app.agentic-marketing.approvals.index') }}" class="pl-btn-ghost justify-center">Reset</a>
            </form>
        </section>

        <form id="bulk-approve-form" method="POST" action="{{ route('app.agentic-marketing.approvals.bulk-approve') }}" class="hidden">
            @csrf
        </form>

        <div class="space-y-4">
            <div class="flex flex-col gap-3 rounded-lg border border-border bg-surface px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-semibold text-textPrimary">Approval required</p>
                    <p class="text-xs text-textSecondary">{{ $runs->total() }} action(s) waiting for customer review.</p>
                </div>
                <button class="pl-btn-primary" type="submit" form="bulk-approve-form">
                    <i data-lucide="check-check" class="h-4 w-4"></i>
                    <span>Bulk approve low risk</span>
                </button>
            </div>

            <div class="space-y-4">
                @forelse ($runs as $run)
                    @php
                        $action = $run->action;
                        $payload = (array) data_get($run->input_snapshot, 'payload', $action?->payload ?? []);
                        $proposalItems = collect((array) data_get($payload, 'proposal_details.items', data_get($run->output_snapshot, 'proposal_details.items', [])))->values();
                        $preview = data_get($run->output_snapshot, 'brief_id')
                            ? 'Brief created: '.data_get($run->output_snapshot, 'title', data_get($run->output_snapshot, 'brief_id'))
                            : data_get($payload, 'recommendation', data_get($payload, 'reason', $run->reason));
                        $destination = $run->goal?->clientSite?->name
                            ?: $action?->objective?->clientSite?->name
                            ?: data_get($payload, 'client_site_id')
                            ?: 'No publishing destination selected';
                        $risk = (string) data_get($payload, 'planning.risk_level', 'low');
                        $lowRisk = in_array($risk, ['low', ''], true) && (int) ($run->estimated_credits ?? 0) <= 10;
                    @endphp
                    <article class="rounded-lg border border-border bg-surface">
                        <div class="grid gap-4 p-5 xl:grid-cols-[minmax(0,1fr)_340px]">
                            <div class="min-w-0 space-y-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <label class="inline-flex items-center gap-2 rounded-full border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">
                                        <input type="checkbox" form="bulk-approve-form" name="run_ids[]" value="{{ $run->id }}" @disabled(! $lowRisk)>
                                        <span>{{ $lowRisk ? 'Bulk eligible' : 'Manual review' }}</span>
                                    </label>
                                    <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $statusClasses[$run->status] ?? 'bg-slate-100 text-slate-700' }}">{{ str_replace('_', ' ', $run->status) }}</span>
                                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', $run->action_type) }}</span>
                                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ ucfirst($run->execution_mode_snapshot) }}</span>
                                </div>

                                <div>
                                    <h2 class="text-base font-semibold text-textPrimary">{{ $run->opportunity?->title ?? $action?->opportunity?->title ?? $run->goal?->name ?? 'Agentic Marketing action' }}</h2>
                                    <p class="mt-1 text-sm text-textSecondary">{{ $run->reason ?: data_get($payload, 'reason', 'No reason recorded.') }}</p>
                                </div>

                                <div class="grid gap-2 text-xs text-textSecondary sm:grid-cols-2 lg:grid-cols-4">
                                    <div class="rounded-md border border-border bg-background px-3 py-2">Destination <span class="font-semibold text-textPrimary">{{ $destination }}</span></div>
                                    <div class="rounded-md border border-border bg-background px-3 py-2">Credits <span class="font-semibold text-textPrimary">{{ number_format((int) ($run->estimated_credits ?? 0)) }}</span></div>
                                    <div class="rounded-md border border-border bg-background px-3 py-2">Risk <span class="font-semibold text-textPrimary">{{ ucfirst($risk ?: 'low') }}</span></div>
                                    <div class="rounded-md border border-border bg-background px-3 py-2">Workspace <span class="font-semibold text-textPrimary">{{ $run->workspace?->display_name ?? $run->workspace?->name }}</span></div>
                                </div>

                                <div class="rounded-md border border-border bg-background p-3">
                                    <p class="text-xs font-semibold text-textPrimary">Brief or plan preview</p>
                                    <p class="mt-1 text-sm text-textSecondary">{{ $preview ?: 'No generated preview is available yet.' }}</p>
                                    @if ($proposalItems->isNotEmpty())
                                        <ul class="mt-3 space-y-2 text-sm text-textSecondary">
                                            @foreach ($proposalItems->take(4) as $item)
                                                @php($item = (array) $item)
                                                <li class="rounded-md border border-border bg-surface px-3 py-2">
                                                    <span class="font-medium text-textPrimary">{{ str_replace('_', ' ', (string) ($item['type'] ?? 'proposal')) }}</span>
                                                    <span>{{ $item['text'] ?? $item['reason'] ?? json_encode($item) }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                    @if ($notes = (array) data_get($run->input_snapshot, 'approval_notes', []))
                                        <div class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                            Latest note: {{ data_get(last($notes), 'note') }}
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <div class="space-y-3 rounded-lg border border-border bg-background p-3">
                                <form method="POST" action="{{ route('app.agentic-marketing.approvals.approve', $run) }}">
                                    @csrf
                                    <button class="pl-btn-primary w-full justify-center" type="submit"><i data-lucide="check" class="h-4 w-4"></i><span>Approve</span></button>
                                </form>
                                <form method="POST" action="{{ route('app.agentic-marketing.approvals.run', $run) }}">
                                    @csrf
                                    <button class="pl-btn-ghost w-full justify-center" type="submit"><i data-lucide="play" class="h-4 w-4"></i><span>Run approved action</span></button>
                                </form>
                                <form method="POST" action="{{ route('app.agentic-marketing.approvals.request-changes', $run) }}" class="space-y-2">
                                    @csrf
                                    <textarea name="note" rows="3" class="pl-input w-full text-sm" placeholder="Request changes"></textarea>
                                    <button class="pl-btn-ghost w-full justify-center" type="submit"><i data-lucide="message-square" class="h-4 w-4"></i><span>Request changes</span></button>
                                </form>
                                <form method="POST" action="{{ route('app.agentic-marketing.approvals.reject', $run) }}" class="space-y-2">
                                    @csrf
                                    <input name="note" class="pl-input w-full text-sm" placeholder="Optional rejection note">
                                    <button class="w-full justify-center rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-medium text-rose-800 hover:bg-rose-100" type="submit">
                                        Reject
                                    </button>
                                </form>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="rounded-lg border border-border bg-surface px-5 py-10 text-center">
                        <p class="text-sm font-medium text-textPrimary">No actions need approval</p>
                        <p class="mt-1 text-sm text-textSecondary">When Agentic Marketing actions require customer review, they will appear here.</p>
                    </div>
                @endforelse
            </div>

            @if ($runs->hasPages())
                <div class="rounded-lg border border-border bg-surface px-5 py-4">{{ $runs->links() }}</div>
            @endif
        </div>
    </div>
@endsection
