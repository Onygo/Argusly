<x-app.layout title="Admin Overview" :show-workspace-header="false">
    @include('admin._nav')

    <div class="mb-6 flex flex-col gap-2">
        <p class="text-sm font-semibold uppercase tracking-[0.12em] text-muted">Administration</p>
        <h1 class="text-3xl font-bold text-ink">Admin Control Center</h1>
        <p class="max-w-3xl text-sm text-muted">Create, inspect, configure and troubleshoot customers from one platform administration area.</p>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($metrics as $label => $value)
            <div class="rounded-md border border-line bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.08em] text-muted">{{ str($label)->headline() }}</p>
                <p class="mt-3 text-3xl font-bold text-ink">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-6 grid gap-4 xl:grid-cols-[1fr_1.2fr]">
        <div class="rounded-md border border-line bg-white p-4">
            <h2 class="text-lg font-bold text-ink">Quick Actions</h2>
            <div class="mt-4 grid gap-2 sm:grid-cols-2">
                @foreach ([
                    ['Create account', 'admin.accounts'],
                    ['Create brand', 'admin.brands'],
                    ['Invite user', 'admin.users'],
                    ['Review pilot signup', 'admin.pilot-signups'],
                    ['Assign credits', 'admin.credits'],
                    ['Enable module', 'admin.modules'],
                    ['Inspect failed jobs', 'admin.jobs'],
                ] as [$label, $route])
                    <a href="{{ route($route) }}" class="rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink transition hover:bg-panel">{{ $label }}</a>
                @endforeach
            </div>
        </div>

        <div class="rounded-md border border-line bg-white p-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-bold text-ink">Pilot Requests</h2>
                    <p class="mt-1 text-sm text-muted">Pending requests that need review, follow-up, or activation.</p>
                </div>
                <a href="{{ route('admin.pilot-signups') }}" class="rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink transition hover:bg-panel">Open queue</a>
            </div>

            <div class="mt-4 space-y-3">
                @forelse ($pendingPilotSignups as $signup)
                    <div class="rounded-md border border-amber-200 bg-amber-50/60 p-3">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold text-ink">{{ $signup->company }} · {{ $signup->name }}</p>
                                <p class="text-sm text-muted">{{ $signup->email }} @if($signup->website) · {{ $signup->website }} @endif</p>
                            </div>
                            @include('admin._status', ['value' => $signup->status])
                        </div>
                    </div>
                @empty
                    <p class="rounded-md border border-line p-4 text-sm text-muted">No pending pilot requests.</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-md border border-line bg-white p-4">
            <h2 class="text-lg font-bold text-ink">Recent Admin Activity</h2>
            <div class="mt-4 space-y-3">
                @forelse ($recentActivity as $activity)
                    <div class="rounded-md border border-line p-3">
                        <p class="text-sm font-semibold text-ink">{{ $activity->event }}</p>
                        <p class="text-sm text-muted">{{ $activity->description }}</p>
                        <p class="mt-1 text-xs text-muted">{{ $activity->created_at?->format('Y-m-d H:i') }} · {{ $activity->user?->name ?? 'System' }}</p>
                    </div>
                @empty
                    <p class="rounded-md border border-line p-4 text-sm text-muted">No admin activity has been recorded yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-app.layout>
