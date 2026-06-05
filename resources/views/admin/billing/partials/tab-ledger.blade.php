<form method="GET" class="mb-4 grid gap-2 lg:grid-cols-6">
    <input type="hidden" name="tab" value="ledger">
    <select name="ledger_site" class="rounded border border-border bg-background px-2 py-2 text-xs">
        <option value="">All sites</option>
        @foreach ($sites as $site)
            <option value="{{ $site->id }}" @selected($ledgerFilters['site'] === $site->id)>{{ $site->name }}</option>
        @endforeach
    </select>
    <select name="ledger_type" class="rounded border border-border bg-background px-2 py-2 text-xs">
        <option value="">All types</option>
        @foreach ($ledgerTypes as $type)
            <option value="{{ $type }}" @selected($ledgerFilters['type'] === $type)>{{ $type }}</option>
        @endforeach
    </select>
    <input type="date" name="ledger_from" value="{{ $ledgerFilters['from'] }}" class="rounded border border-border bg-background px-2 py-2 text-xs" aria-label="From date">
    <input type="date" name="ledger_to" value="{{ $ledgerFilters['to'] }}" class="rounded border border-border bg-background px-2 py-2 text-xs" aria-label="To date">
    <input type="text" name="ledger_q" value="{{ $ledgerFilters['q'] }}" placeholder="Search note or reference" class="rounded border border-border bg-background px-2 py-2 text-xs lg:col-span-2">
    <div class="lg:col-span-6 flex gap-2">
        <button class="rounded border border-border px-3 py-1.5 text-xs">Apply filters</button>
        <a href="{{ route('admin.organizations.billing', [$organization, 'tab' => 'ledger']) }}" class="rounded border border-border px-3 py-1.5 text-xs text-textSecondary">Reset</a>
    </div>
</form>

<div class="space-y-2">
    @forelse ($ledgerRows as $row)
        @php
            $isPositive = $row['amount'] >= 0;
            $ledgerDrawer = [
                'kind' => 'ledger',
                'id' => $row['id'],
                'type' => $row['type'],
                'amount' => $row['amount'],
                'created_at' => optional($row['created_at'])->toDateTimeString(),
                'site_name' => $row['site_name'],
                'site_id' => $row['site_id'],
                'organization_name' => (string) $organization->name,
                'organization_id' => (string) $organization->id,
                'note' => $row['note'],
                'source_type' => $row['source_type'],
                'source_id' => $row['source_id'],
                'brief_id' => $row['brief_id'],
                'user_id' => $row['user_id'],
                'reference' => $row['reference'],
                'meta' => $row['meta'],
            ];
        @endphp
        <button type="button" class="w-full rounded-md border border-border p-3 text-left hover:bg-surfaceSubtle/30" data-open-drawer data-drawer='@json($ledgerDrawer)'>
            <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-textPrimary">{{ $row['type'] }}</p>
                    <p class="truncate text-xs text-textSecondary">{{ $row['site_name'] }} · {{ $row['reference'] }}</p>
                </div>
                <div class="text-right">
                    <p class="text-sm font-semibold {{ $isPositive ? 'text-emerald-700' : 'text-rose-700' }}">{{ $isPositive ? '+' : '' }}{{ number_format($row['amount']) }}</p>
                    <p class="text-xs text-textSecondary">{{ optional($row['created_at'])->format('Y-m-d H:i') }}</p>
                </div>
            </div>
        </button>
    @empty
        <p class="text-sm text-textSecondary">No ledger entries found.</p>
    @endforelse
</div>

<div class="mt-4">{{ $ledgerRows->links() }}</div>
