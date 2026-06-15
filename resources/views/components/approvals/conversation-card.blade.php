@props(['run', 'service'])

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
    $risk = $service->riskLevel($run);
    $recommended = $service->isRecommendedApproval($run);
    $blocked = $run->status === \App\Models\AgenticActionRun::STATUS_BLOCKED || str_contains($service->approvalReason($run), 'blocked');
@endphp

<article {{ $attributes->merge(['class' => 'rounded-lg border border-border bg-surface']) }}>
    <div class="grid gap-4 p-5 xl:grid-cols-[minmax(0,1fr)_340px]">
        <div class="min-w-0 space-y-4">
            <div class="rounded-md border border-border bg-background p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Argusly says</p>
                <p class="mt-1 text-base font-semibold text-textPrimary">{{ $service->conversationPrompt($run) }}</p>
                <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $service->approvalReason($run) }}</p>
            </div>

            <div>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($recommended)
                        <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-800">Recommended approval</span>
                    @elseif ($blocked)
                        <span class="rounded-full border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-800">Blocked</span>
                    @else
                        <span class="rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800">Needs judgment</span>
                    @endif
                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', $run->action_type) }}</span>
                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ ucfirst($run->execution_mode_snapshot) }}</span>
                </div>
                <h2 class="mt-3 text-base font-semibold text-textPrimary">{{ $run->opportunity?->title ?? $action?->opportunity?->title ?? $run->goal?->name ?? 'Agentic Marketing action' }}</h2>
                <p class="mt-1 text-sm text-textSecondary">{{ $run->reason ?: data_get($payload, 'reason', 'No reason recorded.') }}</p>
            </div>

            <div class="grid gap-2 text-xs text-textSecondary sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-md border border-border bg-background px-3 py-2">Destination <span class="font-semibold text-textPrimary">{{ $destination }}</span></div>
                <div class="rounded-md border border-border bg-background px-3 py-2">Credits <span class="font-semibold text-textPrimary">{{ number_format((int) ($run->estimated_credits ?? 0)) }}</span></div>
                <div class="rounded-md border border-border bg-background px-3 py-2">Risk <span class="font-semibold text-textPrimary">{{ ucfirst($risk ?: 'low') }}</span></div>
                <div class="rounded-md border border-border bg-background px-3 py-2">Workspace <span class="font-semibold text-textPrimary">{{ $run->workspace?->display_name ?? $run->workspace?->name }}</span></div>
            </div>

            <div class="rounded-md border border-border bg-background p-3">
                <p class="text-xs font-semibold text-textPrimary">Prepared context</p>
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
                <button class="pl-btn-primary w-full justify-center" type="submit" @disabled($blocked)><i data-lucide="check" class="h-4 w-4"></i><span>Approve</span></button>
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
