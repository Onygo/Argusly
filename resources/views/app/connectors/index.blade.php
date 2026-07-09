@extends('layouts.app', ['title' => 'Data connectors'])

@section('pageHeader')
    <x-page-header title="Data connectors" />
@endsection

@section('pageDescription')
    <x-page-description>Manage source-data connections for search, analytics, social, ads, CRM, and other data providers.</x-page-description>
@endsection

@section('metricSection')
    <x-metric-section>
        <x-metric-card label="Configured providers" :value="$providerDefinitions->count()" />
        <x-metric-card label="Connected accounts" :value="$accounts->where('status', 'connected')->count()" />
        <x-metric-card label="Datasets" :value="$accounts->sum(fn ($account) => $account->datasets->count())" />
    </x-metric-section>
@endsection

@section('content')
    <div class="space-y-6">
        <div class="rounded-lg border border-border bg-surface p-5">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-textPrimary">Available providers</h2>
                    <p class="mt-1 text-sm text-textSecondary">Provider definitions are registered, but connection flows are intentionally gated for a later phase.</p>
                </div>
                <button class="pl-btn-secondary opacity-60" disabled>
                    <i data-lucide="plug-zap" class="h-4 w-4"></i>
                    <span>Connect coming soon</span>
                </button>
            </div>

            <div class="mt-5 grid gap-4 lg:grid-cols-3">
                @foreach ($providerDefinitions as $providerKey => $definition)
                    @php
                        $provider = $providers->get($providerKey);
                        $providerAccounts = $accounts->where('provider_key', $providerKey);
                    @endphp
                    <div class="rounded-lg border border-border bg-background p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-textPrimary">{{ $definition['name'] ?? \Illuminate\Support\Str::headline((string) $providerKey) }}</p>
                                <p class="mt-1 text-xs uppercase tracking-wide text-textFaint">{{ $definition['category'] ?? 'other' }}</p>
                            </div>
                            @include('app.connectors.partials.status-badge', ['status' => $provider?->status ?? ($definition['status'] ?? 'active')])
                        </div>
                        <div class="mt-4 grid grid-cols-3 gap-2 text-xs">
                            <div class="rounded-md border border-border bg-surface px-2 py-2">
                                <p class="text-textFaint">OAuth</p>
                                <p class="mt-1 font-medium text-textPrimary">{{ ($definition['supports_oauth'] ?? false) ? 'Yes' : 'No' }}</p>
                            </div>
                            <div class="rounded-md border border-border bg-surface px-2 py-2">
                                <p class="text-textFaint">Sync</p>
                                <p class="mt-1 font-medium text-textPrimary">{{ ($definition['supports_sync'] ?? false) ? 'Yes' : 'No' }}</p>
                            </div>
                            <div class="rounded-md border border-border bg-surface px-2 py-2">
                                <p class="text-textFaint">Accounts</p>
                                <p class="mt-1 font-medium text-textPrimary">{{ $providerAccounts->count() }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface">
            <div class="flex items-center justify-between gap-3 border-b border-border px-5 py-4">
                <div>
                    <h2 class="text-base font-semibold text-textPrimary">Connector accounts</h2>
                    <p class="mt-1 text-sm text-textSecondary">Workspace-scoped source accounts prepared for future OAuth and sync jobs.</p>
                </div>
            </div>

            @if ($accounts->isEmpty())
                <div class="p-8 text-center">
                    <div class="mx-auto flex h-11 w-11 items-center justify-center rounded-md border border-border bg-background">
                        <i data-lucide="database-zap" class="h-5 w-5 text-textSecondary"></i>
                    </div>
                    <p class="mt-3 text-sm font-medium text-textPrimary">No connector accounts yet</p>
                    <p class="mt-1 text-sm text-textSecondary">Accounts will appear here once provider-specific connection flows are enabled.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-border text-sm">
                        <thead class="bg-surfaceSubtle text-left text-xs uppercase tracking-wide text-textFaint">
                            <tr>
                                <th class="px-5 py-3 font-medium">Account</th>
                                <th class="px-5 py-3 font-medium">Provider</th>
                                <th class="px-5 py-3 font-medium">Status</th>
                                <th class="px-5 py-3 font-medium">Datasets</th>
                                <th class="px-5 py-3 font-medium">Last sync</th>
                                <th class="px-5 py-3 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($accounts as $account)
                                <tr>
                                    <td class="px-5 py-4">
                                        <p class="font-medium text-textPrimary">{{ $account->account_name }}</p>
                                        <p class="mt-1 text-xs text-textSecondary">{{ $account->external_account_id ?: 'External account pending' }}</p>
                                    </td>
                                    <td class="px-5 py-4 text-textSecondary">{{ $account->provider?->name ?? \Illuminate\Support\Str::headline($account->provider_key) }}</td>
                                    <td class="px-5 py-4">@include('app.connectors.partials.status-badge', ['status' => $account->status])</td>
                                    <td class="px-5 py-4 text-textSecondary">{{ $account->datasets->count() }}</td>
                                    <td class="px-5 py-4 text-textSecondary">{{ $account->last_synced_at?->diffForHumans() ?? 'Never' }}</td>
                                    <td class="px-5 py-4 text-right">
                                        <a href="{{ route('app.connectors.show', $account) }}" class="pl-btn-secondary">
                                            <i data-lucide="arrow-right" class="h-4 w-4"></i>
                                            <span>Open</span>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
