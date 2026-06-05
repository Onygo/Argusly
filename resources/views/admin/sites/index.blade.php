@extends('layouts.admin', ['title' => 'Sites'])

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Sites</h1>
        <p class="text-textSecondary mt-1">All connected sites.</p>
    </div>

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

        <div class="hidden overflow-x-auto lg:block">
            <table class="min-w-[1100px] w-full text-sm">
                <thead>
                    <tr class="text-left text-textSecondary">
                        <th class="pb-2 font-medium">Workspace</th>
                        <th class="pb-2 font-medium">Name</th>
                        <th class="pb-2 font-medium">URL</th>
                        <th class="pb-2 font-medium">Organization</th>
                        <th class="pb-2 font-medium">Platform</th>
                        <th class="pb-2 font-medium">Status</th>
                        <th class="pb-2 font-medium">Heartbeat</th>
                        <th class="pb-2 font-medium">Last heartbeat</th>
                        <th class="pb-2 font-medium">WP version</th>
                        <th class="pb-2 font-medium">Plugin version</th>
                        <th class="pb-2 font-medium">Plugin status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
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
                        <tr>
                            <td class="py-3">{{ $site->workspace?->name ?? 'n/a' }}</td>
                            <td class="py-3">{{ $site->name }}</td>
                            <td class="py-3 max-w-xs truncate" title="{{ $site->base_url ?: $site->site_url }}">{{ $site->base_url ?: $site->site_url }}</td>
                            <td class="py-3">{{ $site->workspace?->organization?->name ?? 'n/a' }}</td>
                            <td class="py-3">
                                <span class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium {{ $site->connector_platform === 'laravel' ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700' }}">
                                    {{ $site->connector_platform ?? $site->type ?? 'wp' }}
                                </span>
                            </td>
                            <td class="py-3">{{ $site->status ?? ($site->is_active ? 'active' : 'inactive') }}</td>
                            <td class="py-3">
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
                            </td>
                            <td class="py-3 text-textSecondary text-xs">{{ $site->last_heartbeat_at?->diffForHumans() ?? 'never' }}</td>
                            <td class="py-3">{{ $isWpSite ? ($wpVersion !== '' ? $wpVersion : '-') : '-' }}</td>
                            <td class="py-3">{{ $isWpSite ? ($installedVersion !== '' ? $installedVersion : '-') : '-' }}</td>
                            <td class="py-3">
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
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="py-6 text-center text-textSecondary" colspan="11">No sites found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">{{ $sites->links() }}</div>
@endsection
