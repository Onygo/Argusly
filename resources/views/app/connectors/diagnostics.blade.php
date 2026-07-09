@extends('layouts.app', ['title' => $account->account_name.' diagnostics'])

@section('pageHeader')
    <x-page-header :title="$account->account_name.' diagnostics'" />
@endsection

@section('pageDescription')
    <x-page-description>{{ $account->provider?->name ?? \Illuminate\Support\Str::headline($account->provider_key) }} connector diagnostics for {{ $workspace->display_name }}.</x-page-description>
@endsection

@section('metricSection')
    <x-metric-section>
        <x-metric-card label="OAuth" :value="\Illuminate\Support\Str::headline($diagnostics['oauth_status'])" />
        <x-metric-card label="Token" :value="$diagnostics['token_valid'] ? 'Valid' : 'Invalid'" />
        <x-metric-card label="Health score" :value="$diagnostics['health_score'] !== null ? $diagnostics['health_score'].'/100' : 'Pending'" />
        <x-metric-card label="Raw records" :value="$diagnostics['raw_records']" />
    </x-metric-section>
@endsection

@section('content')
    <div class="space-y-6">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('app.connectors.show', $account) }}" class="pl-btn-secondary">
                <i data-lucide="arrow-left" class="h-4 w-4"></i>
                <span>Back</span>
            </a>
            <form method="POST" action="{{ route('app.connectors.health-check', $account) }}">
                @csrf
                <button type="submit" class="pl-btn-secondary">
                    <i data-lucide="activity" class="h-4 w-4"></i>
                    <span>Run Health</span>
                </button>
            </form>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">OAuth and token</h2>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-textFaint">OAuth status</dt>
                        <dd class="mt-1 font-medium text-textPrimary">{{ \Illuminate\Support\Str::headline($diagnostics['oauth_status']) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textFaint">Token validity</dt>
                        <dd class="mt-1 font-medium text-textPrimary">{{ $diagnostics['token_valid'] ? 'Valid' : 'Needs reconnect' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textFaint">Expires</dt>
                        <dd class="mt-1 font-medium text-textPrimary">{{ $diagnostics['token_expires_at']?->diffForHumans() ?? 'Not provided' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textFaint">Refresh token</dt>
                        <dd class="mt-1 font-medium text-textPrimary">{{ $diagnostics['has_refresh_token'] ? 'Stored' : 'Missing' }}</dd>
                    </div>
                </dl>
                <div class="mt-4 flex flex-wrap gap-2">
                    @forelse ($account->scopes as $scope)
                        <span class="inline-flex rounded-full border border-border bg-background px-3 py-1 text-xs text-textSecondary">
                            {{ $scope->scope }}
                        </span>
                    @empty
                        <span class="text-sm text-textSecondary">No scopes recorded.</span>
                    @endforelse
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Provider diagnostics</h2>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-textFaint">Rate limit</dt>
                        <dd class="mt-1 font-medium text-textPrimary">{{ data_get($diagnostics, 'rate_limit.remaining', 'Unknown') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textFaint">Last API call</dt>
                        <dd class="mt-1 font-medium text-textPrimary">{{ $diagnostics['last_api_call_at']?->diffForHumans() ?? 'Never' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textFaint">Last sync duration</dt>
                        <dd class="mt-1 font-medium text-textPrimary">{{ $diagnostics['last_sync_duration_ms'] !== null ? round($diagnostics['last_sync_duration_ms'] / 1000, 2).'s' : 'Pending' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textFaint">Canonical observations</dt>
                        <dd class="mt-1 font-medium text-textPrimary">{{ $diagnostics['observations'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textFaint">Async report jobs</dt>
                        <dd class="mt-1 font-medium text-textPrimary">{{ $diagnostics['async_report_jobs'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textFaint">Backfill ranges</dt>
                        <dd class="mt-1 font-medium text-textPrimary">{{ $diagnostics['backfill_ranges'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textFaint">Webhook status</dt>
                        <dd class="mt-1 font-medium text-textPrimary">{{ \Illuminate\Support\Str::headline($diagnostics['webhook_status'] ?? 'pending') }}</dd>
                    </div>
                </dl>
                @if ($diagnostics['last_error'])
                    <div class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                        {{ $diagnostics['last_error'] }}
                    </div>
                @endif
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface">
            <div class="border-b border-border px-5 py-4">
                <h2 class="text-base font-semibold text-textPrimary">Quota budgets</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-border text-sm">
                    <thead class="bg-surfaceSubtle text-left text-xs uppercase tracking-wide text-textFaint">
                        <tr>
                            <th class="px-5 py-3 font-medium">Scope</th>
                            <th class="px-5 py-3 font-medium">Type</th>
                            <th class="px-5 py-3 font-medium">Used</th>
                            <th class="px-5 py-3 font-medium">Remaining</th>
                            <th class="px-5 py-3 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ((array) data_get($diagnostics, 'quota.budgets', []) as $budget)
                            <tr>
                                <td class="px-5 py-4 text-textSecondary">{{ \Illuminate\Support\Str::headline($budget['scope']) }}</td>
                                <td class="px-5 py-4 text-textSecondary">{{ \Illuminate\Support\Str::headline($budget['type']) }}</td>
                                <td class="px-5 py-4 text-textSecondary">{{ $budget['used'] }} / {{ $budget['limit'] }}</td>
                                <td class="px-5 py-4 text-textSecondary">{{ $budget['remaining'] }}</td>
                                <td class="px-5 py-4">@include('app.connectors.partials.status-badge', ['status' => $budget['status']])</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-6 text-textSecondary">No quota budgets configured.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface">
            <div class="border-b border-border px-5 py-4">
                <h2 class="text-base font-semibold text-textPrimary">Datasets</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-border text-sm">
                    <thead class="bg-surfaceSubtle text-left text-xs uppercase tracking-wide text-textFaint">
                        <tr>
                            <th class="px-5 py-3 font-medium">Dataset</th>
                            <th class="px-5 py-3 font-medium">Status</th>
                            <th class="px-5 py-3 font-medium">Frequency</th>
                            <th class="px-5 py-3 font-medium">Last sync</th>
                            <th class="px-5 py-3 font-medium">Next sync</th>
                            <th class="px-5 py-3 font-medium">Health</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($account->datasets as $dataset)
                            <tr>
                                <td class="px-5 py-4">
                                    <p class="font-medium text-textPrimary">{{ $dataset->display_name }}</p>
                                    <p class="mt-1 text-xs text-textSecondary">{{ $dataset->dataset_key }}</p>
                                </td>
                                <td class="px-5 py-4">@include('app.connectors.partials.status-badge', ['status' => $dataset->status])</td>
                                <td class="px-5 py-4 text-textSecondary">{{ \Illuminate\Support\Str::headline($dataset->sync_frequency ?? 'manual') }}</td>
                                <td class="px-5 py-4 text-textSecondary">{{ $dataset->last_sync_at?->diffForHumans() ?? 'Never' }}</td>
                                <td class="px-5 py-4 text-textSecondary">{{ $dataset->next_sync_at?->diffForHumans() ?? 'Manual' }}</td>
                                <td class="px-5 py-4">@include('app.connectors.partials.status-badge', ['status' => $dataset->health_status ?? 'unknown'])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface">
            <div class="border-b border-border px-5 py-4">
                <h2 class="text-base font-semibold text-textPrimary">Recent events</h2>
            </div>
            <div class="divide-y divide-border">
                @forelse ($account->healthEvents as $event)
                    <div class="px-5 py-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-medium text-textPrimary">{{ $event->message }}</p>
                                <p class="mt-1 text-xs text-textSecondary">{{ $event->event_type }} &middot; {{ $event->occurred_at?->diffForHumans() }}</p>
                            </div>
                            @include('app.connectors.partials.status-badge', ['status' => $event->severity])
                        </div>
                    </div>
                @empty
                    <div class="p-6 text-sm text-textSecondary">No health events recorded.</div>
                @endforelse
            </div>
        </div>
    </div>
@endsection
