<x-app.layout title="Admin Account" :show-workspace-header="false">
    @include('admin._nav')
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-ink">{{ $account->name }}</h1>
            <p class="text-sm text-muted">{{ $account->slug }} · @include('admin._status', ['value' => $account->status])</p>
        </div>
        <form method="POST" action="{{ route('admin.accounts.update', $account) }}" class="flex flex-wrap gap-2">
            @csrf
            @method('PUT')
            <input name="name" value="{{ $account->name }}" class="rounded-md border border-line px-3 py-2 text-sm">
            <select name="status" class="rounded-md border border-line px-3 py-2 text-sm">
                @foreach (['active', 'pending', 'paused', 'archived'] as $status)
                    <option value="{{ $status }}" @selected($account->status === $status)>{{ str($status)->headline() }}</option>
                @endforeach
            </select>
            <button class="rounded-md bg-ink px-4 py-2 text-sm font-semibold text-white">Save</button>
        </form>
    </div>

    <div class="mt-6 grid gap-4 lg:grid-cols-4">
        <div class="rounded-md border border-line bg-white p-4"><p class="text-xs uppercase text-muted">Brands</p><p class="mt-2 text-2xl font-bold">{{ $account->brands->count() }}</p></div>
        <div class="rounded-md border border-line bg-white p-4"><p class="text-xs uppercase text-muted">Users</p><p class="mt-2 text-2xl font-bold">{{ $account->memberships->count() }}</p></div>
        <div class="rounded-md border border-line bg-white p-4"><p class="text-xs uppercase text-muted">Active Modules</p><p class="mt-2 text-2xl font-bold">{{ $account->subscriptionModules->where('status', 'active')->count() }}</p></div>
        <div class="rounded-md border border-line bg-white p-4"><p class="text-xs uppercase text-muted">Credits</p><p class="mt-2 text-2xl font-bold">{{ $account->creditBalance?->balance ?? 0 }}</p></div>
    </div>

    <div class="mt-6 grid gap-4 xl:grid-cols-2">
        <section class="rounded-md border border-line bg-white p-4">
            <h2 class="text-lg font-bold text-ink">Brands</h2>
            @include('admin._table', ['rows' => $account->brands, 'columns' => ['name', 'status', 'domain', 'created_at']])
        </section>
        <section class="rounded-md border border-line bg-white p-4">
            <h2 class="text-lg font-bold text-ink">Users</h2>
            @include('admin._table', ['rows' => $account->memberships, 'columns' => ['user.name', 'user.email', 'status', 'joined_at']])
        </section>
        <section class="rounded-md border border-line bg-white p-4">
            <h2 class="text-lg font-bold text-ink">Modules</h2>
            @include('admin._table', ['rows' => $account->subscriptionModules, 'columns' => ['module.name', 'status', 'starts_at', 'ends_at']])
        </section>
        <section class="rounded-md border border-line bg-white p-4">
            <h2 class="text-lg font-bold text-ink">Integrations</h2>
            @include('admin._table', ['rows' => $account->integrationConnections, 'columns' => ['integration.name', 'name', 'status', 'last_used_at']])
        </section>
        <section class="rounded-md border border-line bg-white p-4">
            <h2 class="text-lg font-bold text-ink">Publishing Channels</h2>
            @include('admin._table', ['rows' => $account->publishingChannels, 'columns' => ['name', 'provider', 'brand.name', 'status']])
        </section>
        <section class="rounded-md border border-line bg-white p-4">
            <h2 class="text-lg font-bold text-ink">Recent Domain Events</h2>
            @include('admin._table', ['rows' => $events, 'columns' => ['event_type', 'brand.name', 'actor.name', 'occurred_at', 'processed_at']])
        </section>
        <section class="rounded-md border border-line bg-white p-4">
            <h2 class="text-lg font-bold text-ink">Recommendations</h2>
            @include('admin._table', ['rows' => $recommendations, 'columns' => ['title', 'status', 'created_at']])
        </section>
        <section class="rounded-md border border-line bg-white p-4">
            <h2 class="text-lg font-bold text-ink">Signals</h2>
            @include('admin._table', ['rows' => $signals, 'columns' => ['type', 'status', 'created_at']])
        </section>
    </div>
</x-app.layout>
