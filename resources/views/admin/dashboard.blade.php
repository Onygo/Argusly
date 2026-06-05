@extends('layouts.admin', ['title' => 'Admin dashboard'])

@section('content')
    <div class="mb-8">
        <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Admin dashboard</h1>
        <p class="text-textSecondary mt-1">Overview of approvals and activity.</p>
    </div>

    @if (session('status'))
        <x-alert class="mb-6">{{ session('status') }}</x-alert>
    @endif
    @if ($errors->has('dashboard'))
        <div class="mb-6 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('dashboard') }}</div>
    @endif

    <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-sm text-textSecondary">Pending organizations</p>
            <p class="text-3xl font-semibold text-textPrimary mt-1">{{ $pendingOrganizations }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-sm text-textSecondary">Pending users</p>
            <p class="text-3xl font-semibold text-textPrimary mt-1">{{ $pendingUsers }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-sm text-textSecondary">Organizations on hold</p>
            <p class="text-3xl font-semibold text-textPrimary mt-1">{{ $orgsOnHold }}</p>
        </div>
    </div>

    <div class="rounded-lg border border-border bg-surface p-5 mb-8">
        <h3 class="font-semibold text-textPrimary">Client activity signals</h3>
        <p class="mt-1 text-sm text-textSecondary">Rolling summary and 30-day client-level activity metrics.</p>

        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            @foreach ($activitySummaryCards as $metric)
                <div class="rounded border border-border p-3">
                    <p class="text-xs text-textSecondary">{{ $metric['label'] }}</p>
                    <p class="mt-1 text-2xl font-semibold text-textPrimary">{{ $metric['value'] }}</p>
                </div>
            @endforeach
        </div>

        <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($clientActivityCards as $client)
                <div class="rounded border border-border p-4">
                    <p class="text-sm font-semibold text-textPrimary">{{ $client['workspace_name'] }}</p>
                    <p class="mt-0.5 text-xs text-textSecondary">{{ $client['organization_name'] }}</p>

                    <div class="mt-3 grid grid-cols-2 gap-2">
                        @foreach ($client['metric_cards'] as $metric)
                            <div class="rounded border border-border p-2">
                                <p class="text-xs text-textSecondary">{{ $metric['label'] }}</p>
                                <p class="mt-1 text-lg font-semibold text-textPrimary">{{ $metric['value'] }}</p>
                            </div>
                        @endforeach
                    </div>

                    <p class="mt-3 text-xs text-textSecondary">
                        {{ $activityLabels['last_activity_at'] }}: <span class="font-medium text-textPrimary">{{ $client['last_activity_at'] ?? 'n/a' }}</span>
                    </p>

                    <div class="mt-3 flex flex-wrap gap-2">
                        @if ($client['organization_id'])
                            <a href="{{ route('admin.organizations.show', $client['organization_id']) }}" class="inline-flex items-center rounded border border-border px-2.5 py-1.5 text-xs">
                                View client details
                            </a>
                        @endif
                        <form method="POST" action="{{ route('admin.workspaces.impersonate', $client['workspace_id']) }}">
                            @csrf
                            <button class="inline-flex items-center rounded border border-border px-2.5 py-1.5 text-xs">
                                Impersonate client
                            </button>
                        </form>
                    </div>
                </div>
            @empty
                <div class="rounded border border-border bg-background px-4 py-3 text-sm text-textSecondary md:col-span-2 xl:col-span-3">
                    No client activity recorded in the last 30 days.
                </div>
            @endforelse
        </div>
    </div>

    <div class="rounded-lg border border-border bg-surface p-5 mb-8">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="font-semibold text-textPrimary">SEO readiness</h3>
                <p class="mt-1 text-sm text-textSecondary">Crawl, indexation and metadata checks for public Argusly pages.</p>
            </div>
            <div class="flex flex-wrap gap-2 text-xs">
                <a href="{{ $seoDashboard['sitemap_url'] }}" class="rounded border border-border px-2.5 py-1.5">Sitemap</a>
                <a href="{{ $seoDashboard['robots_url'] }}" class="rounded border border-border px-2.5 py-1.5">Robots</a>
            </div>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <div class="rounded border border-border p-3">
                <p class="text-xs text-textSecondary">Canonical audit</p>
                <p class="mt-1 text-sm font-semibold text-textPrimary">{{ $seoDashboard['canonical_audit_status'] }}</p>
            </div>
            <div class="rounded border border-border p-3">
                <p class="text-xs text-textSecondary">Last audit run</p>
                <p class="mt-1 text-sm font-semibold text-textPrimary">{{ $seoDashboard['last_audit_run'] ?? 'Not run yet' }}</p>
            </div>
            <div class="rounded border border-border p-3">
                <p class="text-xs text-textSecondary">Missing metadata</p>
                <p class="mt-1 text-2xl font-semibold text-textPrimary">{{ $seoDashboard['pages_missing_metadata'] }}</p>
            </div>
            <div class="rounded border border-border p-3">
                <p class="text-xs text-textSecondary">Missing image alt</p>
                <p class="mt-1 text-2xl font-semibold text-textPrimary">{{ $seoDashboard['pages_missing_alt_text'] }}</p>
            </div>
            <div class="rounded border border-border p-3">
                <p class="text-xs text-textSecondary">Excluded pages</p>
                <p class="mt-1 text-2xl font-semibold text-textPrimary">{{ $seoDashboard['pages_excluded_from_index'] }}</p>
            </div>
        </div>

        <div class="mt-4 rounded border border-border bg-background px-4 py-3">
            <p class="text-xs font-medium text-textPrimary">Recommendations</p>
            <ul class="mt-2 space-y-1 text-xs text-textSecondary">
                @foreach($seoDashboard['recommendations'] as $recommendation)
                    <li>{{ $recommendation }}</li>
                @endforeach
            </ul>
        </div>
    </div>

    <div class="rounded-lg border border-border bg-surface p-5 mb-8">
        <h3 class="font-semibold text-textPrimary mb-4">Argusly onboarding</h3>
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded border border-border p-4">
                <p class="text-xs text-textSecondary">New registrations (7 days)</p>
                <p class="text-2xl font-semibold text-textPrimary mt-1">{{ $onboarding['new_registrations_7d'] }}</p>
            </div>
            <div class="rounded border border-border p-4">
                <p class="text-xs text-textSecondary">Activated (7 days)</p>
                <p class="text-2xl font-semibold text-textPrimary mt-1">{{ $onboarding['activated_7d'] }}</p>
            </div>
            <div class="rounded border border-border p-4">
                <p class="text-xs text-textSecondary">Avg time to first value</p>
                <p class="text-2xl font-semibold text-textPrimary mt-1">
                    {{ $onboarding['avg_minutes_to_first_value'] !== null ? $onboarding['avg_minutes_to_first_value'].' min' : 'n/a' }}
                </p>
            </div>
        </div>

        <x-mobile-card-list class="mt-5">
            @forelse($onboarding['phase_rows'] as $row)
                <article class="pl-mobile-card">
                    <div class="pl-mobile-card__header">
                        <div class="min-w-0">
                            <div class="pl-mobile-card__title">{{ $row->user?->name ?? 'Unknown' }}</div>
                            <div class="mt-1 text-xs text-textSecondary">{{ $row->user?->email ?? 'Unknown' }}</div>
                        </div>
                        <span class="pl-badge border-border bg-surfaceSubtle text-textPrimary">
                            <span class="pl-badge__label">{{ $row->phase }}</span>
                        </span>
                    </div>
                    <div class="pl-mobile-card__meta">
                        <x-metadata-row label="Updated" :value="$row->updated_at?->diffForHumans() ?? 'n/a'" />
                    </div>
                </article>
            @empty
                <div class="pl-mobile-card text-sm text-textSecondary">No onboarding rows yet.</div>
            @endforelse
        </x-mobile-card-list>

        <x-responsive-table class="mt-5">
            <thead>
            <tr class="text-left">
                <th class="py-2">User</th>
                <th class="py-2">Email</th>
                <th class="py-2">Phase</th>
                <th class="py-2">Updated</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-border">
            @forelse($onboarding['phase_rows'] as $row)
                <tr>
                    <td class="py-2">{{ $row->user?->name ?? 'Unknown' }}</td>
                    <td class="py-2">{{ $row->user?->email ?? 'Unknown' }}</td>
                    <td class="py-2">{{ $row->phase }}</td>
                    <td class="py-2">{{ $row->updated_at?->diffForHumans() }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="py-3 text-textSecondary">No onboarding rows yet.</td>
                </tr>
            @endforelse
            </tbody>
        </x-responsive-table>
    </div>

    <div class="rounded-lg border border-border bg-surface p-5 mb-8">
        <h3 class="font-semibold text-textPrimary">WordPress plugin management</h3>
        <p class="mt-1 text-sm text-textSecondary">Manage plugin releases and inspect installed site versions from heartbeat reports.</p>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <div class="rounded border border-border p-4">
                <p class="text-xs text-textSecondary">Latest release</p>
                <p class="mt-1 text-lg font-semibold text-textPrimary">
                    {{ $latestWpPluginRelease?->version ? 'v'.$latestWpPluginRelease->version : 'No release uploaded' }}
                </p>
                @if ($latestWpPluginRelease)
                    <p class="mt-1 text-xs text-textSecondary">
                        Min WP: {{ $latestWpPluginRelease->min_wp_version ?: '-' }} · Tested WP: {{ $latestWpPluginRelease->tested_wp_version ?: '-' }}
                    </p>
                    <p class="mt-1 text-xs text-textSecondary">
                        Uploaded {{ $latestWpPluginRelease->created_at?->diffForHumans() }}
                    </p>
                    <a href="{{ route('admin.dashboard.plugin-releases.download', $latestWpPluginRelease) }}" class="mt-3 inline-flex rounded border border-border px-3 py-1.5 text-xs">
                        Download latest zip
                    </a>
                @endif

                @can('admin-area-superadmin')
                    @if ($errors->hasAny(['version', 'min_wp_version', 'tested_wp_version', 'archive']))
                        <div class="mt-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-800">
                            <p class="font-medium">Upload failed:</p>
                            <p class="mt-1">{{ $errors->first('version') ?: $errors->first('archive') ?: $errors->first() }}</p>
                            @if ($errors->has('archive') && str_contains($errors->first('archive'), 'nginx'))
                                <p class="mt-2 text-[11px] opacity-75">
                                    <a href="{{ route('admin.dashboard.upload-diagnostics') }}" target="_blank" class="underline">View server diagnostics</a>
                                </p>
                            @endif
                        </div>
                    @endif
                    <form method="POST" action="{{ route('admin.dashboard.plugin-releases.store') }}" enctype="multipart/form-data" class="mt-4 grid gap-2">
                        @csrf
                        <div class="flex items-center justify-between">
                            <label class="text-xs text-textSecondary">Upload new release</label>
                            <a href="{{ route('admin.dashboard.upload-diagnostics') }}" target="_blank" class="text-[10px] text-textSecondary hover:text-textPrimary">
                                Check server limits
                            </a>
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <input type="text" name="version" value="{{ old('version') }}" placeholder="Version (e.g. 1.4.0)" class="rounded border border-border bg-background px-2 py-2 text-xs" required>
                            <input type="text" name="min_wp_version" value="{{ old('min_wp_version') }}" placeholder="Min WP version" class="rounded border border-border bg-background px-2 py-2 text-xs">
                            <input type="text" name="tested_wp_version" value="{{ old('tested_wp_version') }}" placeholder="Tested WP version" class="rounded border border-border bg-background px-2 py-2 text-xs">
                            <label class="inline-flex items-center gap-2 rounded border border-border bg-background px-2 py-2 text-xs text-textSecondary">
                                <input type="checkbox" name="is_security_release" value="1" {{ old('is_security_release') ? 'checked' : '' }}>
                                Security release
                            </label>
                        </div>
                        <input type="file" name="archive" accept=".zip,application/zip" class="rounded border border-border bg-background px-2 py-2 text-xs" required>
                        <p class="text-[10px] text-textSecondary">Maximum file size: 50MB. Only .zip files accepted.</p>
                        <button class="inline-flex items-center justify-center rounded border border-border px-3 py-2 text-xs font-medium">
                            Upload release
                        </button>
                    </form>
                @else
                    <p class="mt-4 text-xs text-textSecondary">Only superadmins can upload plugin releases.</p>
                @endcan
            </div>

            <div class="rounded border border-border p-4">
                <p class="text-xs text-textSecondary">Recent releases</p>
                <x-mobile-card-list class="mt-3">
                    @forelse ($pluginReleases as $release)
                        <article class="pl-mobile-card">
                            <div class="pl-mobile-card__header">
                                <div class="pl-mobile-card__title">v{{ $release->version }}</div>
                                @if ($release->is_security_release)
                                    <span class="pl-badge border-rose-200 bg-rose-50 text-rose-700"><span class="pl-badge__label">Security</span></span>
                                @else
                                    <span class="pl-badge border-slate-200 bg-slate-100 text-slate-700"><span class="pl-badge__label">Standard</span></span>
                                @endif
                            </div>
                            <div class="pl-mobile-card__meta">
                                <x-metadata-row label="WP range" :value="($release->min_wp_version ?: '-') . ' → ' . ($release->tested_wp_version ?: '-')" />
                                <x-metadata-row label="Uploaded" :value="$release->created_at?->diffForHumans() ?? 'n/a'" />
                            </div>
                            <div class="pl-mobile-card__actions">
                                <a href="{{ route('admin.dashboard.plugin-releases.download', $release) }}" class="pl-btn-secondary w-full justify-center">Download</a>
                                @can('admin-area-superadmin')
                                    @if ((string) $latestWpPluginRelease?->id !== (string) $release->id)
                                        <form method="POST" action="{{ route('admin.dashboard.plugin-releases.destroy', $release) }}" onsubmit="return confirm('Delete WordPress plugin release v{{ $release->version }}? This also removes the stored zip when no other release uses it.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="pl-btn-secondary w-full justify-center border-rose-200 text-rose-700 hover:bg-rose-50">Delete</button>
                                        </form>
                                    @endif
                                @endcan
                            </div>
                        </article>
                    @empty
                        <div class="pl-mobile-card text-sm text-textSecondary">No plugin releases uploaded yet.</div>
                    @endforelse
                </x-mobile-card-list>

                <x-responsive-table class="mt-2" table-class="text-xs">
                    <thead>
                    <tr class="text-left text-textSecondary">
                        <th class="py-1 font-medium">Version</th>
                        <th class="py-1 font-medium">WP range</th>
                        <th class="py-1 font-medium">Type</th>
                        <th class="py-1 font-medium">Uploaded</th>
                        <th class="py-1 font-medium">Action</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                    @forelse ($pluginReleases as $release)
                        <tr>
                            <td class="py-2 font-medium text-textPrimary">v{{ $release->version }}</td>
                            <td class="py-2 text-textSecondary">{{ $release->min_wp_version ?: '-' }} → {{ $release->tested_wp_version ?: '-' }}</td>
                            <td class="py-2">
                                @if ($release->is_security_release)
                                    <span class="inline-flex rounded bg-rose-100 px-2 py-0.5 text-[11px] font-medium text-rose-700">security</span>
                                @else
                                    <span class="inline-flex rounded bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-700">standard</span>
                                @endif
                            </td>
                            <td class="py-2 text-textSecondary">{{ $release->created_at?->diffForHumans() }}</td>
                            <td class="py-2">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <a href="{{ route('admin.dashboard.plugin-releases.download', $release) }}" class="rounded border border-border px-2 py-1 text-[11px]">Download</a>
                                    @can('admin-area-superadmin')
                                        @if ((string) $latestWpPluginRelease?->id !== (string) $release->id)
                                            <form method="POST" action="{{ route('admin.dashboard.plugin-releases.destroy', $release) }}" onsubmit="return confirm('Delete WordPress plugin release v{{ $release->version }}? This also removes the stored zip when no other release uses it.');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="rounded border border-rose-200 px-2 py-1 text-[11px] text-rose-700 hover:bg-rose-50">Delete</button>
                                            </form>
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-3 text-textSecondary">No plugin releases uploaded yet.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </x-responsive-table>
            </div>
        </div>

        <p class="mb-2 mt-5 text-xs text-textSecondary">WordPress site plugin versions (from heartbeat)</p>
        <x-mobile-card-list>
            @forelse($wpSiteVersions as $row)
                @php
                    $site = $row['site'] ?? null;
                @endphp
                <article class="pl-mobile-card">
                    <div class="pl-mobile-card__header">
                        <div class="min-w-0">
                            <div class="pl-mobile-card__title">{{ $site?->name ?? ($row['site_name'] ?? 'n/a') }}</div>
                            <div class="mt-1 text-xs text-textSecondary">{{ $site?->workspace?->organization?->name ?? 'n/a' }} · {{ $site?->workspace?->name ?? 'n/a' }}</div>
                        </div>
                        <x-status-badge
                            :label="match ($row['status']) { 'up_to_date' => 'Up to date', 'outdated' => 'Outdated', 'ahead' => 'Ahead', 'tracked' => 'No latest release', default => 'Unknown' }"
                            :color="match ($row['status']) { 'up_to_date' => 'green', 'outdated' => 'amber', 'ahead' => 'sky', default => 'slate' }"
                        />
                    </div>
                    <div class="pl-mobile-card__meta">
                        <x-metadata-row label="Heartbeat" :value="$site?->last_heartbeat_at?->diffForHumans() ?? 'never'" />
                        <x-metadata-row label="WP version" :value="$site?->wp_version ?? '-'" />
                        <x-metadata-row label="Installed plugin" :value="$row['installed_version'] ?? '-'" />
                    </div>
                </article>
            @empty
                <div class="pl-mobile-card text-sm text-textSecondary">No WordPress sites found yet.</div>
            @endforelse
        </x-mobile-card-list>

        <x-responsive-table table-class="text-xs">
            <thead>
            <tr class="text-left text-textSecondary">
                <th class="py-1 font-medium">Organization</th>
                <th class="py-1 font-medium">Workspace</th>
                <th class="py-1 font-medium">Site</th>
                <th class="py-1 font-medium">Last heartbeat</th>
                <th class="py-1 font-medium">WP version</th>
                <th class="py-1 font-medium">Installed plugin</th>
                <th class="py-1 font-medium">Release status</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-border">
            @forelse($wpSiteVersions as $row)
                @php
                    $site = $row['site'] ?? null;
                    $status = $row['status'] ?? 'unknown';
                @endphp
                <tr>
                    <td class="py-2">{{ $site?->workspace?->organization?->name ?? 'n/a' }}</td>
                    <td class="py-2">{{ $site?->workspace?->name ?? 'n/a' }}</td>
                    <td class="py-2">{{ $site?->name ?? ($row['site_name'] ?? 'n/a') }}</td>
                    <td class="py-2 text-textSecondary">{{ $site?->last_heartbeat_at?->diffForHumans() ?? 'never' }}</td>
                    <td class="py-2 text-textSecondary">{{ $site?->wp_version ?? '-' }}</td>
                    <td class="py-2 font-medium text-textPrimary">{{ $row['installed_version'] ?? '-' }}</td>
                    <td class="py-2">
                        @if ($status === 'up_to_date')
                            <span class="inline-flex rounded bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-700">up to date</span>
                        @elseif ($status === 'outdated')
                            <span class="inline-flex rounded bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-700">outdated</span>
                        @elseif ($status === 'ahead')
                            <span class="inline-flex rounded bg-sky-100 px-2 py-0.5 text-[11px] font-medium text-sky-700">ahead</span>
                        @elseif ($status === 'tracked')
                            <span class="inline-flex rounded bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-700">no latest release</span>
                        @else
                            <span class="inline-flex rounded bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-700">unknown</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="py-3 text-textSecondary">No WordPress sites found yet.</td>
                </tr>
            @endforelse
            </tbody>
        </x-responsive-table>
    </div>

@endsection
