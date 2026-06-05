<div class="mb-6 rounded-lg border border-border bg-surface p-4">
    <div class="mb-3 flex items-center gap-2">
        <span class="inline-flex h-6 w-6 items-center justify-center rounded-md bg-accentYellow-100 text-accentYellow-900">
            <i data-lucide="building-2" class="h-3.5 w-3.5"></i>
        </span>
        <h2 class="text-sm font-semibold text-textPrimary">Site allocations</h2>
    </div>

    <div class="mb-4 grid gap-3 lg:grid-cols-3">
        <form method="POST" action="{{ route('app.billing.allocations.allocate') }}" class="rounded-md border border-border p-3">
            @csrf
            <p class="mb-2 text-sm font-medium text-textPrimary">Allocate workspace credits</p>
            <select name="client_site_id" class="mb-2 w-full rounded border border-border bg-background px-2 py-2 text-sm" required>
                @foreach ($wallets as $wallet)
                    <option value="{{ $wallet['client_site_id'] }}">{{ $wallet['site_name'] }}</option>
                @endforeach
            </select>
            <input type="number" min="1" name="amount" placeholder="Credits" class="mb-2 w-full rounded border border-border bg-background px-2 py-2 text-sm" required>
            <button class="rounded border border-border px-3 py-2 text-sm">Allocate</button>
        </form>

        <form method="POST" action="{{ route('app.billing.allocations.reclaim') }}" class="rounded-md border border-border p-3">
            @csrf
            <p class="mb-2 text-sm font-medium text-textPrimary">Reclaim to workspace pool</p>
            <select name="client_site_id" class="mb-2 w-full rounded border border-border bg-background px-2 py-2 text-sm" required>
                @foreach ($wallets as $wallet)
                    <option value="{{ $wallet['client_site_id'] }}">{{ $wallet['site_name'] }}</option>
                @endforeach
            </select>
            <input type="number" min="1" name="amount" placeholder="Credits" class="mb-2 w-full rounded border border-border bg-background px-2 py-2 text-sm" required>
            <button class="rounded border border-border px-3 py-2 text-sm">Reclaim</button>
        </form>

        <form method="POST" action="{{ route('app.billing.allocations.transfer') }}" class="rounded-md border border-border p-3">
            @csrf
            <p class="mb-2 text-sm font-medium text-textPrimary">Transfer between sites</p>
            <select name="from_client_site_id" class="mb-2 w-full rounded border border-border bg-background px-2 py-2 text-sm" required>
                @foreach ($wallets as $wallet)
                    <option value="{{ $wallet['client_site_id'] }}">From {{ $wallet['site_name'] }}</option>
                @endforeach
            </select>
            <select name="to_client_site_id" class="mb-2 w-full rounded border border-border bg-background px-2 py-2 text-sm" required>
                @foreach ($wallets as $wallet)
                    <option value="{{ $wallet['client_site_id'] }}">To {{ $wallet['site_name'] }}</option>
                @endforeach
            </select>
            <input type="number" min="1" name="amount" placeholder="Credits" class="mb-2 w-full rounded border border-border bg-background px-2 py-2 text-sm" required>
            <button class="rounded border border-border px-3 py-2 text-sm">Transfer</button>
        </form>
    </div>

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($wallets as $wallet)
            @php
                $walletDrawer = [
                    'kind' => 'wallet',
                    'id' => (string) ($wallet['id'] ?? ''),
                    'site_name' => (string) $wallet['site_name'],
                    'site_id' => (string) $wallet['client_site_id'],
                    'available' => (int) $wallet['available'],
                    'reserved' => (int) $wallet['reserved_cached'],
                    'balance' => (int) $wallet['balance_cached'],
                    'used' => (int) ($wallet['used_cached'] ?? 0),
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
                <p class="text-xs text-textSecondary">Allocation {{ number_format($wallet['balance_cached']) }} · Remaining {{ number_format($wallet['available']) }} · Reserved {{ number_format($wallet['reserved_cached']) }} · Used {{ number_format($wallet['used_cached'] ?? 0) }}</p>
                <p class="mt-1 text-[11px] text-textSecondary">Workspace unallocated pool: {{ number_format($wallet['workspace_unallocated_credits'] ?? 0) }}</p>
            </div>
        @empty
            <p class="text-sm text-textSecondary">No sites found for this organization.</p>
        @endforelse
    </div>
</div>
