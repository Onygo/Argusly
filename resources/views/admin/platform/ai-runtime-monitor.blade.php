<x-app.layout title="AI Runtime Monitor" :show-workspace-header="false">
    @include('admin._nav')

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.12em] text-muted">Platform</p>
            <h1 class="mt-1 text-3xl font-bold text-ink">AI Runtime Monitor</h1>
        </div>
        <form method="GET" class="flex flex-wrap gap-2">
            <select name="account_id" class="rounded-md border border-line px-3 py-2 text-sm">
                <option value="">All workspaces</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}" @selected((int) request('account_id') === $account->id)>{{ $account->name }}</option>
                @endforeach
            </select>
            <input name="provider" value="{{ request('provider') }}" placeholder="Provider" class="rounded-md border border-line px-3 py-2 text-sm">
            <select name="purpose" class="rounded-md border border-line px-3 py-2 text-sm">
                <option value="">All purposes</option>
                @foreach ($purposes as $purpose)
                    <option value="{{ $purpose }}" @selected(request('purpose') === $purpose)>{{ str($purpose)->headline() }}</option>
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
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-7">
        @foreach ($summary as $label => $value)
            <div class="rounded-md border border-line bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.08em] text-muted">{{ str($label)->headline() }}</p>
                <p class="mt-3 text-2xl font-bold text-ink">{{ is_float($value) ? number_format($value, 4) : $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-6 grid gap-4 xl:grid-cols-[0.8fr_1.4fr]">
        <div class="rounded-md border border-line bg-white p-4">
            <h2 class="text-lg font-bold text-ink">By Provider</h2>
            <div class="mt-4 space-y-3">
                @forelse ($byProvider as $provider)
                    <div class="rounded-md bg-panel p-3">
                        <div class="flex justify-between gap-3">
                            <p class="text-sm font-semibold text-ink">{{ $provider->provider }}</p>
                            <p class="text-sm text-muted">{{ $provider->requests_count }} requests</p>
                        </div>
                        <p class="mt-1 text-xs text-muted">{{ (int) $provider->total_tokens_sum }} tokens · {{ number_format((float) $provider->estimated_cost_sum, 6) }} estimated cost</p>
                    </div>
                @empty
                    <p class="text-sm text-muted">No provider usage yet.</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-md border border-line bg-white p-4">
            <h2 class="text-lg font-bold text-ink">Requests</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-line text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-[0.08em] text-muted">
                            <th class="py-2 pr-4">Created</th>
                            <th class="py-2 pr-4">Workspace</th>
                            <th class="py-2 pr-4">Provider</th>
                            <th class="py-2 pr-4">Purpose</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2 pr-4">Tokens</th>
                            <th class="py-2 pr-4">Cost</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($requests as $request)
                            <tr>
                                <td class="py-2 pr-4 text-muted">{{ $request->created_at?->format('Y-m-d H:i') }}</td>
                                <td class="py-2 pr-4 text-ink">{{ $request->account?->name ?? 'n/a' }}</td>
                                <td class="py-2 pr-4 text-muted">{{ $request->provider }} / {{ $request->model }}</td>
                                <td class="py-2 pr-4 text-muted">{{ $request->purpose }}</td>
                                <td class="py-2 pr-4">@include('admin._status', ['value' => $request->status])</td>
                                <td class="py-2 pr-4 text-muted">{{ $request->total_tokens ?? 0 }}</td>
                                <td class="py-2 pr-4 text-muted">{{ $request->estimated_cost ?? '0.000000' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-6 text-muted">No AI requests match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $requests->links() }}</div>
        </div>
    </div>
</x-app.layout>
