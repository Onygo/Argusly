@extends('layouts.app', ['title' => 'Developer'])

@section('content')
    @php
        use App\View\Presenters\PublicationDestinationPresenter;

        $tab = in_array($activeTab, ['overview', 'destinations', 'keys', 'webhooks', 'usage', 'docs'], true) ? $activeTab : 'overview';
        $section = in_array($tab, ['webhooks', 'docs'], true) ? $tab : 'api';
        $sectionNavItems = [
            [
                'id' => 'api',
                'label' => 'API',
                'url' => route('app.developer.api'),
                'active' => $section === 'api',
            ],
            [
                'id' => 'webhooks',
                'label' => 'Webhooks',
                'url' => route('app.developer.webhooks'),
                'active' => $section === 'webhooks',
            ],
            [
                'id' => 'docs',
                'label' => 'Docs',
                'url' => route('app.developer.docs'),
                'active' => $section === 'docs',
            ],
        ];
    @endphp

    <div class="space-y-6">
        <header class="space-y-2">
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Developer</h1>
            <p class="text-textSecondary">Manage API-only and hybrid integrations for this workspace.</p>
            <div class="flex flex-wrap items-center gap-2 text-xs text-textSecondary">
                <span class="inline-flex items-center rounded-md border border-border bg-surface px-2.5 py-1">Workspace: {{ $workspace->display_name }}</span>
                <span class="inline-flex items-center rounded-md border border-border bg-surface px-2.5 py-1">Organization: {{ auth()->user()->organization->name }}</span>
            </div>
        </header>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        @if ($errors->any())
            <div class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-800">
                <p class="font-medium">Some actions could not be completed.</p>
                <ul class="mt-1 list-disc pl-5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($createdApiKeySecret)
            <div class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3">
                <p class="text-sm font-semibold text-amber-900">New API key (shown once)</p>
                <p class="mt-2 rounded bg-white px-3 py-2 font-mono text-xs text-amber-900">{{ $createdApiKeySecret }}</p>
                <p class="mt-2 text-xs text-amber-800">Store this key in your secret manager now.</p>
            </div>
        @endif

        <x-app.section-nav :items="$sectionNavItems" />

        @if ($tab === 'overview')
            <div class="grid gap-6 lg:grid-cols-3">
                <div class="rounded-lg border border-border bg-background p-4">
                    <p class="text-xs uppercase tracking-wide text-textSecondary">Destinations</p>
                    <p class="mt-2 text-3xl font-semibold text-textPrimary">{{ $destinations->count() }}</p>
                    <p class="mt-1 text-xs text-textSecondary">Content endpoints available for connected CMS, API-only, or hybrid.</p>
                </div>
                <div class="rounded-lg border border-border bg-background p-4">
                    <p class="text-xs uppercase tracking-wide text-textSecondary">Active API keys</p>
                    <p class="mt-2 text-3xl font-semibold text-textPrimary">{{ (int) ($credentialSummary['active_workspace_api_keys'] ?? $apiKeys->whereNull('revoked_at')->count()) }}</p>
                    <p class="mt-1 text-xs text-textSecondary">Scoped integration tokens for server-to-server access.</p>
                </div>
                <div class="rounded-lg border border-border bg-background p-4">
                    <p class="text-xs uppercase tracking-wide text-textSecondary">Webhooks</p>
                    <p class="mt-2 text-3xl font-semibold text-textPrimary">{{ $webhooks->where('is_active', true)->count() }}</p>
                    <p class="mt-1 text-xs text-textSecondary">Active outbound webhook subscriptions.</p>
                </div>
            </div>
        @endif

        @if ($tab === 'destinations')
            <x-settings.section-card title="Create destination" description="Set up where generated content should be routed.">
                <form method="POST" action="{{ route('app.developer.destinations.store') }}" class="grid gap-3 md:grid-cols-2">
                    @csrf
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Name</label>
                        <input name="name" value="{{ old('name') }}" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" required>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Type</label>
                        <select name="type" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                            <option value="api" @selected(old('type') === 'api')>API</option>
                            <option value="wordpress" @selected(old('type') === 'wordpress')>WordPress</option>
                            <option value="laravel" @selected(old('type') === 'laravel')>Laravel</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Environment</label>
                        <select name="environment" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                            <option value="production" @selected(old('environment', 'production') === 'production')>Production</option>
                            <option value="staging" @selected(old('environment') === 'staging')>Staging</option>
                            <option value="development" @selected(old('environment') === 'development')>Development</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Default language</label>
                        <input name="default_language" value="{{ old('default_language', 'en') }}" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Laravel base URL</label>
                        <input name="config[laravel_connector][base_url]" value="{{ old('config.laravel_connector.base_url') }}" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" placeholder="https://client.example.com">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Sync endpoint path</label>
                        <input name="config[laravel_connector][sync_endpoint]" value="{{ old('config.laravel_connector.sync_endpoint', '/wp-json/argusly/v1/content/sync') }}" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" placeholder="/wp-json/argusly/v1/content/sync">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Site ID</label>
                        <input name="config[laravel_connector][site_id]" value="{{ old('config.laravel_connector.site_id') }}" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Shared API key</label>
                        <input name="config[laravel_connector][api_key]" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Laravel mode</label>
                        <select name="config[laravel_connector][mode]" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                            <option value="hosted_views" @selected(old('config.laravel_connector.mode', 'hosted_views') === 'hosted_views')>hosted_views</option>
                            <option value="headless" @selected(old('config.laravel_connector.mode') === 'headless')>headless</option>
                        </select>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-textSecondary">
                        <input type="hidden" name="tracking_enabled" value="0">
                        <input type="checkbox" name="tracking_enabled" value="1" checked>
                        Tracking enabled
                    </label>
                    <label class="flex items-center gap-2 text-sm text-textSecondary">
                        <input type="hidden" name="seo_audit_enabled" value="0">
                        <input type="checkbox" name="seo_audit_enabled" value="1" checked>
                        SEO audits enabled
                    </label>
                    <label class="flex items-center gap-2 text-sm text-textSecondary">
                        <input type="hidden" name="config[laravel_connector][enabled]" value="0">
                        <input type="checkbox" name="config[laravel_connector][enabled]" value="1" @checked(old('config.laravel_connector.enabled', true))>
                        Laravel connector enabled
                    </label>
                    <div class="md:col-span-2">
                        <button class="inline-flex items-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Create destination</button>
                    </div>
                </form>
            </x-settings.section-card>

            <x-settings.section-card title="Existing destinations" description="Update status and naming.">
                <div class="space-y-3">
                    @forelse($destinations as $destination)
                        @php($destinationPresenter = PublicationDestinationPresenter::for($destination))
                        <form method="POST" action="{{ route('app.developer.destinations.update', $destination) }}" class="grid gap-2 rounded-lg border border-border bg-background p-3 md:grid-cols-4">
                            @csrf
                            <input name="name" value="{{ $destination->name }}" class="rounded-md border border-border bg-surface px-3 py-2 text-sm">
                            <input value="{{ $destinationPresenter->label() }}" class="rounded-md border border-border bg-surfaceSubtle px-3 py-2 text-sm" readonly>
                            <select name="status" class="rounded-md border border-border bg-surface px-3 py-2 text-sm">
                                <option value="active" @selected(($destination->status?->value ?? $destination->status) === 'active')>active</option>
                                <option value="disabled" @selected(($destination->status?->value ?? $destination->status) === 'disabled')>disabled</option>
                            </select>
                            <div class="flex flex-wrap items-center gap-2">
                                <button class="inline-flex items-center justify-center rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Save</button>
                                @if ($destinationPresenter->supportsConnectionTest())
                                    <button formaction="{{ route('app.developer.destinations.test-connection', $destination) }}" class="inline-flex items-center justify-center rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Test connection</button>
                                @endif
                            </div>
                            <div class="md:col-span-4 flex flex-wrap gap-2 pb-1">
                                @foreach ($destinationPresenter->capabilitySummary() as $capability)
                                    <span class="inline-flex items-center rounded border px-2 py-1 text-[11px] {{ $capability['supported'] ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-border bg-surfaceSubtle text-textSecondary' }}">
                                        {{ $capability['label'] }}
                                    </span>
                                @endforeach
                            </div>
                            @if ($destinationPresenter->supportsLaravelConfig())
                                <div class="md:col-span-2">
                                    <label class="mb-1 block text-xs text-textSecondary">Base URL</label>
                                    <input name="config[laravel_connector][base_url]" value="{{ data_get($destination->sanitizedConfig(), 'laravel_connector.base_url') }}" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs text-textSecondary">Sync endpoint path</label>
                                    <input name="config[laravel_connector][sync_endpoint]" value="{{ data_get($destination->sanitizedConfig(), 'laravel_connector.sync_endpoint', '/wp-json/argusly/v1/content/sync') }}" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs text-textSecondary">Site ID</label>
                                    <input name="config[laravel_connector][site_id]" value="{{ data_get($destination->sanitizedConfig(), 'laravel_connector.site_id') }}" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs text-textSecondary">Shared API key</label>
                                    <input name="config[laravel_connector][api_key]" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" placeholder="{{ data_get($destination->sanitizedConfig(), 'laravel_connector.has_api_key') ? 'Stored securely. Leave blank to keep.' : 'Set shared API key' }}">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs text-textSecondary">Laravel mode</label>
                                    <select name="config[laravel_connector][mode]" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                                        <option value="hosted_views" @selected(data_get($destination->sanitizedConfig(), 'laravel_connector.mode', 'hosted_views') === 'hosted_views')>hosted_views</option>
                                        <option value="headless" @selected(data_get($destination->sanitizedConfig(), 'laravel_connector.mode') === 'headless')>headless</option>
                                    </select>
                                </div>
                                <label class="flex items-center gap-2 text-sm text-textSecondary">
                                    <input type="hidden" name="config[laravel_connector][enabled]" value="0">
                                    <input type="checkbox" name="config[laravel_connector][enabled]" value="1" @checked(data_get($destination->sanitizedConfig(), 'laravel_connector.enabled', true))>
                                    Connector enabled
                                </label>
                                <div class="md:col-span-4 rounded-md border border-border bg-surfaceSubtle px-3 py-2 text-xs text-textSecondary">
                                    <div>Sync URL: {{ data_get($destination->sanitizedConfig(), 'laravel_connector.sync_url', 'n/a') }}</div>
                                    <div>Health URL: {{ data_get($destination->sanitizedConfig(), 'laravel_connector.health_url', 'n/a') }}</div>
                                    <div>Connector mode: {{ data_get($destination->sanitizedConfig(), 'laravel_connector.mode', 'hosted_views') }}</div>
                                    <div>Latest sync: {{ $destination->latestSyncAttempt?->status ?? 'n/a' }}@if($destination->latestSyncAttempt?->response_status) · HTTP {{ $destination->latestSyncAttempt->response_status }}@endif</div>
                                    @if (!empty($destination->latestSyncAttempt?->error_message))
                                        <div class="text-rose-700">Last error: {{ $destination->latestSyncAttempt->error_message }}</div>
                                    @endif
                                </div>
                            @else
                                <div class="md:col-span-4 rounded-md border border-border bg-surfaceSubtle px-3 py-2 text-xs text-textSecondary">
                                    Destination capabilities and config are resolved by destination type. No extra settings are required for {{ strtolower($destinationPresenter->label()) }} here yet.
                                </div>
                            @endif
                        </form>
                    @empty
                        <x-settings.empty-state title="No destinations yet" description="Create your first API or hybrid destination." />
                    @endforelse
                </div>
            </x-settings.section-card>
        @endif

        @if ($tab === 'keys')
            <x-settings.section-card title="Create API key" description="Scoped key for server-to-server integrations.">
                <form method="POST" action="{{ route('app.developer.api-keys.store') }}" class="space-y-3">
                    @csrf
                    <div class="grid gap-3 md:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary">Key name</label>
                            <input name="name" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" required>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary">Destination (optional)</label>
                            <select name="content_destination_id" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                                <option value="">All destinations</option>
                                @foreach($destinations as $destination)
                                    <option value="{{ $destination->id }}">{{ $destination->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary">Expires at (optional)</label>
                            <input type="datetime-local" name="expires_at" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                        </div>
                    </div>
                    <div>
                        <p class="mb-2 text-xs text-textSecondary">Scopes</p>
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach($availableScopes as $scope)
                                <label class="flex items-center gap-2 rounded border border-border bg-background px-2 py-1 text-sm">
                                    <input type="checkbox" name="scopes[]" value="{{ $scope }}">
                                    <span class="font-mono text-xs">{{ $scope }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <button class="inline-flex items-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Create key</button>
                </form>
            </x-settings.section-card>

            <x-settings.section-card title="Existing keys" description="Revoke compromised or unused keys.">
                <div class="space-y-2">
                    @forelse($apiKeys as $apiKey)
                        <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-background px-3 py-2">
                            <div>
                                <p class="text-sm font-medium text-textPrimary">{{ $apiKey->name }} <span class="font-mono text-xs text-textSecondary">({{ $apiKey->key_prefix }}...)</span></p>
                                <p class="text-xs text-textSecondary">Scopes: {{ implode(', ', (array) $apiKey->scopes) ?: 'none' }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="rounded border border-border px-2 py-1 text-xs {{ $apiKey->revoked_at ? 'text-rose-700' : 'text-emerald-700' }}">{{ $apiKey->revoked_at ? 'revoked' : 'active' }}</span>
                                @if(!$apiKey->revoked_at)
                                    <form method="POST" action="{{ route('app.developer.api-keys.revoke', $apiKey) }}">
                                        @csrf
                                        <button class="rounded-md border border-border px-3 py-1 text-sm text-textPrimary hover:bg-surfaceSubtle">Revoke</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @empty
                        <x-settings.empty-state title="No API keys yet" description="Create your first scoped key for integrations." />
                    @endforelse
                </div>
            </x-settings.section-card>

            <x-settings.section-card title="Connected integration credentials" description="Visibility for legacy and site-linked credentials that are still in use.">
                <div class="space-y-2">
                    @forelse($linkedCredentials as $credential)
                        <div class="rounded-lg border border-border bg-background px-3 py-2">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <p class="text-sm font-medium text-textPrimary">
                                        {{ $credential['name'] }}
                                        <span class="font-mono text-xs text-textSecondary">({{ $credential['identifier'] }})</span>
                                    </p>
                                    <p class="text-xs text-textSecondary">
                                        {{ $credential['type_label'] }} · {{ $credential['origin_label'] }}
                                    </p>
                                    <p class="text-xs text-textSecondary">
                                        {{ $credential['scope_label'] }}
                                        @if (!empty($credential['scopes']) && is_array($credential['scopes']))
                                            · Scopes: {{ implode(', ', $credential['scopes']) }}
                                        @endif
                                    </p>
                                    @if(!empty($credential['manage_hint']))
                                        <p class="mt-1 text-xs text-amber-700">{{ $credential['manage_hint'] }}</p>
                                    @endif
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    @if(!empty($credential['legacy_badge']))
                                        <span class="rounded border border-amber-300 px-2 py-1 text-xs text-amber-800">{{ $credential['legacy_label'] ?? 'Legacy' }}</span>
                                    @endif
                                    <span class="rounded border border-border px-2 py-1 text-xs {{ ($credential['status'] ?? '') === 'active' ? 'text-emerald-700' : 'text-textSecondary' }}">
                                        {{ $credential['status_label'] ?? strtoupper((string) ($credential['status'] ?? 'unknown')) }}
                                    </span>
                                    @if(!empty($credential['manage_url']))
                                        <a href="{{ $credential['manage_url'] }}" class="rounded-md border border-border px-3 py-1 text-sm text-textPrimary hover:bg-surfaceSubtle">Open source</a>
                                    @endif
                                </div>
                            </div>
                            <p class="mt-2 text-[11px] text-textSecondary">
                                Created: {{ !empty($credential['created_at']) ? \Illuminate\Support\Carbon::parse($credential['created_at'])->format('Y-m-d H:i') : 'Unknown' }}
                                · Last used: {{ !empty($credential['last_used_at']) ? \Illuminate\Support\Carbon::parse($credential['last_used_at'])->format('Y-m-d H:i') : 'Unknown' }}
                            </p>
                        </div>
                    @empty
                        <x-settings.empty-state title="No linked credentials found" description="Site-linked and legacy credentials will appear here when detected." />
                    @endforelse
                </div>
            </x-settings.section-card>
        @endif

        @if ($tab === 'webhooks')
            <x-settings.section-card title="Create webhook" description="Subscribe to lifecycle events.">
                <form method="POST" action="{{ route('app.developer.webhooks.store') }}" class="space-y-3">
                    @csrf
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary">Name</label>
                            <input name="name" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" required>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary">Target URL</label>
                            <input name="target_url" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" placeholder="https://example.com/webhooks/argusly" required>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary">Signing secret</label>
                            <input name="secret" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" required>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary">Destination (optional)</label>
                            <select name="content_destination_id" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                                <option value="">All destinations</option>
                                @foreach($destinations as $destination)
                                    <option value="{{ $destination->id }}">{{ $destination->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <p class="mb-2 text-xs text-textSecondary">Events</p>
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach(['brief.created', 'draft.generation.started', 'draft.generation.completed', 'draft.generation.failed', 'draft.translated', 'seo_audit.completed', 'seo_audit.failed'] as $event)
                                <label class="flex items-center gap-2 rounded border border-border bg-background px-2 py-1 text-sm">
                                    <input type="checkbox" name="events[]" value="{{ $event }}" checked>
                                    <span class="font-mono text-xs">{{ $event }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <button class="inline-flex items-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Create webhook</button>
                </form>
            </x-settings.section-card>

            <x-settings.section-card title="Existing webhooks" description="Toggle active status or delete endpoints.">
                <div class="space-y-2">
                    @forelse($webhooks as $webhook)
                        <div class="rounded-lg border border-border bg-background p-3">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-medium text-textPrimary">{{ $webhook->name }}</p>
                                    <p class="text-xs text-textSecondary">{{ $webhook->target_url }}</p>
                                    <p class="mt-1 text-xs text-textSecondary">Events: {{ implode(', ', (array) $webhook->events) }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <form method="POST" action="{{ route('app.developer.webhooks.update', $webhook) }}" class="flex items-center gap-2">
                                        @csrf
                                        <input type="hidden" name="name" value="{{ $webhook->name }}">
                                        <input type="hidden" name="is_active" value="{{ $webhook->is_active ? 0 : 1 }}">
                                        <button class="rounded-md border border-border px-3 py-1 text-sm text-textPrimary hover:bg-surfaceSubtle">{{ $webhook->is_active ? 'Disable' : 'Enable' }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('app.developer.webhooks.destroy', $webhook) }}" onsubmit="return confirm('Delete this webhook?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="rounded-md border border-rose-300 px-3 py-1 text-sm text-rose-700 hover:bg-rose-50">Delete</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @empty
                        <x-settings.empty-state title="No webhooks yet" description="Create webhook subscriptions for integration events." />
                    @endforelse
                </div>
            </x-settings.section-card>
        @endif

        @if ($tab === 'usage')
            <x-settings.section-card title="Usage logs" description="Most recent integration API requests.">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-border text-left text-xs text-textSecondary">
                                <th class="px-2 py-2">Time</th>
                                <th class="px-2 py-2">Method</th>
                                <th class="px-2 py-2">Path</th>
                                <th class="px-2 py-2">Status</th>
                                <th class="px-2 py-2">Duration</th>
                                <th class="px-2 py-2">IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($usageLogs as $log)
                                <tr class="border-b border-border/60">
                                    <td class="px-2 py-2 text-xs text-textSecondary">{{ $log->requested_at?->format('Y-m-d H:i:s') }}</td>
                                    <td class="px-2 py-2 font-mono text-xs text-textPrimary">{{ $log->method }}</td>
                                    <td class="px-2 py-2 font-mono text-xs text-textPrimary">{{ $log->path }}</td>
                                    <td class="px-2 py-2 text-textPrimary">{{ $log->response_status }}</td>
                                    <td class="px-2 py-2 text-textPrimary">{{ $log->duration_ms }} ms</td>
                                    <td class="px-2 py-2 text-xs text-textSecondary">{{ $log->ip_address }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-2 py-6 text-sm text-textSecondary">No request logs yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-settings.section-card>
        @endif

        @if ($tab === 'docs')
            <x-settings.section-card title="API Documentation" description="Complete API reference and downloadable specifications.">
                <div class="space-y-4">
                    <div class="flex flex-wrap gap-3">
                        <a href="{{ route('app.developer.docs.index') }}" class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse hover:bg-primary/90">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            API Reference
                        </a>
                        <a href="{{ route('app.developer.docs.downloads') }}" class="inline-flex items-center gap-2 rounded-md border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            Downloads
                        </a>
                    </div>
                </div>
            </x-settings.section-card>

            <x-settings.section-card title="Quick Reference" description="Essential integration details.">
                <div class="space-y-2 text-sm">
                    <p><span class="font-medium text-textPrimary">API base path:</span> <code class="rounded bg-surfaceSubtle px-1 py-0.5">https://api.argusly.com/api/v1</code></p>
                    <p><span class="font-medium text-textPrimary">Authentication:</span> <code class="rounded bg-surfaceSubtle px-1 py-0.5">Authorization: Bearer &lt;api_key&gt;</code></p>
                    <p><span class="font-medium text-textPrimary">Webhook signature:</span> HMAC SHA-256 in <code class="rounded bg-surfaceSubtle px-1 py-0.5">X-Argusly-Signature</code></p>
                    <p><span class="font-medium text-textPrimary">Async polling:</span> <code class="rounded bg-surfaceSubtle px-1 py-0.5">GET /api/v1/operations/{operation}</code></p>
                </div>
            </x-settings.section-card>
        @endif
    </div>
@endsection
