<x-app.layout title="Platform Alerts" :show-workspace-header="false">
    @include('admin._nav')

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.12em] text-muted">Platform</p>
            <h1 class="mt-1 text-3xl font-bold text-ink">Alerts</h1>
            <p class="mt-2 max-w-3xl text-sm text-muted">Operational alerts generated from high severity signals, worker health, source health and platform monitoring.</p>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($stats as $label => $value)
            <div class="rounded-md border border-line bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.08em] text-muted">{{ str($label)->headline() }}</p>
                <p class="mt-3 text-3xl font-bold text-ink">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-6 rounded-md border border-line bg-white p-4">
        <form method="GET" action="{{ route('admin.platform.alerts') }}" class="grid gap-3 md:grid-cols-[1fr_1fr_auto]">
            <label class="block">
                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Status</span>
                <select name="status" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ str($status)->headline() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Severity</span>
                <select name="severity" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    <option value="">All severities</option>
                    @foreach ($severities as $severity)
                        <option value="{{ $severity }}" @selected(($filters['severity'] ?? '') === $severity)>{{ str($severity)->headline() }}</option>
                    @endforeach
                </select>
            </label>
            <div class="flex items-end gap-2">
                <button class="rounded-md bg-ink px-4 py-2 text-sm font-semibold text-white">Filter</button>
                <a href="{{ route('admin.platform.alerts') }}" class="rounded-md border border-line px-4 py-2 text-sm font-semibold text-ink">Reset</a>
            </div>
        </form>
    </div>

    <div class="mt-6 space-y-3">
        @forelse ($alerts as $alert)
            <div class="rounded-md border border-line bg-white p-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            @include('admin._status', ['value' => $alert->status])
                            <span class="rounded-full border border-line bg-panel px-2.5 py-1 text-xs font-semibold text-muted">{{ str($alert->severity)->headline() }}</span>
                            @if ($alert->account)
                                <span class="text-xs text-muted">{{ $alert->account->name }}</span>
                            @endif
                            @if ($alert->brand)
                                <span class="text-xs text-muted">{{ $alert->brand->name }}</span>
                            @endif
                        </div>
                        <p class="mt-3 text-sm font-semibold text-ink">{{ $alert->title }}</p>
                        <p class="mt-1 text-sm leading-6 text-muted">{{ $alert->body }}</p>
                        @if ($alert->signal)
                            <a href="{{ route('app.intelligence.show', $alert->signal) }}" class="mt-2 inline-flex text-sm font-semibold text-blue">Open signal</a>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if ($alert->status === 'open')
                            <form method="POST" action="{{ route('admin.platform.alerts.acknowledge', $alert) }}">
                                @csrf
                                <button class="rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink hover:bg-panel">Acknowledge</button>
                            </form>
                        @endif
                        @if ($alert->status !== 'resolved')
                            <form method="POST" action="{{ route('admin.platform.alerts.resolve', $alert) }}">
                                @csrf
                                <button class="rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink hover:bg-panel">Resolve</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-md border border-line bg-white p-6 text-sm text-muted">No alerts found.</div>
        @endforelse
    </div>

    <div class="mt-6">
        {{ $alerts->links() }}
    </div>
</x-app.layout>
