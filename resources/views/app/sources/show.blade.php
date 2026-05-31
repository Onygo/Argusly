<x-app.layout title="{{ $source->name }} | Argusly">
    <div class="mx-auto max-w-7xl">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Source detail</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ $source->name }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">{{ str($source->provider)->headline() }} monitored {{ str($source->type)->headline() }} stream or corpus.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button href="{{ route('app.sources.index') }}" variant="secondary">Back to sources</x-ui.button>
                <form method="POST" action="{{ route('app.sources.syncs.plan', $source) }}">
                    @csrf
                    <x-ui.button type="submit">Plan sync record</x-ui.button>
                </form>
            </div>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-lg border border-line bg-white p-4 text-sm font-medium text-ink">{{ session('status') }}</div>
        @endif

        <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-dashboard.info-card label="Type" value="{{ str($source->type)->headline() }}" />
            <x-dashboard.info-card label="Provider" value="{{ str($source->provider)->headline() }}" />
            <x-dashboard.info-card label="Status" value="{{ str($source->status)->headline() }}" />
            <x-dashboard.info-card label="Scope" value="{{ $source->brand?->name ?? ($source->account_id ? 'Account' : 'Global') }}" />
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[0.8fr_1.2fr]">
            <x-dashboard.section title="Credentials">
                @if ($source->connections->isEmpty())
                    <x-dashboard.empty-state title="No credentials" message="This source has no credential link yet." />
                @else
                    <div class="space-y-3">
                        @foreach ($source->connections as $connection)
                            <div class="rounded-lg border border-line bg-panel p-4">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-ink">{{ $connection->integrationConnection?->name ?? 'Manual source' }}</p>
                                        <p class="mt-1 text-xs text-muted">{{ $connection->integrationConnection?->integration?->name ?? 'No integration provider' }}</p>
                                    </div>
                                    <x-ui.badge>{{ str($connection->status)->headline() }}</x-ui.badge>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-dashboard.section>

            <x-dashboard.section title="Sync history" description="Records are architecture placeholders; no sync worker runs yet.">
                @if ($syncs->isEmpty())
                    <x-dashboard.empty-state title="No sync records" message="Create a planned sync record to reserve the future sync history lane." />
                @else
                    <div class="space-y-3">
                        @foreach ($syncs as $sync)
                            <div class="rounded-lg border border-line bg-panel p-4">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-ink">{{ str($sync->status)->headline() }}</p>
                                        <p class="mt-1 text-xs text-muted">{{ $sync->started_at?->format('M j, Y H:i') ?? 'Not started' }} · {{ $sync->records_found ?? 0 }} records</p>
                                    </div>
                                    <time class="shrink-0 text-xs text-muted" datetime="{{ $sync->created_at?->toIso8601String() }}">{{ $sync->created_at?->diffForHumans() }}</time>
                                </div>
                                @if ($sync->error)
                                    <p class="mt-3 text-xs text-red-700">{{ $sync->error }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-5">{{ $syncs->links() }}</div>
                @endif
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
