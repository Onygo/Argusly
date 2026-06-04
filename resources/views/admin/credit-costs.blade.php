<x-app.layout title="Credit Cost Catalog" :show-workspace-header="false">
    @include('admin._nav')

    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-ink">Credit Cost Catalog</h1>
            <p class="mt-1 text-sm text-muted">Central source of truth for feature credit pricing, overrides and usage.</p>
        </div>
        <form method="GET" action="{{ route('admin.credit-costs') }}" class="flex flex-wrap gap-2">
            <input name="q" value="{{ request('q') }}" placeholder="Search code or name" class="rounded-md border border-line px-3 py-2 text-sm">
            <select name="category" class="rounded-md border border-line px-3 py-2 text-sm">
                <option value="">All categories</option>
                @foreach ($categories as $category)
                    <option value="{{ $category }}" @selected(request('category') === $category)>{{ str($category)->headline() }}</option>
                @endforeach
            </select>
            <select name="status" class="rounded-md border border-line px-3 py-2 text-sm">
                <option value="">All statuses</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ str($status)->headline() }}</option>
                @endforeach
            </select>
            <button class="rounded-md bg-blue px-4 py-2 text-sm font-semibold text-white">Search</button>
        </form>
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-[1fr_360px]">
        <section class="overflow-hidden rounded-md border border-line bg-white">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-line text-sm">
                    <thead class="bg-panel text-left text-xs font-semibold uppercase tracking-[0.1em] text-muted">
                        <tr>
                            <th class="px-4 py-3">Code</th>
                            <th class="px-4 py-3">Category</th>
                            <th class="px-4 py-3">Cost</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Overrides</th>
                            <th class="px-4 py-3">Usage</th>
                            <th class="px-4 py-3">Last Used</th>
                            <th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($catalog as $item)
                            <tr>
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-ink">{{ $item->code }}</p>
                                    <p class="text-xs text-muted">{{ $item->name }}</p>
                                </td>
                                <td class="px-4 py-3">{{ str($item->category)->headline() }}</td>
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-ink">{{ $item->default_cost }}</p>
                                    <p class="text-xs text-muted">{{ $item->cost_type }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $item->status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-muted' }}">{{ $item->status }}</span>
                                </td>
                                <td class="px-4 py-3">{{ $item->overrides_count }}</td>
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-ink">{{ (int) $item->usage_executions_sum }} runs</p>
                                    <p class="text-xs text-muted">{{ (int) $item->usage_credits_sum }} credits</p>
                                </td>
                                <td class="px-4 py-3 text-muted">{{ $item->last_used_at ? \Illuminate\Support\Carbon::parse($item->last_used_at)->diffForHumans() : 'Never' }}</td>
                                <td class="px-4 py-3">
                                    <details class="rounded-md border border-line bg-panel p-3">
                                        <summary class="cursor-pointer text-sm font-semibold text-ink">Edit</summary>
                                        <form method="POST" action="{{ route('admin.credit-costs.update', $item) }}" class="mt-3 grid gap-2">
                                            @csrf
                                            @method('PUT')
                                            <input name="name" value="{{ $item->name }}" class="rounded-md border border-line px-3 py-2 text-sm">
                                            <textarea name="description" rows="2" class="rounded-md border border-line px-3 py-2 text-sm">{{ $item->description }}</textarea>
                                            <select name="category" class="rounded-md border border-line px-3 py-2 text-sm">
                                                @foreach ($categories as $category)
                                                    <option value="{{ $category }}" @selected($item->category === $category)>{{ str($category)->headline() }}</option>
                                                @endforeach
                                            </select>
                                            <input name="default_cost" value="{{ $item->default_cost }}" type="number" min="0" class="rounded-md border border-line px-3 py-2 text-sm">
                                            <div class="grid grid-cols-2 gap-2">
                                                <input name="minimum_cost" value="{{ $item->minimum_cost }}" type="number" min="0" placeholder="Min" class="rounded-md border border-line px-3 py-2 text-sm">
                                                <input name="maximum_cost" value="{{ $item->maximum_cost }}" type="number" min="0" placeholder="Max" class="rounded-md border border-line px-3 py-2 text-sm">
                                            </div>
                                            <select name="cost_type" class="rounded-md border border-line px-3 py-2 text-sm">
                                                @foreach ($costTypes as $type)
                                                    <option value="{{ $type }}" @selected($item->cost_type === $type)>{{ str($type)->headline() }}</option>
                                                @endforeach
                                            </select>
                                            <select name="status" class="rounded-md border border-line px-3 py-2 text-sm">
                                                @foreach ($statuses as $status)
                                                    <option value="{{ $status }}" @selected($item->status === $status)>{{ str($status)->headline() }}</option>
                                                @endforeach
                                            </select>
                                            <button class="rounded-md bg-ink px-3 py-2 text-sm font-semibold text-white">Save</button>
                                        </form>
                                    </details>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-line p-4">{{ $catalog->links() }}</div>
        </section>

        <div class="space-y-5">
            <form method="POST" action="{{ route('admin.credit-costs.overrides.store') }}" class="rounded-md border border-line bg-white p-4">
                @csrf
                <h2 class="text-lg font-bold text-ink">Create Override</h2>
                <div class="mt-4 space-y-3">
                    <select name="credit_cost_catalog_id" required class="w-full rounded-md border border-line px-3 py-2 text-sm">
                        @foreach ($catalog as $item)
                            <option value="{{ $item->id }}">{{ $item->code }} ({{ $item->default_cost }})</option>
                        @endforeach
                    </select>
                    <select name="account_id" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                        <option value="">Account optional</option>
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </select>
                    <select name="brand_id" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                        <option value="">Brand optional</option>
                        @foreach ($brands as $brand)
                            <option value="{{ $brand->id }}">{{ $brand->name }} · {{ $brand->account?->name }}</option>
                        @endforeach
                    </select>
                    <input name="override_cost" type="number" min="0" required placeholder="Override cost" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                    <select name="status" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    <button class="w-full rounded-md bg-blue px-4 py-2 text-sm font-semibold text-white">Save override</button>
                </div>
            </form>

            <section class="rounded-md border border-line bg-white p-4">
                <h2 class="text-lg font-bold text-ink">Recent Overrides</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($overrides as $override)
                        <div class="rounded-md border border-line bg-panel p-3">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-semibold text-ink">{{ $override->catalog?->code }}</p>
                                    <p class="text-xs text-muted">{{ $override->account?->name ?? 'Global' }} @if($override->brand) · {{ $override->brand->name }} @endif</p>
                                </div>
                                <span class="rounded-full bg-blue/10 px-2.5 py-1 text-xs font-semibold text-blue">{{ $override->override_cost }}</span>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-muted">No overrides yet.</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
</x-app.layout>
