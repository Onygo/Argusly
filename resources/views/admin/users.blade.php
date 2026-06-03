<x-app.layout title="Admin Users" :show-workspace-header="false">
    @include('admin._nav')
    <h1 class="text-2xl font-bold text-ink">Users & Memberships</h1>
    <form method="GET" class="mt-4 flex gap-2">
        <input name="q" value="{{ request('q') }}" placeholder="Search users" class="rounded-md border border-line px-3 py-2 text-sm">
        <button class="rounded-md bg-ink px-4 py-2 text-sm font-semibold text-white">Search</button>
    </form>
    <div class="mt-4 overflow-hidden rounded-md border border-line bg-white">
        <table class="min-w-full divide-y divide-line text-left">
            <thead class="bg-panel">
                <tr>
                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.08em] text-muted">User</th>
                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.08em] text-muted">Accounts</th>
                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.08em] text-muted">Brands</th>
                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-[0.08em] text-muted">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($users as $user)
                    <tr>
                        <td class="px-4 py-3">
                            <p class="text-sm font-semibold text-ink">{{ $user->name }}</p>
                            <p class="text-xs text-muted">{{ $user->email }}</p>
                        </td>
                        <td class="px-4 py-3 text-sm text-muted">{{ $user->memberships->pluck('account.name')->filter()->join(', ') ?: 'None' }}</td>
                        <td class="px-4 py-3 text-sm text-muted">{{ $user->brandMemberships->pluck('brand.name')->filter()->join(', ') ?: 'None' }}</td>
                        <td class="px-4 py-3">
                            @if (auth()->id() !== $user->id)
                                <form method="POST" action="{{ route('admin.users.impersonate', $user) }}">
                                    @csrf
                                    <button class="rounded-md border border-line px-3 py-1.5 text-sm font-semibold text-ink transition hover:bg-panel">Impersonate</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-sm text-muted">No users found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $users->links() }}</div>

    <div class="mt-6 grid gap-4 xl:grid-cols-2">
        <form method="POST" action="{{ route('admin.memberships.accounts.store') }}" class="rounded-md border border-line bg-white p-4">
            @csrf
            <h2 class="text-lg font-bold text-ink">Assign User To Account</h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <select name="user_id" required class="rounded-md border border-line px-3 py-2 text-sm">@foreach ($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select>
                <select name="account_id" required class="rounded-md border border-line px-3 py-2 text-sm">@foreach ($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select>
                <select name="role_id" required class="rounded-md border border-line px-3 py-2 text-sm">@foreach ($roles as $role)<option value="{{ $role->id }}">{{ $role->display_name }}</option>@endforeach</select>
                <select name="status" class="rounded-md border border-line px-3 py-2 text-sm"><option value="active">Active</option><option value="pending">Pending</option></select>
                <button class="rounded-md bg-blue px-4 py-2 text-sm font-semibold text-white sm:col-span-2">Assign account membership</button>
            </div>
        </form>
        <form method="POST" action="{{ route('admin.memberships.brands.store') }}" class="rounded-md border border-line bg-white p-4">
            @csrf
            <h2 class="text-lg font-bold text-ink">Assign User To Brand</h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <select name="user_id" required class="rounded-md border border-line px-3 py-2 text-sm">@foreach ($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select>
                <select name="brand_id" required class="rounded-md border border-line px-3 py-2 text-sm">@foreach ($brands as $brand)<option value="{{ $brand->id }}">{{ $brand->name }}</option>@endforeach</select>
                <select name="role_id" required class="rounded-md border border-line px-3 py-2 text-sm">@foreach ($roles as $role)<option value="{{ $role->id }}">{{ $role->display_name }}</option>@endforeach</select>
                <select name="status" class="rounded-md border border-line px-3 py-2 text-sm"><option value="active">Active</option><option value="pending">Pending</option></select>
                <button class="rounded-md bg-blue px-4 py-2 text-sm font-semibold text-white sm:col-span-2">Assign brand membership</button>
            </div>
        </form>
    </div>
</x-app.layout>
