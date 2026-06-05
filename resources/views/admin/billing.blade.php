<x-app.layout title="Admin Billing" :show-workspace-header="false">
    @include('admin._nav')

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-ink">Billing</h1>
            <p class="mt-1 text-sm text-muted">Commercial operations for plans, entitlements, invoices, usage and Mollie checkout.</p>
        </div>
        <a href="{{ route('admin.credit-costs') }}" class="rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink hover:bg-panel">Cost catalog</a>
    </div>

    <div class="mt-4 grid gap-3 md:grid-cols-3 xl:grid-cols-6">
        @foreach ($dashboard['stats'] as $label => $value)
            <div class="rounded-md border border-line bg-white p-4">
                <div class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">{{ str($label)->headline() }}</div>
                <div class="mt-2 text-2xl font-bold text-ink">{{ is_numeric($value) ? number_format((int) $value) : $value }}</div>
            </div>
        @endforeach
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-[1fr_380px]">
        <div class="space-y-4">
            <section class="rounded-md border border-line bg-white p-4">
                <h2 class="text-lg font-bold text-ink">Billing Report</h2>
                <div class="mt-4 grid gap-3 md:grid-cols-4">
                    @foreach ($dashboard['billingReport'] as $label => $value)
                        <div class="rounded-md border border-line p-3">
                            <div class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">{{ str($label)->headline() }}</div>
                            <div class="mt-1 text-xl font-bold text-ink">{{ number_format((int) $value) }}</div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="rounded-md border border-line bg-white p-4">
                <h2 class="text-lg font-bold text-ink">Invoices</h2>
                @include('admin._table', ['rows' => $dashboard['invoices'], 'columns' => ['uuid', 'account.name', 'subscription.plan.name', 'provider', 'status', 'currency', 'total_amount', 'issued_at', 'paid_at']])
            </section>

            <section class="rounded-md border border-line bg-white p-4">
                <h2 class="text-lg font-bold text-ink">Usage</h2>
                @if ($dashboard['usage']->isNotEmpty())
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full divide-y divide-line text-sm">
                            <thead>
                                <tr class="text-left text-xs font-semibold uppercase tracking-[0.12em] text-muted">
                                    <th class="py-2 pr-3">Account</th>
                                    <th class="px-3 py-2">Brand</th>
                                    <th class="px-3 py-2">Catalog</th>
                                    <th class="px-3 py-2">Credits</th>
                                    <th class="px-3 py-2">Executions</th>
                                    <th class="py-2 pl-3">Period</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($dashboard['usage'] as $row)
                                    <tr>
                                        <td class="py-3 pr-3 font-semibold text-ink">{{ $row['account'] }}</td>
                                        <td class="px-3 py-3">{{ $row['brand'] ?? 'Workspace' }}</td>
                                        <td class="px-3 py-3">{{ $row['catalog_code'] }}</td>
                                        <td class="px-3 py-3">{{ number_format($row['credits_used']) }}</td>
                                        <td class="px-3 py-3">{{ number_format($row['executions']) }}</td>
                                        <td class="py-3 pl-3">{{ $row['period'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="mt-3 text-sm text-muted">No usage recorded yet.</p>
                @endif
            </section>

            <section class="rounded-md border border-line bg-white p-4">
                <h2 class="text-lg font-bold text-ink">Account Commercial State</h2>
                @include('admin._table', ['rows' => $dashboard['accounts'], 'columns' => ['name', 'activeSubscription.status', 'activeSubscription.plan.name', 'creditBalance.balance', 'status']])
            </section>
        </div>

        <div class="space-y-4">
            <form method="POST" action="{{ route('admin.billing.mollie-checkout') }}" class="rounded-md border border-line bg-white p-4">
                @csrf
                <h2 class="text-lg font-bold text-ink">Mollie Checkout</h2>
                <div class="mt-4 space-y-3">
                    <select name="account_id" required class="w-full rounded-md border border-line px-3 py-2 text-sm">
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </select>
                    <select name="plan_id" required class="w-full rounded-md border border-line px-3 py-2 text-sm">
                        @foreach ($plans as $plan)
                            <option value="{{ $plan->id }}">{{ $plan->name }} - {{ $plan->currency }} {{ number_format($plan->amount / 100, 2) }}</option>
                        @endforeach
                    </select>
                    <button class="w-full rounded-md bg-blue px-4 py-2 text-sm font-semibold text-white">Create checkout</button>
                </div>
            </form>

            <form method="POST" action="{{ route('admin.billing.invoices.store') }}" class="rounded-md border border-line bg-white p-4">
                @csrf
                <h2 class="text-lg font-bold text-ink">Create Invoice</h2>
                <div class="mt-4 space-y-3">
                    <select name="account_id" required class="w-full rounded-md border border-line px-3 py-2 text-sm">
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </select>
                    <button class="w-full rounded-md bg-ink px-4 py-2 text-sm font-semibold text-white">Create invoice</button>
                </div>
            </form>

            <form method="POST" action="{{ route('admin.billing.overages.store') }}" class="rounded-md border border-line bg-white p-4">
                @csrf
                <h2 class="text-lg font-bold text-ink">Overage</h2>
                <div class="mt-4 space-y-3">
                    <select name="account_id" required class="w-full rounded-md border border-line px-3 py-2 text-sm">
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </select>
                    <input name="limit_key" required placeholder="Limit key" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                    <input name="usage" required type="number" min="0" placeholder="Usage" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                    <input name="limit" required type="number" min="0" placeholder="Limit" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                    <button class="w-full rounded-md border border-line px-4 py-2 text-sm font-semibold text-ink hover:bg-panel">Record overage</button>
                </div>
            </form>
        </div>
    </div>
</x-app.layout>
