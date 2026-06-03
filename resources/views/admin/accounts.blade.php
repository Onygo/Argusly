<x-app.layout title="Admin Accounts" :show-workspace-header="false">
    @include('admin._nav')
    <h1 class="text-2xl font-bold text-ink">Accounts</h1>

    <div class="mt-4 grid gap-4 xl:grid-cols-[1fr_360px]">
        <div>
            <form method="GET" class="mb-4 flex flex-wrap gap-2">
                <input name="q" value="{{ request('q') }}" placeholder="Search accounts" class="rounded-md border border-line px-3 py-2 text-sm">
                <select name="status" class="rounded-md border border-line px-3 py-2 text-sm">
                    <option value="">Any status</option>
                    @foreach (['active', 'pending', 'paused', 'archived'] as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ str($status)->headline() }}</option>
                    @endforeach
                </select>
                <button class="rounded-md bg-ink px-4 py-2 text-sm font-semibold text-white">Search</button>
            </form>
            <div class="overflow-hidden rounded-md border border-line bg-white">
                <table class="min-w-full divide-y divide-line text-left">
                    <thead class="bg-panel">
                        <tr>
                            <th class="px-4 py-3 text-xs font-semibold uppercase text-muted">Account</th>
                            <th class="px-4 py-3 text-xs font-semibold uppercase text-muted">Status</th>
                            <th class="px-4 py-3 text-xs font-semibold uppercase text-muted">Brands</th>
                            <th class="px-4 py-3 text-xs font-semibold uppercase text-muted">Users</th>
                            <th class="px-4 py-3 text-xs font-semibold uppercase text-muted">Credits</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($accounts as $account)
                            <tr>
                                <td class="px-4 py-3"><a href="{{ route('admin.accounts.show', $account) }}" class="font-semibold text-blue">{{ $account->name }}</a><p class="text-xs text-muted">{{ $account->slug }}</p></td>
                                <td class="px-4 py-3">@include('admin._status', ['value' => $account->status])</td>
                                <td class="px-4 py-3 text-sm text-ink">{{ $account->brands_count }}</td>
                                <td class="px-4 py-3 text-sm text-ink">{{ $account->users_count }}</td>
                                <td class="px-4 py-3 text-sm text-ink">{{ $account->creditBalance?->balance ?? 0 }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-10 text-center text-sm text-muted">No accounts found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $accounts->links() }}</div>
        </div>

        <form method="POST" action="{{ route('admin.accounts.store') }}" class="rounded-md border border-line bg-white p-4">
            @csrf
            <h2 class="text-lg font-bold text-ink">Create Account</h2>
            <div class="mt-4 space-y-3">
                <input name="name" placeholder="Name" required class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <input name="slug" placeholder="Slug optional" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <select name="status" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                    @foreach (['active', 'pending', 'paused', 'archived'] as $status)
                        <option value="{{ $status }}">{{ str($status)->headline() }}</option>
                    @endforeach
                </select>
                <button class="w-full rounded-md bg-blue px-4 py-2 text-sm font-semibold text-white">Create account</button>
            </div>
        </form>
    </div>
</x-app.layout>
