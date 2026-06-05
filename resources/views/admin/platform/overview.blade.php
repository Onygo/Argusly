<x-app.layout title="Platform Operations" :show-workspace-header="false">
    @include('admin._nav')

    <div class="mb-6">
        <p class="text-sm font-semibold uppercase tracking-[0.12em] text-muted">Platform</p>
        <h1 class="mt-1 text-3xl font-bold text-ink">Operations</h1>
        <p class="mt-2 max-w-3xl text-sm text-muted">Health, queues, webhooks, feature gates and AI runtime signals for the Argusly platform.</p>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($health as $item)
            <div class="rounded-md border border-line bg-white p-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-bold text-ink">{{ $item['label'] }}</p>
                        <p class="mt-2 text-sm text-muted">{{ $item['detail'] }}</p>
                    </div>
                    @include('admin._status', ['value' => $item['status']])
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-6">
        @foreach ($metrics as $label => $value)
            <div class="rounded-md border border-line bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.08em] text-muted">{{ str($label)->headline() }}</p>
                <p class="mt-3 text-2xl font-bold text-ink">{{ is_float($value) ? number_format($value, 2) : $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-6 grid gap-4 xl:grid-cols-2">
        <div class="rounded-md border border-line bg-white p-4">
            <div class="flex items-center justify-between gap-4">
                <h2 class="text-lg font-bold text-ink">Queue Summary</h2>
                <a href="{{ route('admin.platform.queues') }}" class="rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink hover:bg-panel">Open queues</a>
            </div>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div class="rounded-md bg-panel p-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.08em] text-muted">Pending</p>
                    <p class="mt-2 text-2xl font-bold text-ink">{{ $queue['pending_count'] }}</p>
                </div>
                <div class="rounded-md bg-panel p-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.08em] text-muted">Failed</p>
                    <p class="mt-2 text-2xl font-bold text-ink">{{ $queue['failed_count'] }}</p>
                </div>
            </div>
        </div>

        <div class="rounded-md border border-line bg-white p-4">
            <h2 class="text-lg font-bold text-ink">Platform Actions</h2>
            <div class="mt-4 grid gap-2 sm:grid-cols-2">
                <a href="{{ route('admin.platform.feature-flags') }}" class="rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink hover:bg-panel">Feature flags</a>
                <a href="{{ route('admin.platform.webhooks') }}" class="rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink hover:bg-panel">Webhooks</a>
                <a href="{{ route('admin.platform.alerts') }}" class="rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink hover:bg-panel">Alerts</a>
                <a href="{{ route('admin.ai-runtime.monitor') }}" class="rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink hover:bg-panel">AI monitor</a>
                <a href="{{ route('admin.developer-tools.show', ['domain-events']) }}" class="rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink hover:bg-panel">Domain events</a>
            </div>
        </div>
    </div>

    <div class="mt-6 grid gap-4 xl:grid-cols-3">
        <div class="rounded-md border border-line bg-white p-4">
            <h2 class="text-lg font-bold text-ink">Alert Summary</h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach ($alertStats as $label => $value)
                    <div class="rounded-md bg-panel p-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.08em] text-muted">{{ str($label)->headline() }}</p>
                        <p class="mt-2 text-2xl font-bold text-ink">{{ $value }}</p>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="rounded-md border border-line bg-white p-4">
            <h2 class="text-lg font-bold text-ink">Source Health</h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach (['healthy', 'warning', 'critical', 'stale'] as $key)
                    <div class="rounded-md bg-panel p-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.08em] text-muted">{{ str($key)->headline() }}</p>
                        <p class="mt-2 text-2xl font-bold text-ink">{{ $sourceHealth[$key] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="rounded-md border border-line bg-white p-4">
            <h2 class="text-lg font-bold text-ink">Scheduler</h2>
            <div class="mt-4">
                @include('admin._status', ['value' => $scheduler['status']])
                <p class="mt-3 text-sm text-muted">Last heartbeat: {{ $scheduler['last_run_at'] ?? 'No heartbeat' }}</p>
                <p class="mt-1 text-sm text-muted">{{ $scheduler['due_visibility_schedules'] }} due visibility schedules.</p>
            </div>
        </div>
    </div>
</x-app.layout>
