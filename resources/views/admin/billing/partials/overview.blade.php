<div class="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
    <div class="rounded-lg border border-border bg-surface p-4">
        <div class="mb-2 flex items-center gap-2 text-xs text-textSecondary"><span class="inline-flex h-6 w-6 items-center justify-center rounded-md bg-accentYellow-100 text-accentYellow-900"><i data-lucide="coins" class="h-3.5 w-3.5"></i></span> Total available credits</div>
        <p class="text-xl font-semibold text-textPrimary">{{ number_format($walletStats['total_available']) }}</p>
    </div>
    <div class="rounded-lg border border-border bg-surface p-4">
        <div class="mb-2 flex items-center gap-2 text-xs text-textSecondary"><span class="inline-flex h-6 w-6 items-center justify-center rounded-md bg-accentYellow-100 text-accentYellow-900"><i data-lucide="lock" class="h-3.5 w-3.5"></i></span> Total reserved credits</div>
        <p class="text-xl font-semibold text-textPrimary">{{ number_format($walletStats['total_reserved']) }}</p>
    </div>
    <div class="rounded-lg border border-border bg-surface p-4">
        <div class="mb-2 flex items-center gap-2 text-xs text-textSecondary"><span class="inline-flex h-6 w-6 items-center justify-center rounded-md bg-accentYellow-100 text-accentYellow-900"><i data-lucide="wallet" class="h-3.5 w-3.5"></i></span> Total balance</div>
        <p class="text-xl font-semibold text-textPrimary">{{ number_format($walletStats['total_balance']) }}</p>
    </div>
    <div class="rounded-lg border border-border bg-surface p-4">
        <div class="mb-2 flex items-center gap-2 text-xs text-textSecondary"><span class="inline-flex h-6 w-6 items-center justify-center rounded-md bg-accentYellow-100 text-accentYellow-900"><i data-lucide="receipt" class="h-3.5 w-3.5"></i></span> Open payments amount</div>
        <p class="text-xl font-semibold text-textPrimary">{{ number_format($walletStats['open_payments_amount_cents'] / 100, 2) }} EUR</p>
    </div>
</div>
