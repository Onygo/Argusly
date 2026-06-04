<x-app.layout title="LLM Requests">
    @include('admin._nav')

    <div class="mt-6 flex flex-col gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-ink">LLM requests</h1>
            <p class="text-sm text-muted">Inspect provider calls, usage, costs, credit consumption and failures across all tenants.</p>
        </div>

        <form method="GET" action="{{ route('admin.llm-requests') }}" class="grid gap-3 rounded-md border border-line bg-white p-4 md:grid-cols-5">
            <select name="account_id" class="rounded-md border border-line px-3 py-2 text-sm">
                <option value="">All accounts</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}" @selected((int) request('account_id') === $account->id)>{{ $account->name }}</option>
                @endforeach
            </select>
            <input name="provider" value="{{ request('provider') }}" placeholder="Provider" class="rounded-md border border-line px-3 py-2 text-sm">
            <select name="purpose" class="rounded-md border border-line px-3 py-2 text-sm">
                <option value="">All purposes</option>
                @foreach ($purposes as $purpose)
                    <option value="{{ $purpose }}" @selected(request('purpose') === $purpose)>{{ str($purpose)->replace('_', ' ')->headline() }}</option>
                @endforeach
            </select>
            <select name="status" class="rounded-md border border-line px-3 py-2 text-sm">
                <option value="">All statuses</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ str($status)->headline() }}</option>
                @endforeach
            </select>
            <button class="rounded-md bg-ink px-4 py-2 text-sm font-semibold text-white">Filter</button>
        </form>

        @include('admin._table', [
            'rows' => $requests,
            'columns' => [
                'created_at',
                'completed_at',
                'account.name',
                'brand.name',
                'user.name',
                'provider',
                'model',
                'purpose',
                'status',
                'prompt_tokens',
                'completion_tokens',
                'total_tokens',
                'estimated_cost',
                'credits_charged',
                'latency_ms',
                'error_message',
                'metadata',
            ],
        ])
    </div>
</x-app.layout>
