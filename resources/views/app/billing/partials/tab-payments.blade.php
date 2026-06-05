<form method="GET" class="mb-4 grid gap-2 lg:grid-cols-6">
    <input type="hidden" name="tab" value="payments">
    <select name="payment_status" class="rounded border border-border bg-background px-2 py-2 text-xs">
        <option value="">All statuses</option>
        @foreach ($paymentStatuses as $status)
            <option value="{{ $status }}" @selected($paymentFilters['status'] === $status)>{{ $status }}</option>
        @endforeach
    </select>
    <select name="payment_provider" class="rounded border border-border bg-background px-2 py-2 text-xs">
        <option value="">All providers</option>
        @foreach ($paymentProviders as $provider)
            <option value="{{ $provider }}" @selected($paymentFilters['provider'] === $provider)>{{ $provider }}</option>
        @endforeach
    </select>
    <input type="date" name="payment_from" value="{{ $paymentFilters['from'] }}" class="rounded border border-border bg-background px-2 py-2 text-xs" aria-label="From date">
    <input type="date" name="payment_to" value="{{ $paymentFilters['to'] }}" class="rounded border border-border bg-background px-2 py-2 text-xs" aria-label="To date">
    <input type="text" name="payment_q" value="{{ $paymentFilters['q'] }}" placeholder="Search payment id or reference" class="rounded border border-border bg-background px-2 py-2 text-xs lg:col-span-2">
    <div class="lg:col-span-6 flex gap-2">
        <button class="rounded border border-border px-3 py-1.5 text-xs">Apply filters</button>
        <a href="{{ route('app.billing.index', ['tab' => 'payments']) }}" class="rounded border border-border px-3 py-1.5 text-xs text-textSecondary">Reset</a>
    </div>
</form>

<div class="space-y-2">
    @forelse ($paymentsRows as $row)
        @php
            $lineItems = collect((array) data_get($row, 'meta.line_items', []))
                ->filter(fn ($line) => (int) ($line['amount_cents'] ?? 0) > 0)
                ->values();
            $paymentDrawer = [
                'kind' => 'payment',
                'id' => $row['id'],
                'status' => $row['status'],
                'provider' => $row['provider'],
                'amount_cents' => $row['amount_cents'],
                'currency' => $row['currency'],
                'created_at' => optional($row['created_at'])->toDateTimeString(),
                'paid_at' => optional($row['paid_at'])->toDateTimeString(),
                'failed_at' => optional($row['failed_at'])->toDateTimeString(),
                'canceled_at' => optional($row['canceled_at'])->toDateTimeString(),
                'provider_payment_id' => $row['provider_payment_id'],
                'checkout_url' => $row['checkout_url'],
                'site_name' => $row['site_name'],
                'site_id' => $row['site_id'],
                'billable_id' => $row['billable_id'],
                'billable_type' => $row['billable_type'],
                'meta' => $row['meta'],
                'invoice_number' => $row['invoice_number'],
                'invoice_status' => $row['invoice_status'],
            ];
        @endphp
        <div class="rounded-md border border-border p-3">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium text-textPrimary">{{ $row['status'] }} · {{ $row['provider'] }}</p>
                    <p class="text-xs text-textSecondary">{{ $row['site_name'] }} · {{ optional($row['created_at'])->format('Y-m-d H:i') }}</p>
                    @if ($lineItems->isNotEmpty())
                        <p class="mt-1 text-xs text-textSecondary">
                            {{ $lineItems->map(fn ($line) => ($line['label'] ?? 'Line item') . ' (' . (($line['type'] ?? 'one_time') === 'one_time' ? 'one time' : 'recurring') . ')')->implode(' · ') }}
                        </p>
                    @endif
                    @if ($row['invoice_id'])
                        <p class="text-xs text-textSecondary mt-1">
                            Invoice {{ $row['invoice_number'] }} ({{ $row['invoice_status'] }})
                            <a href="{{ route('app.billing.invoices.download', $row['invoice_id']) }}" class="underline">download</a>
                        </p>
                    @endif
                </div>
                <div class="flex items-center gap-3">
                    <p class="text-sm font-semibold text-textPrimary">{{ number_format($row['amount_cents'] / 100, 2) }} {{ $row['currency'] }}</p>
                    <button type="button" class="inline-flex items-center gap-1 rounded border border-border px-2 py-1 text-xs" data-open-drawer data-drawer='@json($paymentDrawer)'>
                        <i data-lucide="panel-right-open" class="h-3.5 w-3.5"></i>
                        View
                    </button>
                </div>
            </div>
        </div>
    @empty
        <p class="text-sm text-textSecondary">No payments found.</p>
    @endforelse
</div>

<div class="mt-4">{{ $paymentsRows->links() }}</div>
