<form method="GET" class="mb-4 grid gap-2 lg:grid-cols-4">
    <input type="hidden" name="tab" value="subscriptions">
    <select name="subscription_site" class="rounded border border-border bg-background px-2 py-2 text-xs">
        <option value="">All sites</option>
        @foreach ($sites as $site)
            <option value="{{ $site->id }}" @selected($subscriptionFilters['site'] === $site->id)>{{ $site->name }}</option>
        @endforeach
    </select>
    <select name="subscription_status" class="rounded border border-border bg-background px-2 py-2 text-xs">
        @foreach ($subscriptionStatuses as $status)
            <option value="{{ $status }}" @selected($subscriptionFilters['status'] === $status)>{{ $status === 'all' ? 'All statuses' : $status }}</option>
        @endforeach
    </select>
    <div class="lg:col-span-2 flex gap-2">
        <button class="rounded border border-border px-3 py-1.5 text-xs">Apply filters</button>
        <a href="{{ route('app.billing.index', ['tab' => 'subscriptions']) }}" class="rounded border border-border px-3 py-1.5 text-xs text-textSecondary">Reset</a>
    </div>
</form>

<div class="space-y-2">
    @forelse ($subscriptionRows as $row)
        @php
            $subscriptionDrawer = [
                'kind' => 'subscription',
                'id' => $row['id'],
                'status' => $row['status'],
                'status_reason' => $row['status_reason'] ?? '',
                'plan_name' => $row['plan_name'],
                'site_name' => $row['site_name'],
                'site_id' => $row['site_id'],
                'current_period_end' => optional($row['current_period_end'])->toDateTimeString(),
                'next_payment_at' => optional($row['next_payment_at'])->toDateTimeString(),
                'price_cents' => $row['price_cents'],
                'currency' => $row['currency'],
                'included_credits_per_interval' => $row['included_credits_per_interval'],
                'provider' => $row['provider'],
                'provider_customer_id' => $row['provider_customer_id'],
                'provider_subscription_id' => $row['provider_subscription_id'],
                'canceled_at' => optional($row['canceled_at'])->toDateTimeString(),
                'meta' => $row['meta'],
            ];
        @endphp
        <button type="button" class="w-full rounded-md border border-border p-3 text-left hover:bg-surfaceSubtle/30" data-open-drawer data-drawer='@json($subscriptionDrawer)'>
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium text-textPrimary">{{ $row['plan_name'] }}</p>
                    <p class="text-xs text-textSecondary">{{ $row['status'] }} · {{ $row['site_name'] }}</p>
                </div>
                <p class="text-xs text-textSecondary">{{ optional($row['next_payment_at'] ?? $row['current_period_end'])->format('Y-m-d') ?? 'n/a' }}</p>
            </div>
        </button>
    @empty
        <p class="text-sm text-textSecondary">No subscriptions found.</p>
    @endforelse
</div>

<div class="mt-4">{{ $subscriptionRows->links() }}</div>
