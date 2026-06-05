<div class="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
    <div class="rounded-lg border border-border bg-surface p-4">
        <div class="mb-2 flex items-center gap-2 text-xs text-textSecondary">
            <span class="inline-flex h-6 w-6 items-center justify-center rounded-md bg-accentYellow-100 text-accentYellow-900">
                <i data-lucide="coins" class="h-3.5 w-3.5"></i>
            </span>
            Workspace credits available
        </div>
        <p class="text-xl font-semibold text-textPrimary">{{ number_format($totals['available'] ?? 0) }}</p>
    </div>
    <div class="rounded-lg border border-border bg-surface p-4">
        <div class="mb-2 flex items-center gap-2 text-xs text-textSecondary">
            <span class="inline-flex h-6 w-6 items-center justify-center rounded-md bg-accentYellow-100 text-accentYellow-900">
                <i data-lucide="lock" class="h-3.5 w-3.5"></i>
            </span>
            Reserved across sites
        </div>
        <p class="text-xl font-semibold text-textPrimary">{{ number_format($totals['reserved_cached'] ?? 0) }}</p>
    </div>
    <div class="rounded-lg border border-border bg-surface p-4">
        <div class="mb-2 flex items-center gap-2 text-xs text-textSecondary">
            <span class="inline-flex h-6 w-6 items-center justify-center rounded-md bg-accentYellow-100 text-accentYellow-900">
                <i data-lucide="wallet" class="h-3.5 w-3.5"></i>
            </span>
            Allocated to sites
        </div>
        <p class="text-xl font-semibold text-textPrimary">{{ number_format($totals['allocated_credits'] ?? 0) }}</p>
    </div>
    <div class="rounded-lg border border-border bg-surface p-4">
        <div class="mb-2 flex items-center gap-2 text-xs text-textSecondary">
            <span class="inline-flex h-6 w-6 items-center justify-center rounded-md bg-accentYellow-100 text-accentYellow-900">
                <i data-lucide="wallet" class="h-3.5 w-3.5"></i>
            </span>
            Unallocated workspace pool
        </div>
        <p class="text-xl font-semibold text-textPrimary">{{ number_format($totals['unallocated_credits'] ?? 0) }}</p>
    </div>
    <div class="rounded-lg border border-border bg-surface p-4">
        <div class="mb-2 flex items-center gap-2 text-xs text-textSecondary">
            <span class="inline-flex h-6 w-6 items-center justify-center rounded-md bg-accentYellow-100 text-accentYellow-900">
                <i data-lucide="receipt" class="h-3.5 w-3.5"></i>
            </span>
            Credits used by sites
        </div>
        <p class="text-xl font-semibold text-textPrimary">{{ number_format($totals['used_credits'] ?? 0) }}</p>
    </div>
</div>
