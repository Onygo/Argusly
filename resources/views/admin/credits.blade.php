<x-app.layout title="Admin Credits" :show-workspace-header="false">
    @include('admin._nav')
    <h1 class="text-2xl font-bold text-ink">Credits</h1>
    <div class="mt-4 grid gap-4 xl:grid-cols-[1fr_380px]">
        <div class="space-y-4">
            <section class="rounded-md border border-line bg-white p-4">
                <h2 class="text-lg font-bold text-ink">Wallets</h2>
                @include('admin._table', ['rows' => $accounts, 'columns' => ['name', 'creditBalance.balance', 'status']])
            </section>
            <section class="rounded-md border border-line bg-white p-4">
                <h2 class="text-lg font-bold text-ink">Recent Transactions</h2>
                @include('admin._table', ['rows' => $transactions, 'columns' => ['account.name', 'user.name', 'amount', 'balance_after', 'type', 'description', 'created_at']])
            </section>
        </div>
        <form method="POST" action="{{ route('admin.credits.adjust') }}" class="rounded-md border border-line bg-white p-4">
            @csrf
            <h2 class="text-lg font-bold text-ink">Manual Adjustment</h2>
            <div class="mt-4 space-y-3">
                <select name="account_id" required class="w-full rounded-md border border-line px-3 py-2 text-sm">@foreach ($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select>
                <input name="amount" required type="number" placeholder="Positive or negative amount" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <textarea name="reason" required placeholder="Reason" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
                <button class="w-full rounded-md bg-blue px-4 py-2 text-sm font-semibold text-white">Record credit change</button>
            </div>
        </form>
    </div>
</x-app.layout>
