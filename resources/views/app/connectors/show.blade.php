@extends('layouts.app', ['title' => $account->account_name])

@section('pageHeader')
    <x-page-header :title="$account->account_name" />
@endsection

@section('pageDescription')
    <x-page-description>{{ $account->provider?->name ?? \Illuminate\Support\Str::headline($account->provider_key) }} source-data connector account.</x-page-description>
@endsection

@section('metricSection')
    <x-metric-section>
        <x-metric-card label="Status" :value="\Illuminate\Support\Str::headline($account->status)" />
        <x-metric-card label="Datasets" :value="$account->datasets->count()" />
        <x-metric-card label="Sync runs" :value="$account->syncRuns->count()" />
    </x-metric-section>
@endsection

@section('content')
    <div class="space-y-6">
        <div class="rounded-lg border border-border bg-surface p-5">
            <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        @include('app.connectors.partials.status-badge', ['status' => $account->status])
                        @if ($account->health_status)
                            @include('app.connectors.partials.status-badge', ['status' => $account->health_status, 'label' => \Illuminate\Support\Str::headline($account->health_status)])
                        @endif
                    </div>
                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <dt class="text-xs text-textFaint">Provider</dt>
                            <dd class="mt-1 font-medium text-textPrimary">{{ $account->provider?->name ?? \Illuminate\Support\Str::headline($account->provider_key) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-textFaint">Workspace</dt>
                            <dd class="mt-1 font-medium text-textPrimary">{{ $workspace->display_name }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-textFaint">Site</dt>
                            <dd class="mt-1 font-medium text-textPrimary">{{ $account->clientSite?->name ?? 'Workspace-wide' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-textFaint">Connected</dt>
                            <dd class="mt-1 font-medium text-textPrimary">{{ $account->connected_at?->toFormattedDateString() ?? 'Not connected' }}</dd>
                        </div>
                    </dl>
                </div>
                <button class="pl-btn-secondary opacity-60" disabled>
                    <i data-lucide="plug-zap" class="h-4 w-4"></i>
                    <span>Connect coming soon</span>
                </button>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-lg border border-border bg-surface">
                <div class="border-b border-border px-5 py-4">
                    <h2 class="text-base font-semibold text-textPrimary">Datasets</h2>
                    <p class="mt-1 text-sm text-textSecondary">Dataset discovery and mapping will be populated by provider adapters.</p>
                </div>
                @if ($account->datasets->isEmpty())
                    <div class="p-6 text-sm text-textSecondary">No datasets discovered yet.</div>
                @else
                    <div class="divide-y divide-border">
                        @foreach ($account->datasets as $dataset)
                            <div class="px-5 py-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="font-medium text-textPrimary">{{ $dataset->display_name }}</p>
                                        <p class="mt-1 text-xs text-textSecondary">{{ $dataset->dataset_key }} &middot; {{ $dataset->dataset_type }}</p>
                                    </div>
                                    @include('app.connectors.partials.status-badge', ['status' => $dataset->status])
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="rounded-lg border border-border bg-surface">
                <div class="border-b border-border px-5 py-4">
                    <h2 class="text-base font-semibold text-textPrimary">Health events</h2>
                    <p class="mt-1 text-sm text-textSecondary">Provider and dataset health history for later sync operations.</p>
                </div>
                @if ($account->healthEvents->isEmpty())
                    <div class="p-6 text-sm text-textSecondary">No health events recorded.</div>
                @else
                    <div class="divide-y divide-border">
                        @foreach ($account->healthEvents as $event)
                            <div class="px-5 py-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="font-medium text-textPrimary">{{ $event->message }}</p>
                                        <p class="mt-1 text-xs text-textSecondary">{{ $event->event_type }} &middot; {{ $event->occurred_at?->diffForHumans() }}</p>
                                    </div>
                                    @include('app.connectors.partials.status-badge', ['status' => $event->severity])
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface">
            <div class="border-b border-border px-5 py-4">
                <h2 class="text-base font-semibold text-textPrimary">Sync run history</h2>
                <p class="mt-1 text-sm text-textSecondary">Manual, scheduled, backfill, and webhook runs will appear here once adapters are enabled.</p>
            </div>
            @if ($account->syncRuns->isEmpty())
                <div class="p-6 text-sm text-textSecondary">No sync runs logged yet.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-border text-sm">
                        <thead class="bg-surfaceSubtle text-left text-xs uppercase tracking-wide text-textFaint">
                            <tr>
                                <th class="px-5 py-3 font-medium">Dataset</th>
                                <th class="px-5 py-3 font-medium">Type</th>
                                <th class="px-5 py-3 font-medium">Status</th>
                                <th class="px-5 py-3 font-medium">Started</th>
                                <th class="px-5 py-3 font-medium">Finished</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($account->syncRuns as $run)
                                <tr>
                                    <td class="px-5 py-4 text-textSecondary">{{ $run->dataset_key ?: 'Account' }}</td>
                                    <td class="px-5 py-4 text-textSecondary">{{ \Illuminate\Support\Str::headline($run->run_type) }}</td>
                                    <td class="px-5 py-4">@include('app.connectors.partials.status-badge', ['status' => $run->status])</td>
                                    <td class="px-5 py-4 text-textSecondary">{{ $run->started_at?->diffForHumans() ?? 'Not started' }}</td>
                                    <td class="px-5 py-4 text-textSecondary">{{ $run->finished_at?->diffForHumans() ?? 'Running' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
