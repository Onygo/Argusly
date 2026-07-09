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
        <x-metric-card label="Health" :value="\Illuminate\Support\Str::headline($account->health_status ?? 'unknown')" />
        <x-metric-card label="Datasets" :value="$account->datasets->count()" />
        <x-metric-card label="Sync runs" :value="$account->syncRuns->count()" />
        <x-metric-card label="Raw records" :value="$diagnostics['raw_records']" />
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
                        <div>
                            <dt class="text-xs text-textFaint">Last sync</dt>
                            <dd class="mt-1 font-medium text-textPrimary">{{ $account->last_synced_at?->diffForHumans() ?? 'Never' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-textFaint">Next sync</dt>
                            <dd class="mt-1 font-medium text-textPrimary">{{ $account->datasets->pluck('next_sync_at')->filter()->sort()->first()?->diffForHumans() ?? 'Manual' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-textFaint">Health score</dt>
                            <dd class="mt-1 font-medium text-textPrimary">{{ $account->health_score !== null ? $account->health_score.'/100' : 'Pending' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-textFaint">Token</dt>
                            <dd class="mt-1 font-medium text-textPrimary">{{ $diagnostics['token_valid'] ? 'Valid' : 'Needs attention' }}</dd>
                        </div>
                    </dl>
                    @if ($account->last_error)
                        <div class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                            {{ $account->last_error }}
                        </div>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-2 md:justify-end">
                    <form method="POST" action="{{ route('app.connectors.sync', $account) }}">
                        @csrf
                        <button type="submit" class="pl-btn-primary">
                            <i data-lucide="refresh-cw" class="h-4 w-4"></i>
                            <span>Manual Sync</span>
                        </button>
                    </form>
                    <form method="POST" action="{{ route('app.connectors.discover', $account) }}">
                        @csrf
                        <button type="submit" class="pl-btn-secondary">
                            <i data-lucide="database-zap" class="h-4 w-4"></i>
                            <span>Discover</span>
                        </button>
                    </form>
                    <form method="POST" action="{{ route('app.connectors.health-check', $account) }}">
                        @csrf
                        <button type="submit" class="pl-btn-secondary">
                            <i data-lucide="activity" class="h-4 w-4"></i>
                            <span>Health</span>
                        </button>
                    </form>
                    <form method="POST" action="{{ route('app.connectors.reconnect', $account) }}">
                        @csrf
                        <button type="submit" class="pl-btn-secondary">
                            <i data-lucide="plug-zap" class="h-4 w-4"></i>
                            <span>Reconnect</span>
                        </button>
                    </form>
                    <a href="{{ route('app.connectors.diagnostics', $account) }}" class="pl-btn-secondary">
                        <i data-lucide="stethoscope" class="h-4 w-4"></i>
                        <span>Diagnostics</span>
                    </a>
                    <a href="{{ route('app.connectors.field-mapping', $account) }}" class="pl-btn-secondary">
                        <i data-lucide="list-checks" class="h-4 w-4"></i>
                        <span>Field Mapping</span>
                    </a>
                    <form method="POST" action="{{ route('app.connectors.disconnect', $account) }}">
                        @csrf
                        <button type="submit" class="pl-btn-danger">
                            <i data-lucide="unplug" class="h-4 w-4"></i>
                            <span>Disconnect</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Capabilities</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div>
                        <dt class="text-xs text-textFaint">Sync modes</dt>
                        <dd class="mt-1 text-textPrimary">{{ collect($manifest['sync_modes'] ?? [])->map(fn ($mode) => \Illuminate\Support\Str::headline((string) $mode))->join(', ') ?: 'Manual' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textFaint">Datasets</dt>
                        <dd class="mt-1 text-textPrimary">{{ collect($manifest['supported_datasets'] ?? [])->map(fn ($dataset) => \Illuminate\Support\Str::headline((string) $dataset))->join(', ') ?: 'Provider default' }}</dd>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @include('app.connectors.partials.status-badge', ['status' => data_get($manifest, 'supports_async_reports') ? 'active' : 'disabled', 'label' => 'Async reports'])
                        @include('app.connectors.partials.status-badge', ['status' => data_get($manifest, 'supports_webhooks') ? 'active' : 'disabled', 'label' => 'Webhooks'])
                        @include('app.connectors.partials.status-badge', ['status' => data_get($manifest, 'supports_incremental_sync') ? 'active' : 'disabled', 'label' => 'Incremental'])
                    </div>
                </dl>
            </div>

            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Quota usage</h2>
                <div class="mt-4 space-y-3">
                    @forelse ((array) data_get($diagnostics, 'quota.budgets', []) as $budget)
                        <div>
                            <div class="flex items-center justify-between gap-3 text-xs">
                                <span class="font-medium text-textPrimary">{{ \Illuminate\Support\Str::headline($budget['scope'].' '.$budget['type']) }}</span>
                                @include('app.connectors.partials.status-badge', ['status' => $budget['status']])
                            </div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-surfaceSubtle">
                                <div class="h-full bg-emerald-500" style="width: {{ min(100, ($budget['limit'] > 0 ? ($budget['used'] / $budget['limit']) * 100 : 0)) }}%"></div>
                            </div>
                            <p class="mt-1 text-xs text-textSecondary">{{ $budget['used'] }} / {{ $budget['limit'] }} used</p>
                        </div>
                    @empty
                        <p class="text-sm text-textSecondary">No quota budgets configured for this provider.</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Webhook readiness</h2>
                <div class="mt-4">
                    @include('app.connectors.partials.status-badge', ['status' => $account->webhookRegistration?->status ?? 'pending'])
                    <p class="mt-3 text-sm text-textSecondary">{{ data_get($account->webhookRegistration?->metadata_json, 'registration_ready') ? 'Provider webhook registration can be enabled in a later phase.' : 'Provider webhooks are not available or not prepared yet.' }}</p>
                    <p class="mt-2 text-xs text-textFaint">{{ collect((array) $account->webhookRegistration?->event_types_json)->join(', ') ?: 'No event types advertised' }}</p>
                </div>
            </div>
        </div>

        @if (data_get($account->metadata_json, 'account_hierarchy') || data_get($account->metadata_json, 'crm_object_overview'))
            <div class="grid gap-6 lg:grid-cols-2">
                <div class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-base font-semibold text-textPrimary">Account hierarchy</h2>
                    <div class="mt-4 space-y-3">
                        @forelse ((array) data_get($account->metadata_json, 'account_hierarchy', []) as $node)
                            <div class="rounded-md border border-border bg-background px-3 py-2 text-sm">
                                <p class="font-medium text-textPrimary">{{ $node['name'] ?? $node['id'] ?? 'Ad account' }}</p>
                                <p class="mt-1 text-xs text-textSecondary">{{ $node['id'] ?? 'Unknown ID' }}{{ !empty($node['parent_id']) ? ' · Parent '.$node['parent_id'] : '' }}</p>
                            </div>
                        @empty
                            <p class="text-sm text-textSecondary">No ad account hierarchy recorded.</p>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-base font-semibold text-textPrimary">CRM objects</h2>
                    <div class="mt-4 space-y-3">
                        @forelse ((array) data_get($account->metadata_json, 'crm_object_overview', []) as $objectKey => $object)
                            <div class="flex items-center justify-between rounded-md border border-border bg-background px-3 py-2 text-sm">
                                <span class="font-medium text-textPrimary">{{ $object['display_name'] ?? \Illuminate\Support\Str::headline((string) $objectKey) }}</span>
                                <span class="text-xs text-textSecondary">{{ $object['field_count'] ?? 0 }} fields</span>
                            </div>
                        @empty
                            <p class="text-sm text-textSecondary">No CRM schema overview recorded.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="text-base font-semibold text-textPrimary">Scopes</h2>
            @if ($account->scopes->isEmpty())
                <p class="mt-3 text-sm text-textSecondary">No scopes recorded yet.</p>
            @else
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($account->scopes as $scope)
                        <span class="inline-flex items-center gap-2 rounded-full border border-border bg-background px-3 py-1 text-xs text-textSecondary">
                            <span class="font-medium text-textPrimary">{{ $scope->scope }}</span>
                            <span>{{ \Illuminate\Support\Str::headline($scope->scope_type) }}</span>
                        </span>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-lg border border-border bg-surface">
                <div class="border-b border-border px-5 py-4">
                    <h2 class="text-base font-semibold text-textPrimary">Datasets</h2>
                    <p class="mt-1 text-sm text-textSecondary">Discovered provider datasets can be enabled, disabled, and scheduled independently.</p>
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
                                        <p class="mt-1 text-xs text-textSecondary">
                                            Last {{ $dataset->last_sync_at?->diffForHumans() ?? 'never' }} &middot;
                                            Next {{ $dataset->next_sync_at?->diffForHumans() ?? 'manual' }}
                                        </p>
                                    </div>
                                    <div class="flex flex-col items-end gap-2">
                                        @include('app.connectors.partials.status-badge', ['status' => $dataset->status])
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <form method="POST" action="{{ route('app.connectors.datasets.backfill', $dataset) }}" class="flex flex-wrap items-center justify-end gap-2">
                                                @csrf
                                                <input type="date" name="range_start" value="{{ now()->subDays(30)->toDateString() }}" class="rounded-md border border-border bg-background px-2 py-1 text-xs text-textPrimary">
                                                <input type="date" name="range_end" value="{{ now()->subDay()->toDateString() }}" class="rounded-md border border-border bg-background px-2 py-1 text-xs text-textPrimary">
                                                <button type="submit" class="pl-btn-secondary">
                                                    <i data-lucide="calendar-clock" class="h-4 w-4"></i>
                                                    <span>Backfill</span>
                                                </button>
                                            </form>
                                            @if ($dataset->status === \App\Models\Connectors\ConnectorDataset::STATUS_ACTIVE)
                                                <form method="POST" action="{{ route('app.connectors.datasets.disable', $dataset) }}">
                                                    @csrf
                                                    <button type="submit" class="pl-btn-secondary">
                                                        <i data-lucide="pause" class="h-4 w-4"></i>
                                                        <span>Disable</span>
                                                    </button>
                                                </form>
                                            @else
                                                <form method="POST" action="{{ route('app.connectors.datasets.enable', $dataset) }}" class="flex items-center gap-2">
                                                    @csrf
                                                    <select name="sync_frequency" class="rounded-md border border-border bg-background px-2 py-1 text-xs text-textPrimary">
                                                        @foreach (['daily' => 'Daily', 'hourly' => 'Hourly', 'weekly' => 'Weekly', 'manual' => 'Manual'] as $value => $label)
                                                            <option value="{{ $value }}" @selected(($dataset->sync_frequency ?? 'daily') === $value)>{{ $label }}</option>
                                                        @endforeach
                                                    </select>
                                                    <button type="submit" class="pl-btn-secondary">
                                                        <i data-lucide="play" class="h-4 w-4"></i>
                                                        <span>Enable</span>
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
            @endif
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-lg border border-border bg-surface">
                <div class="border-b border-border px-5 py-4">
                    <h2 class="text-base font-semibold text-textPrimary">Latest report jobs</h2>
                    <p class="mt-1 text-sm text-textSecondary">Async report preparation and retrieval status.</p>
                </div>
                <div class="divide-y divide-border">
                    @forelse ($account->asyncReportJobs as $job)
                        <div class="px-5 py-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-medium text-textPrimary">{{ \Illuminate\Support\Str::headline($job->report_type) }}</p>
                                    <p class="mt-1 text-xs text-textSecondary">{{ $job->external_report_id ?: 'Provider job pending' }} · {{ $job->created_at?->diffForHumans() }}</p>
                                </div>
                                @include('app.connectors.partials.status-badge', ['status' => $job->status])
                            </div>
                        </div>
                    @empty
                        <div class="p-6 text-sm text-textSecondary">No async report jobs recorded.</div>
                    @endforelse
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface">
                <div class="border-b border-border px-5 py-4">
                    <h2 class="text-base font-semibold text-textPrimary">Backfill status</h2>
                    <p class="mt-1 text-sm text-textSecondary">Recently queued historical ranges.</p>
                </div>
                <div class="divide-y divide-border">
                    @forelse ($account->backfillRanges as $range)
                        <div class="px-5 py-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-medium text-textPrimary">{{ $range->dataset_key }}</p>
                                    <p class="mt-1 text-xs text-textSecondary">{{ $range->range_start?->toDateString() }} to {{ $range->range_end?->toDateString() }}</p>
                                </div>
                                @include('app.connectors.partials.status-badge', ['status' => $range->status])
                            </div>
                        </div>
                    @empty
                        <div class="p-6 text-sm text-textSecondary">No backfill ranges queued.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface">
                <div class="border-b border-border px-5 py-4">
                    <h2 class="text-base font-semibold text-textPrimary">Health events</h2>
                    <p class="mt-1 text-sm text-textSecondary">Provider and dataset health history.</p>
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
                <p class="mt-1 text-sm text-textSecondary">Manual, scheduled, backfill, discovery, and webhook sync activity.</p>
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
                                <th class="px-5 py-3 font-medium">Records</th>
                                <th class="px-5 py-3 font-medium">Duration</th>
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
                                    <td class="px-5 py-4 text-textSecondary">{{ $run->records_processed ?: data_get($run->metrics_json, 'observations_written', 0) }}</td>
                                    <td class="px-5 py-4 text-textSecondary">{{ $run->duration_ms !== null ? round($run->duration_ms / 1000, 2).'s' : 'Pending' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
