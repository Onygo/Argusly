<x-app.layout title="Platform Webhooks" :show-workspace-header="false">
    @include('admin._nav')

    <div class="mb-6">
        <p class="text-sm font-semibold uppercase tracking-[0.12em] text-muted">Platform</p>
        <h1 class="mt-1 text-3xl font-bold text-ink">Webhooks</h1>
    </div>

    <div class="grid gap-4 xl:grid-cols-[0.9fr_1.4fr]">
        <form method="POST" action="{{ route('admin.platform.webhooks.store') }}" class="rounded-md border border-line bg-white p-4">
            @csrf
            <h2 class="text-lg font-bold text-ink">Create endpoint</h2>
            <div class="mt-4 space-y-3">
                <input name="name" placeholder="Endpoint name" class="w-full rounded-md border border-line px-3 py-2 text-sm" required>
                <input name="url" placeholder="https://example.com/webhooks/argusly" class="w-full rounded-md border border-line px-3 py-2 text-sm" required>
                <select name="account_id" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                    <option value="">Platform-wide</option>
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </select>
                <select name="brand_id" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                    <option value="">All brands</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->id }}">{{ $brand->account?->name }} · {{ $brand->name }}</option>
                    @endforeach
                </select>
                <select name="status" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}">{{ str($status)->headline() }}</option>
                    @endforeach
                </select>
                <input name="signing_secret" placeholder="Signing secret" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <div class="grid gap-2">
                    @foreach ($events as $event => $description)
                        <label class="flex items-start gap-2 rounded-md border border-line p-2 text-sm">
                            <input type="checkbox" name="events[]" value="{{ $event }}" class="mt-1 rounded border-line">
                            <span><span class="font-semibold text-ink">{{ $event }}</span><br><span class="text-muted">{{ $description }}</span></span>
                        </label>
                    @endforeach
                </div>
                <button class="rounded-md bg-ink px-4 py-2 text-sm font-semibold text-white">Create endpoint</button>
            </div>
        </form>

        <div class="space-y-3">
            @forelse ($endpoints as $endpoint)
                <form method="POST" action="{{ route('admin.platform.webhooks.update', $endpoint) }}" class="rounded-md border border-line bg-white p-4">
                    @csrf
                    @method('PATCH')
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-bold text-ink">{{ $endpoint->name }}</p>
                            <p class="text-sm text-muted">{{ $endpoint->url }}</p>
                            <p class="mt-1 text-xs text-muted">{{ $endpoint->account?->name ?? 'Platform-wide' }} · {{ $endpoint->brand?->name ?? 'All brands' }} · {{ $endpoint->deliveries_count }} deliveries</p>
                        </div>
                        @include('admin._status', ['value' => $endpoint->status])
                    </div>
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        <input name="name" value="{{ $endpoint->name }}" class="rounded-md border border-line px-3 py-2 text-sm" required>
                        <input name="url" value="{{ $endpoint->url }}" class="rounded-md border border-line px-3 py-2 text-sm" required>
                        <select name="account_id" class="rounded-md border border-line px-3 py-2 text-sm">
                            <option value="">Platform-wide</option>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}" @selected($endpoint->account_id === $account->id)>{{ $account->name }}</option>
                            @endforeach
                        </select>
                        <select name="brand_id" class="rounded-md border border-line px-3 py-2 text-sm">
                            <option value="">All brands</option>
                            @foreach ($brands as $brand)
                                <option value="{{ $brand->id }}" @selected($endpoint->brand_id === $brand->id)>{{ $brand->account?->name }} · {{ $brand->name }}</option>
                            @endforeach
                        </select>
                        <select name="status" class="rounded-md border border-line px-3 py-2 text-sm">
                            @foreach ($statuses as $status)
                                <option value="{{ $status }}" @selected($endpoint->status === $status)>{{ str($status)->headline() }}</option>
                            @endforeach
                        </select>
                        <input name="signing_secret" placeholder="Leave blank to clear" class="rounded-md border border-line px-3 py-2 text-sm">
                    </div>
                    <div class="mt-3 grid gap-2 md:grid-cols-2">
                        @foreach ($events as $event => $description)
                            <label class="flex items-start gap-2 text-sm">
                                <input type="checkbox" name="events[]" value="{{ $event }}" class="mt-1 rounded border-line" @checked(in_array($event, $endpoint->events ?? [], true))>
                                <span>{{ $event }}</span>
                            </label>
                        @endforeach
                    </div>
                    <button class="mt-3 rounded-md border border-line px-4 py-2 text-sm font-semibold text-ink hover:bg-panel">Save</button>
                </form>
            @empty
                <p class="rounded-md border border-line bg-white p-4 text-sm text-muted">No webhook endpoints yet.</p>
            @endforelse

            {{ $endpoints->links() }}
        </div>
    </div>

    <div class="mt-6 rounded-md border border-line bg-white p-4">
        <h2 class="text-lg font-bold text-ink">Recent Deliveries</h2>
        <div class="mt-4 space-y-3">
            @forelse ($deliveries as $delivery)
                <div class="rounded-md border border-line p-3">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-ink">{{ $delivery->event }} · {{ $delivery->endpoint?->name }}</p>
                            <p class="text-xs text-muted">{{ $delivery->created_at?->format('Y-m-d H:i') }} · attempts {{ $delivery->attempts }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            @include('admin._status', ['value' => $delivery->status])
                            @if (in_array($delivery->status, ['failed', 'cancelled'], true))
                                <form method="POST" action="{{ route('admin.platform.webhooks.deliveries.retry', $delivery) }}">
                                    @csrf
                                    <button class="rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink hover:bg-panel">Retry</button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-muted">No deliveries recorded yet.</p>
            @endforelse
        </div>
    </div>
</x-app.layout>
