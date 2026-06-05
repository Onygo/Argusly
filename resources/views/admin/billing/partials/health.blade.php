<section class="mb-6 rounded-xl border border-border bg-panel p-4">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h2 class="text-sm font-semibold text-textPrimary">Billing health</h2>
            <p class="mt-1 text-xs text-textSecondary">Runtime audit of expiry enforcement, rollover policy, and workspace-shared balances.</p>
        </div>
        <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ ($billingAudit['health'] ?? 'ok') === 'critical' ? 'bg-red-100 text-red-700' : (($billingAudit['health'] ?? 'ok') === 'warning' ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700') }}">
            {{ strtoupper($billingAudit['health'] ?? 'ok') }}
        </span>
    </div>

    <div class="mt-4 grid gap-3 md:grid-cols-3">
        @foreach(($billingAudit['claim_statuses'] ?? []) as $claim => $status)
            <div class="rounded-lg border border-border px-3 py-2">
                <p class="text-xs uppercase tracking-wide text-textMuted">{{ str_replace('_', ' ', $claim) }}</p>
                <p class="mt-1 text-sm font-medium text-textPrimary">{{ $status }}</p>
            </div>
        @endforeach
    </div>

    @if(!empty($billingAudit['issues']))
        <div class="mt-4 space-y-2">
            @foreach($billingAudit['issues'] as $issue)
                <div class="rounded-lg border border-border px-3 py-2">
                    <p class="text-xs font-semibold uppercase tracking-wide text-textMuted">{{ strtoupper($issue['severity'] ?? 'info') }}</p>
                    <p class="mt-1 text-sm text-textPrimary">{{ $issue['message'] ?? '' }}</p>
                    <p class="mt-1 text-xs text-textSecondary">Affected records: {{ (int) ($issue['count'] ?? 0) }}</p>
                </div>
            @endforeach
        </div>
    @else
        <p class="mt-4 text-sm text-textSecondary">No billing mismatches detected for this organization.</p>
    @endif
</section>
