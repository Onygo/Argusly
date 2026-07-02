@extends('layouts.admin', ['title' => 'Sites'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Sites</x-slot:title>
        <x-slot:description>All connected sites.</x-slot:description>
    </x-page-header>
@endsection

@section('content')

    <div class="rounded-lg border border-border bg-surface p-4">
        <div class="space-y-3 lg:hidden">
            @forelse ($sites as $site)
                @php
                    $isWpSite = $site->connector_platform === 'wp' || \App\Models\ClientSite::normalizeType((string) $site->type) === \App\Models\ClientSite::TYPE_WORDPRESS;
                    $installedVersion = trim((string) ($site->connector_version ?: $site->plugin_version));
                    $wpVersion = trim((string) ($site->wp_version ?: data_get($site->connector_meta, 'framework_version', '')));
                    $pluginStatus = '-';
                    if ($isWpSite) {
                        if ($installedVersion === '') {
                            $pluginStatus = 'unknown';
                        } elseif (! $latestWpPluginVersion) {
                            $pluginStatus = 'no-latest';
                        } elseif (version_compare($installedVersion, $latestWpPluginVersion, '<')) {
                            $pluginStatus = 'outdated';
                        } elseif (version_compare($installedVersion, $latestWpPluginVersion, '>')) {
                            $pluginStatus = 'ahead';
                        } else {
                            $pluginStatus = 'up-to-date';
                        }
                    }
                    $hbStatus = $site->heartbeat_status;
                @endphp
                <article class="rounded border border-border p-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-textPrimary">{{ $site->name }}</p>
                            <p class="mt-0.5 truncate text-xs text-textSecondary" title="{{ $site->base_url ?: $site->site_url }}">{{ $site->base_url ?: $site->site_url }}</p>
                        </div>
                        <span class="inline-flex shrink-0 items-center rounded px-2 py-0.5 text-xs font-medium {{ $site->connector_platform === 'laravel' ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700' }}">
                            {{ $site->connector_platform ?? $site->type ?? 'wp' }}
                        </span>
                    </div>

                    <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                        <div>
                            <dt class="text-textSecondary">Workspace</dt>
                            <dd class="truncate text-textPrimary">{{ $site->workspace?->name ?? 'n/a' }}</dd>
                        </div>
                        <div>
                            <dt class="text-textSecondary">Status</dt>
                            <dd class="text-textPrimary">{{ $site->status ?? ($site->is_active ? 'active' : 'inactive') }}</dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-textSecondary">Organization</dt>
                            <dd class="truncate text-textPrimary">{{ $site->workspace?->organization?->name ?? 'n/a' }}</dd>
                        </div>
                        <div>
                            <dt class="text-textSecondary">Heartbeat</dt>
                            <dd>
                                <span class="inline-flex items-center gap-1.5 rounded px-2 py-0.5 text-xs font-medium
                                    {{ $hbStatus === 'online' ? 'bg-emerald-100 text-emerald-700' : '' }}
                                    {{ $hbStatus === 'warning' ? 'bg-amber-100 text-amber-700' : '' }}
                                    {{ $hbStatus === 'offline' ? 'bg-gray-100 text-gray-500' : '' }}">
                                    <span class="h-1.5 w-1.5 rounded-full
                                        {{ $hbStatus === 'online' ? 'bg-emerald-500' : '' }}
                                        {{ $hbStatus === 'warning' ? 'bg-amber-500' : '' }}
                                        {{ $hbStatus === 'offline' ? 'bg-gray-400' : '' }}"></span>
                                    {{ $hbStatus }}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-textSecondary">Last heartbeat</dt>
                            <dd class="text-textPrimary">{{ $site->last_heartbeat_at?->diffForHumans() ?? 'never' }}</dd>
                        </div>
                        <div>
                            <dt class="text-textSecondary">WP version</dt>
                            <dd class="text-textPrimary">{{ $isWpSite ? ($wpVersion !== '' ? $wpVersion : '-') : '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-textSecondary">Plugin version</dt>
                            <dd class="text-textPrimary">{{ $isWpSite ? ($installedVersion !== '' ? $installedVersion : '-') : '-' }}</dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-textSecondary">Plugin status</dt>
                            <dd>
                                @if (! $isWpSite)
                                    <span class="text-textSecondary">-</span>
                                @elseif ($pluginStatus === 'up-to-date')
                                    <span class="inline-flex rounded bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">up-to-date</span>
                                @elseif ($pluginStatus === 'outdated')
                                    <span class="inline-flex rounded bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">outdated</span>
                                @elseif ($pluginStatus === 'ahead')
                                    <span class="inline-flex rounded bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-700">ahead</span>
                                @elseif ($pluginStatus === 'no-latest')
                                    <span class="inline-flex rounded bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">no latest release</span>
                                @else
                                    <span class="inline-flex rounded bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">unknown</span>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </article>
            @empty
                <div class="py-6 text-center text-textSecondary">No sites found.</div>
            @endforelse
        </div>

        <x-data-table label="Admin sites" description="Connected sites with workspace, organization, platform, heartbeat, and connector version status." table-class="min-w-[1100px]" class="hidden border-x-0 border-b-0 rounded-none lg:block">
            <x-data-table.header>
                <x-data-table.row>
                    <x-data-table.cell heading>Workspace</x-data-table.cell>
                    <x-data-table.cell heading>Name</x-data-table.cell>
                    <x-data-table.cell heading>URL</x-data-table.cell>
                    <x-data-table.cell heading>Organization</x-data-table.cell>
                    <x-data-table.cell heading>Platform</x-data-table.cell>
                    <x-data-table.cell heading>Status</x-data-table.cell>
                    <x-data-table.cell heading>Heartbeat</x-data-table.cell>
                    <x-data-table.cell heading>Last heartbeat</x-data-table.cell>
                    <x-data-table.cell heading>WP version</x-data-table.cell>
                    <x-data-table.cell heading>Plugin version</x-data-table.cell>
                    <x-data-table.cell heading>Plugin status</x-data-table.cell>
                </x-data-table.row>
            </x-data-table.header>
            <tbody>
                @forelse ($sites as $site)
                    @php
                        $isWpSite = $site->connector_platform === 'wp' || \App\Models\ClientSite::normalizeType((string) $site->type) === \App\Models\ClientSite::TYPE_WORDPRESS;
                        $installedVersion = trim((string) ($site->connector_version ?: $site->plugin_version));
                        $wpVersion = trim((string) ($site->wp_version ?: data_get($site->connector_meta, 'framework_version', '')));
                        $pluginStatus = '-';
                        if ($isWpSite) {
                            if ($installedVersion === '') {
                                $pluginStatus = 'unknown';
                            } elseif (! $latestWpPluginVersion) {
                                $pluginStatus = 'no-latest';
                            } elseif (version_compare($installedVersion, $latestWpPluginVersion, '<')) {
                                $pluginStatus = 'outdated';
                            } elseif (version_compare($installedVersion, $latestWpPluginVersion, '>')) {
                                $pluginStatus = 'ahead';
                            } else {
                                $pluginStatus = 'up-to-date';
                            }
                        }
                        $hbStatus = $site->heartbeat_status;
                    @endphp
                    <x-data-table.row>
                        <x-data-table.cell label="Workspace">{{ $site->workspace?->name ?? 'n/a' }}</x-data-table.cell>
                        <x-data-table.cell label="Name">{{ $site->name }}</x-data-table.cell>
                        <x-data-table.cell label="URL" class="max-w-xs truncate" title="{{ $site->base_url ?: $site->site_url }}">{{ $site->base_url ?: $site->site_url }}</x-data-table.cell>
                        <x-data-table.cell label="Organization">{{ $site->workspace?->organization?->name ?? 'n/a' }}</x-data-table.cell>
                        <x-data-table.cell label="Platform">
                            <x-data-table.badge :tone="$site->connector_platform === 'laravel' ? 'danger' : 'info'" :label="$site->connector_platform ?? $site->type ?? 'wp'" />
                        </x-data-table.cell>
                        <x-data-table.cell label="Status">{{ $site->status ?? ($site->is_active ? 'active' : 'inactive') }}</x-data-table.cell>
                        <x-data-table.cell label="Heartbeat">
                            <x-data-table.badge :tone="$hbStatus === 'online' ? 'success' : ($hbStatus === 'warning' ? 'warning' : 'neutral')" :label="$hbStatus" />
                        </x-data-table.cell>
                        <x-data-table.cell label="Last heartbeat" class="text-xs text-textSecondary">{{ $site->last_heartbeat_at?->diffForHumans() ?? 'never' }}</x-data-table.cell>
                        <x-data-table.cell label="WP version">{{ $isWpSite ? ($wpVersion !== '' ? $wpVersion : '-') : '-' }}</x-data-table.cell>
                        <x-data-table.cell label="Plugin version">{{ $isWpSite ? ($installedVersion !== '' ? $installedVersion : '-') : '-' }}</x-data-table.cell>
                        <x-data-table.cell label="Plugin status">
                            @if (! $isWpSite)
                                <span class="text-textSecondary">-</span>
                            @elseif ($pluginStatus === 'up-to-date')
                                <x-data-table.badge tone="success" label="up-to-date" />
                            @elseif ($pluginStatus === 'outdated')
                                <x-data-table.badge tone="warning" label="outdated" />
                            @elseif ($pluginStatus === 'ahead')
                                <x-data-table.badge tone="info" label="ahead" />
                            @elseif ($pluginStatus === 'no-latest')
                                <x-data-table.badge label="no latest release" />
                            @else
                                <x-data-table.badge label="unknown" />
                            @endif
                        </x-data-table.cell>
                    </x-data-table.row>
                @empty
                    <x-data-table.empty colspan="11" title="No sites found" />
                @endforelse
            </tbody>
        </x-data-table>
    </div>

    <div class="mt-4">{{ $sites->links() }}</div>
@endsection
