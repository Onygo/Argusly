@extends('layouts.app', ['title' => 'Site Setup'])

@section('content')
    @php
        $siteType = \App\Models\ClientSite::normalizeType((string) ($site->type ?? 'wordpress'));
        $isLaravel = $siteType === \App\Models\ClientSite::TYPE_LARAVEL;
        $isWordPress = $siteType === \App\Models\ClientSite::TYPE_WORDPRESS;
    @endphp

    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">{{ $site->name }}</h1>
            <p class="mt-1 text-textSecondary">{{ $site->base_url ?: $site->site_url }} · {{ strtoupper($siteType) }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('app.sites.insights.index', $site) }}" class="rounded border border-border px-3 py-2 text-sm">View insights</a>
            <a href="{{ route('app.sites') }}" class="rounded border border-border px-3 py-2 text-sm">Back to sites</a>
        </div>
    </div>

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->has('sites'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('sites') }}</div>
    @endif

    @if ($generatedKey && (string) $generatedSiteId === (string) $site->id)
        <div class="mb-6 rounded-lg border border-primary/40 bg-primarySoftBg p-4">
            <p class="text-sm font-semibold text-textPrimary">Site key</p>
            <p class="mt-1 text-xs text-textSecondary">This key is shown only once. Copy and save it in your connector settings.</p>
            <div class="mt-3 rounded border border-border bg-surface px-3 py-2 font-mono text-sm text-textPrimary" id="site-key-value">{{ $generatedKey }}</div>
            <button type="button" class="mt-3 rounded border border-border px-3 py-1.5 text-xs" onclick="navigator.clipboard.writeText(document.getElementById('site-key-value').innerText)">Copy key</button>
            @include('app.sites.partials.setup-instructions', ['siteType' => $siteType])
        </div>
    @endif

    @if (($generatedPluginLicenseKey ?? null) && (string) ($generatedPluginLicenseSiteId ?? '') === (string) $site->id)
        <div class="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4">
            <p class="text-sm font-semibold text-textPrimary">WordPress update license key</p>
            <p class="mt-1 text-xs text-textSecondary">This key is shown only once. Paste it into the Argusly plugin License key field in WordPress.</p>
            <div class="mt-3 rounded border border-amber-200 bg-surface px-3 py-2 font-mono text-sm text-textPrimary" id="plugin-license-key-value">{{ $generatedPluginLicenseKey }}</div>
            <button type="button" class="mt-3 rounded border border-border px-3 py-1.5 text-xs" onclick="navigator.clipboard.writeText(document.getElementById('plugin-license-key-value').innerText)">Copy license key</button>
        </div>
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        <div class="rounded-lg border border-border bg-surface p-4 md:col-span-2">
            <h2 class="text-sm font-semibold text-textPrimary">Site settings</h2>
            <form method="POST" action="{{ route('app.sites.update', $site) }}" class="mt-3 grid gap-3 md:grid-cols-3">
                @csrf
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Site name</label>
                    <input type="text" name="name" value="{{ old('name', $site->name) }}" class="w-full rounded border border-border bg-background px-2 py-2 text-sm" maxlength="120" required>
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Workspace</label>
                    <input type="text" value="{{ $workspace?->name ?? 'n/a' }}" class="w-full rounded border border-border bg-surfaceSubtle px-2 py-2 text-sm text-textSecondary" readonly>
                </div>
                <div class="flex items-end">
                    <button class="rounded border border-border px-3 py-2 text-sm">Save site name</button>
                </div>
            </form>
        </div>

        @can('manage-organization')
            <div class="rounded-lg border border-border bg-surface p-4 md:col-span-2">
                <h2 class="text-sm font-semibold text-textPrimary">Optimization settings</h2>
                <p class="mt-1 text-xs text-textSecondary">Per-site guardrails for safe automation. These settings only allow recommendation generation and refresh draft creation, never silent live changes.</p>
                <form method="POST" action="{{ route('app.sites.automation.update', $site) }}" class="mt-4 grid gap-3 lg:grid-cols-2">
                    @csrf
                    <label class="flex items-start gap-3 rounded-md border border-border bg-background px-3 py-2.5 text-sm">
                        <input type="hidden" name="automatic_recommendation_generation_enabled" value="0">
                        <input class="mt-0.5" type="checkbox" name="automatic_recommendation_generation_enabled" value="1" @checked(old('automatic_recommendation_generation_enabled', $automationSettings['automatic_recommendation_generation_enabled'] ?? true))>
                        <span>
                            <span class="block font-medium text-textPrimary">Automatic recommendation generation</span>
                            <span class="block text-xs text-textSecondary">Run bounded recommendation analysis after lifecycle events and during scheduled scans.</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-3 rounded-md border border-border bg-background px-3 py-2.5 text-sm">
                        <input type="hidden" name="smart_suggestions_enabled" value="0">
                        <input class="mt-0.5" type="checkbox" name="smart_suggestions_enabled" value="1" @checked(old('smart_suggestions_enabled', $automationSettings['smart_suggestions_enabled'] ?? true))>
                        <span>
                            <span class="block font-medium text-textPrimary">Smart suggestions</span>
                            <span class="block text-xs text-textSecondary">Automatically prepare internal-link suggestions for editorial review.</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-3 rounded-md border border-border bg-background px-3 py-2.5 text-sm">
                        <input type="hidden" name="automatic_refresh_draft_creation_enabled" value="0">
                        <input class="mt-0.5" type="checkbox" name="automatic_refresh_draft_creation_enabled" value="1" @checked(old('automatic_refresh_draft_creation_enabled', $automationSettings['automatic_refresh_draft_creation_enabled'] ?? false))>
                        <span>
                            <span class="block font-medium text-textPrimary">Automatic refresh draft creation</span>
                            <span class="block text-xs text-textSecondary">Create reviewable refresh drafts only for high-urgency recommendations. Published content stays untouched.</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-3 rounded-md border border-border bg-background px-3 py-2.5 text-sm">
                        <input type="hidden" name="localization_checks_enabled" value="0">
                        <input class="mt-0.5" type="checkbox" name="localization_checks_enabled" value="1" @checked(old('localization_checks_enabled', $automationSettings['localization_checks_enabled'] ?? true))>
                        <span>
                            <span class="block font-medium text-textPrimary">Localization checks</span>
                            <span class="block text-xs text-textSecondary">Mark multilingual review opportunities automatically without changing locale assignments.</span>
                        </span>
                    </label>
                    <div class="lg:col-span-2">
                        <button class="rounded border border-border px-3 py-2 text-sm">Save optimization settings</button>
                    </div>
                </form>
            </div>
        @endcan

        <div class="rounded-lg border border-border bg-surface p-4">
            <h2 class="text-sm font-semibold text-textPrimary">Site connection</h2>
            <p class="mt-1 text-xs text-textSecondary">Connector health, credential state, and verification actions for this site integration.</p>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-textSecondary">Status</dt><dd class="font-medium text-textPrimary">{{ $site->status }}</dd></div>
                <div class="flex justify-between"><dt class="text-textSecondary">Last seen</dt><dd class="text-textPrimary">{{ optional($site->last_seen_at)->toDateTimeString() ?? 'Never' }}</dd></div>
                <div class="flex justify-between"><dt class="text-textSecondary">Last heartbeat</dt><dd class="text-textPrimary">{{ optional($site->last_heartbeat_at)->toDateTimeString() ?? 'Never' }}</dd></div>
                <div class="flex justify-between"><dt class="text-textSecondary">Last healthcheck</dt><dd class="text-textPrimary">{{ optional($site->last_healthcheck_at)->toDateTimeString() ?? 'Never' }}</dd></div>
                <div class="flex justify-between"><dt class="text-textSecondary">Key status</dt><dd class="text-textPrimary">{{ $latestToken && ! $latestToken->revoked ? 'Active' : 'Revoked' }}</dd></div>
                <div class="flex justify-between"><dt class="text-textSecondary">Key last used</dt><dd class="text-textPrimary">{{ optional($latestToken?->last_used_at)->toDateTimeString() ?? 'Never' }}</dd></div>
                <div class="flex justify-between"><dt class="text-textSecondary">Scopes</dt><dd class="text-textPrimary">{{ implode(', ', $latestToken?->abilities ?? $latestToken?->scopes ?? []) ?: 'None' }}</dd></div>
                @if ($isWordPress)
                    <div class="flex justify-between"><dt class="text-textSecondary">WordPress version</dt><dd class="text-textPrimary">{{ $site->wp_version ?? 'Unknown' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-textSecondary">Plugin version</dt><dd class="text-textPrimary">{{ $site->plugin_version ?? 'Unknown' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-textSecondary">Update license</dt><dd class="text-textPrimary">{{ ($pluginLicenseKey ?? null) ? 'Active' : 'Not generated' }}</dd></div>
                @endif
                @if ($isLaravel)
                    <div class="flex justify-between"><dt class="text-textSecondary">Connector mode</dt><dd class="text-textPrimary">Laravel API connector</dd></div>
                @endif
                <div class="flex justify-between"><dt class="text-textSecondary">Last error</dt><dd class="text-textPrimary">{{ $site->last_error ?: 'None' }}</dd></div>
            </dl>

            <div class="mt-4 flex flex-wrap gap-2">
                @if ($isWordPress)
                    <a href="{{ route('app.sites.wordpress-plugin.download') }}" class="rounded border border-border px-3 py-1.5 text-xs">Download WP plugin</a>
                    <form method="POST" action="{{ route('app.sites.test-wordpress', $site) }}">@csrf<button class="rounded border border-border px-3 py-1.5 text-xs">Test connection</button></form>
                    <form method="POST" action="{{ route('app.sites.plugin-license-key.generate', $site) }}" onsubmit="return confirm('Generate a new WordPress update license key? The key will be shown once.');">
                        @csrf
                        <button class="rounded border border-border px-3 py-1.5 text-xs">{{ ($pluginLicenseKey ?? null) ? 'Rotate update license' : 'Generate update license' }}</button>
                    </form>
                @else
                    <a href="#laravel-connector-setup" class="rounded border border-border px-3 py-1.5 text-xs">Connector setup</a>
                    <form method="POST" action="{{ route('app.sites.test-laravel', $site) }}">@csrf<button class="rounded border border-border px-3 py-1.5 text-xs">Test connection</button></form>
                @endif
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface p-4">
            <h2 class="text-sm font-semibold text-textPrimary">Usage</h2>
            <p class="mt-1 text-xs text-textSecondary">Operational usage for {{ $siteUsage['period_label'] ?? now()->format('F Y') }}.</p>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-textSecondary">Briefs used this month</dt><dd class="text-textPrimary">{{ $siteUsage['briefs_count'] ?? 0 }}</dd></div>
                <div class="flex justify-between"><dt class="text-textSecondary">Drafts used this month</dt><dd class="text-textPrimary">{{ $siteUsage['drafts_count'] ?? 0 }}</dd></div>
                <div class="flex justify-between"><dt class="text-textSecondary">Pushes to WordPress this month</dt><dd class="text-textPrimary">{{ $isWordPress ? ($siteUsage['wp_pushes_count'] ?? 0) : 'n/a' }}</dd></div>
                <div class="flex justify-between"><dt class="text-textSecondary">Workspace brief quota</dt><dd class="text-textPrimary">{{ $usage['briefs_count'] ?? 0 }} / {{ ($limits['briefs_per_month'] ?? -1) < 0 ? 'Unlimited' : $limits['briefs_per_month'] }}</dd></div>
                <div class="flex justify-between"><dt class="text-textSecondary">Workspace draft quota</dt><dd class="text-textPrimary">{{ $usage['drafts_count'] ?? 0 }} / {{ ($limits['drafts_per_month'] ?? -1) < 0 ? 'Unlimited' : $limits['drafts_per_month'] }}</dd></div>
            </dl>
        </div>

        <div class="rounded-lg border border-border bg-surface p-4 md:col-span-2">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-textPrimary">Continuous optimization</h2>
                    <p class="mt-1 text-xs text-textSecondary">Scheduled scans surface bounded refresh and localization opportunities for this site. Nothing changes until an editor acts.</p>
                </div>
                <div class="text-xs text-textSecondary">
                    Last scan: {{ optional(data_get($optimizationOverview ?? [], 'last_scanned_at'))->diffForHumans() ?? 'Not yet run' }}
                </div>
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-3">
                <div class="rounded border border-border bg-background p-3">
                    <p class="text-xs text-textSecondary">Refresh opportunities</p>
                    <p class="mt-1 text-xl font-semibold text-textPrimary">{{ data_get($optimizationOverview ?? [], 'refresh_candidate_count', 0) }}</p>
                    <p class="mt-1 text-xs text-textSecondary">Content items that likely need an editorial refresh.</p>
                </div>
                <div class="rounded border border-border bg-background p-3">
                    <p class="text-xs text-textSecondary">Localization issues</p>
                    <p class="mt-1 text-xl font-semibold text-textPrimary">{{ data_get($optimizationOverview ?? [], 'localization_issue_count', 0) }}</p>
                    <p class="mt-1 text-xs text-textSecondary">Detected multilingual issues across the latest scheduled checks.</p>
                </div>
                <div class="rounded border border-border bg-background p-3">
                    <p class="text-xs text-textSecondary">Affected localized items</p>
                    <p class="mt-1 text-xl font-semibold text-textPrimary">{{ data_get($optimizationOverview ?? [], 'localized_content_count', 0) }}</p>
                    <p class="mt-1 text-xs text-textSecondary">Content items with localization recommendations.</p>
                </div>
            </div>

            @if (data_get($optimizationOverview ?? [], 'has_data'))
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <div class="rounded border border-border bg-background p-3">
                        <h3 class="text-sm font-medium text-textPrimary">Top refresh recommendations</h3>
                        <div class="mt-3 space-y-3">
                            @forelse ((array) data_get($optimizationOverview, 'top_refresh_candidates', []) as $candidate)
                                <div class="rounded border border-border bg-surface p-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="text-sm font-medium text-textPrimary">{{ $candidate['title'] ?? 'Untitled content' }}</p>
                                            <p class="mt-1 text-xs text-textSecondary">{{ $candidate['summary'] ?? 'Refresh recommendation available.' }}</p>
                                        </div>
                                        <div class="text-right text-xs text-textSecondary">
                                            <div>Score {{ $candidate['score'] ?? 0 }}</div>
                                            <div>{{ strtoupper((string) ($candidate['urgency'] ?? 'low')) }}</div>
                                        </div>
                                    </div>
                                    @if (! empty($candidate['reasons'] ?? []))
                                        <p class="mt-2 text-xs text-textSecondary">{{ implode(' · ', array_slice((array) ($candidate['reasons'] ?? []), 0, 3)) }}</p>
                                    @endif
                                    @if (! empty($candidate['href'] ?? null))
                                        <a href="{{ $candidate['href'] }}" class="mt-3 inline-flex rounded border border-border px-2.5 py-1.5 text-xs text-textPrimary">Open content</a>
                                    @endif
                                </div>
                            @empty
                                <p class="text-sm text-textSecondary">No scheduled refresh opportunities are available yet.</p>
                            @endforelse
                        </div>
                    </div>

                    <div class="rounded border border-border bg-background p-3">
                        <h3 class="text-sm font-medium text-textPrimary">Top localization recommendations</h3>
                        <div class="mt-3 space-y-3">
                            @forelse ((array) data_get($optimizationOverview, 'top_localization_items', []) as $candidate)
                                <div class="rounded border border-border bg-surface p-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="text-sm font-medium text-textPrimary">{{ $candidate['title'] ?? 'Untitled content' }}</p>
                                            <p class="mt-1 text-xs text-textSecondary">{{ $candidate['summary'] ?? 'Localization recommendation available.' }}</p>
                                        </div>
                                        <div class="text-xs text-textSecondary">{{ $candidate['issue_count'] ?? 0 }} issue(s)</div>
                                    </div>
                                    @if (! empty($candidate['recommendations'] ?? []))
                                        <p class="mt-2 text-xs text-textSecondary">{{ implode(' · ', array_slice((array) ($candidate['recommendations'] ?? []), 0, 3)) }}</p>
                                    @endif
                                    @if (! empty($candidate['href'] ?? null))
                                        <a href="{{ $candidate['href'] }}" class="mt-3 inline-flex rounded border border-border px-2.5 py-1.5 text-xs text-textPrimary">Open content</a>
                                    @endif
                                </div>
                            @empty
                                <p class="text-sm text-textSecondary">No scheduled localization issues are available yet.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            @else
                <div class="mt-4 rounded border border-dashed border-border bg-background px-4 py-3 text-sm text-textSecondary">
                    Scheduled scans have not produced site-level recommendations yet.
                </div>
            @endif
        </div>

        <div class="rounded-lg border border-border bg-surface p-4 md:col-span-2">
            <h2 class="text-sm font-semibold text-textPrimary">Content</h2>
            <p class="mt-1 text-xs text-textSecondary">Create, review, and publish content workflows for this site.</p>
            <div class="mt-4 flex flex-wrap gap-2">
                <a href="{{ route('app.content.index', ['site' => $site->id, 'inbox' => 'needs_brief']) }}" class="rounded border border-border px-3 py-1.5 text-xs {{ ($limits['can_generate_briefs'] ?? false) ? '' : 'pointer-events-none opacity-50' }}">Create content</a>
                <a href="{{ route('app.content.index', ['site' => $site->id]) }}" class="rounded border border-border px-3 py-1.5 text-xs">Open content</a>
                <a href="{{ route('app.content.index', ['site' => $site->id, 'status' => 'draft']) }}" class="rounded border border-border px-3 py-1.5 text-xs">View drafts</a>
                <a href="{{ route('app.content.automations.index', ['site' => $site->id, 'workspace' => $site->workspace_id]) }}" class="rounded border border-border px-3 py-1.5 text-xs">Automations</a>
                @if ($isWordPress)
                    <a href="{{ route('app.content.index', ['site' => $site->id, 'inbox' => 'ready_publish']) }}" class="rounded border border-border px-3 py-1.5 text-xs {{ ($limits['can_push_to_wp'] ?? false) ? '' : 'pointer-events-none opacity-50' }}">Push to WP</a>
                @endif
            </div>
        </div>

        @can('manage-organization')
            <div class="rounded-lg border border-rose-300/60 bg-rose-500/5 p-4 md:col-span-2">
                <h2 class="text-sm font-semibold text-rose-800">Danger zone</h2>
                <p class="mt-1 text-xs text-rose-700">Sensitive and destructive actions are isolated here to prevent accidental changes.</p>

                <div class="mt-4 space-y-3">
                    <details class="rounded-md border border-border bg-surface p-3">
                        <summary class="cursor-pointer text-sm font-medium text-textPrimary">Regenerate key</summary>
                        <p class="mt-2 text-xs text-textSecondary">This rotates credentials immediately. Existing integrations using the previous key will stop working until updated.</p>
                        <form method="POST" action="{{ route('app.sites.regenerate-key', $site) }}" class="mt-3" onsubmit="return confirm('Regenerate key for this site? Existing integrations using the current key will stop working.');">
                            @csrf
                            <button class="rounded border border-border px-3 py-1.5 text-xs">Confirm regenerate key</button>
                        </form>
                    </details>

                    <details class="rounded-md border border-border bg-surface p-3">
                        <summary class="cursor-pointer text-sm font-medium text-textPrimary">{{ $site->status === 'disabled' ? 'Enable site' : 'Disable site' }}</summary>
                        <p class="mt-2 text-xs text-textSecondary">
                            @if ($site->status === 'disabled')
                                Enabling will allow this site to receive and process integrations again.
                            @else
                                Disabling pauses integration activity for this site without deleting historical records.
                            @endif
                        </p>
                        <form method="POST" action="{{ route('app.sites.toggle', $site) }}" class="mt-3" onsubmit="return confirm('{{ $site->status === 'disabled' ? 'Enable this site?' : 'Disable this site? Integration activity will be paused.' }}');">
                            @csrf
                            <button class="rounded border border-border px-3 py-1.5 text-xs">Confirm {{ $site->status === 'disabled' ? 'enable site' : 'disable site' }}</button>
                        </form>
                    </details>

                    <details class="rounded-md border border-rose-300/60 bg-rose-500/5 p-3">
                        <summary class="cursor-pointer text-sm font-medium text-rose-800">Remove site</summary>
                        <p class="mt-2 text-xs text-rose-700">This permanently removes the site record and revokes tokens. If linked content exists, removal is blocked by policy.</p>
                        <form method="POST" action="{{ route('app.sites.destroy', $site) }}" class="mt-3" onsubmit="return confirm('Remove this site permanently? This cannot be undone.');">
                            @csrf
                            @method('DELETE')
                            <button class="rounded border border-rose-300 px-3 py-1.5 text-xs text-rose-800">Confirm remove site</button>
                        </form>
                    </details>
                </div>
            </div>
        @endcan

        @if ($isLaravel)
            <div id="laravel-connector-setup" class="rounded-lg border border-border bg-surface p-4 md:col-span-2">
                <h2 class="text-sm font-semibold text-textPrimary">Laravel connector setup</h2>
                <p class="mt-2 text-xs text-textSecondary">Configure your Laravel app to send briefs/drafts through the official Argusly connector.</p>
                <div class="mt-3 rounded border border-border bg-background p-3 text-xs text-textSecondary">
                    <div><code>composer require onygo/argusly-laravel-connector</code></div>
                    <div class="mt-2"><code>ARGUSLY_CONNECTOR_API_URL={{ config('argusly_connector.api.base_url', config('argusly_connector.api.base_url', 'https://api.argusly.com')) }}</code></div>
                    <div><code>ARGUSLY_CONNECTOR_API_KEY=&lt;generated_site_key&gt;</code></div>
                    <div><code>ARGUSLY_CONNECTOR_WORKSPACE_ID={{ $workspace?->id }}</code></div>
                </div>
                @include('app.sites.partials.setup-instructions', ['siteType' => $siteType])
            </div>
        @endif
    </div>
@endsection
