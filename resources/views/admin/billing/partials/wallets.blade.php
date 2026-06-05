<div class="mb-6 rounded-lg border border-border bg-surface p-4">
    <div class="mb-3 flex items-center gap-2">
        <span class="inline-flex h-6 w-6 items-center justify-center rounded-md bg-accentYellow-100 text-accentYellow-900">
            <i data-lucide="building-2" class="h-3.5 w-3.5"></i>
        </span>
        <h3 class="text-sm font-semibold text-textPrimary">Site allocations</h3>
    </div>

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($wallets as $wallet)
            @php
                $walletDrawer = [
                    'kind' => 'wallet',
                    'id' => (string) $wallet['id'],
                    'site_name' => (string) $wallet['site_name'],
                    'site_id' => (string) $wallet['client_site_id'],
                    'available' => (int) $wallet['available'],
                    'reserved' => (int) $wallet['reserved_cached'],
                    'balance' => (int) $wallet['balance_cached'],
                    'organization_name' => (string) $organization->name,
                    'organization_id' => (string) $organization->id,
                    'updated_at' => optional($wallet['updated_at'])->toDateTimeString(),
                ];
            @endphp
            <div class="rounded-md border border-border p-3">
                <div class="mb-2 flex items-start justify-between gap-2">
                    <div>
                        <p class="text-sm font-medium text-textPrimary">{{ $wallet['site_name'] }}</p>
                        <p class="text-xs text-textSecondary">{{ $wallet['site_url'] ?: '-' }}</p>
                    </div>
                    <button type="button" class="inline-flex items-center gap-1 rounded border border-border px-2 py-1 text-xs" data-open-drawer data-drawer='@json($walletDrawer)'>
                        <i data-lucide="panel-right-open" class="h-3.5 w-3.5"></i>
                        View details
                    </button>
                </div>
                <p class="text-xs text-textSecondary">Available {{ number_format($wallet['available']) }} · Reserved {{ number_format($wallet['reserved_cached']) }} · Allocation {{ number_format($wallet['balance_cached']) }} · Used {{ number_format($wallet['used_cached']) }}</p>
            </div>
        @empty
            <p class="text-sm text-textSecondary">No site allocations found.</p>
        @endforelse
    </div>
</div>
