<x-app.layout title="Admin Brands" :show-workspace-header="false">
    @include('admin._nav')
    <h1 class="text-2xl font-bold text-ink">Brands</h1>
    <div class="mt-4 grid gap-4 xl:grid-cols-[1fr_360px]">
        <div>
            <form method="GET" class="mb-4 flex flex-wrap gap-2">
                <input name="q" value="{{ request('q') }}" placeholder="Search brands" class="rounded-md border border-line px-3 py-2 text-sm">
                <select name="account_id" class="rounded-md border border-line px-3 py-2 text-sm">
                    <option value="">Any account</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}" @selected((int) request('account_id') === $account->id)>{{ $account->name }}</option>
                    @endforeach
                </select>
                <button class="rounded-md bg-ink px-4 py-2 text-sm font-semibold text-white">Filter</button>
            </form>
            @include('admin._table', ['rows' => $brands, 'columns' => ['name', 'account.name', 'status', 'domain', 'publishing_channels_count', 'products_count', 'services_count', 'narratives_count']])
        </div>
        <form method="POST" action="{{ route('admin.brands.store') }}" class="rounded-md border border-line bg-white p-4">
            @csrf
            <h2 class="text-lg font-bold text-ink">Create Brand</h2>
            <div class="mt-4 space-y-3">
                <select name="account_id" required class="w-full rounded-md border border-line px-3 py-2 text-sm">
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </select>
                <input name="name" placeholder="Name" required class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <input name="domain" placeholder="Domain" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <select name="status" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                    @foreach (['active', 'pending', 'paused', 'archived'] as $status)
                        <option value="{{ $status }}">{{ str($status)->headline() }}</option>
                    @endforeach
                </select>
                <button class="w-full rounded-md bg-blue px-4 py-2 text-sm font-semibold text-white">Create brand</button>
            </div>
        </form>
    </div>
</x-app.layout>
