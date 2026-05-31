<x-app.settings.layout title="Google Search Console" description="Search Console foundations for search performance, content lifecycle scoring, AI visibility correlation, topic authority and campaign reporting.">
    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="space-y-4">
            @if ($sites->isEmpty())
                <x-dashboard.empty-state title="No Search Console sites mapped" message="Search Console site mappings will appear here after OAuth and site discovery are implemented." />
            @else
                @foreach ($sites as $site)
                    <x-ui.card class="p-5">
                        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="text-base font-semibold text-ink">{{ $site->site_url }}</h2>
                                    <x-ui.badge variant="{{ $site->status === 'connected' ? 'success' : ($site->status === 'error' ? 'dark' : 'default') }}">{{ str($site->status)->headline() }}</x-ui.badge>
                                </div>
                                <p class="mt-2 text-sm text-muted">{{ $site->metadata['permission_level'] ?? 'Permission level pending OAuth' }}</p>
                            </div>
                            <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Last synced {{ $site->last_synced_at?->diffForHumans() ?? 'never' }}</p>
                        </div>

                        <div class="mt-5 grid gap-4 md:grid-cols-4">
                            <x-settings.field label="Integration" :value="$site->integrationConnection?->name" empty="Not connected" />
                            <x-settings.field label="Snapshots" :value="$site->query_snapshots_count" />
                            <x-settings.field label="Site type" :value="$site->metadata['site_type'] ?? null" empty="Pending OAuth" />
                            <x-settings.field label="Verification" :value="$site->metadata['verification_state'] ?? null" empty="Unknown" />
                        </div>
                    </x-ui.card>
                @endforeach
            @endif
        </div>

        <div class="space-y-4">
            <x-ui.card class="p-5">
                @php
                    $googleNeedsAttention = $connections->first(fn ($connection) => in_array($connection->status, ['error', 'expired', 'revoked'], true));
                @endphp

                @if (session('google_status'))
                    <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                        {{ session('google_status') }}
                    </div>
                @endif

                @if (session('google_error'))
                    <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                        {{ session('google_error') }}
                    </div>
                @endif

                @if ($googleNeedsAttention)
                    <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        Reconnect Google integration to restore Search Console sync.
                        @if ($googleNeedsAttention->metadata['token_error_message'] ?? null)
                            <span class="block text-xs text-amber-800">{{ $googleNeedsAttention->metadata['token_error_message'] }}</span>
                        @endif
                    </div>
                @endif

                <h2 class="text-base font-semibold text-ink">Google OAuth</h2>
                <p class="mt-2 text-sm leading-6 text-muted">{{ $provider->oauthConfigured() ? 'OAuth is ready for Search Console read access.' : 'Google OAuth credentials are not configured yet.' }}</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    @if ($provider->oauthConfigured())
                        <a href="{{ route('settings.integrations.google.connect') }}" class="inline-flex items-center justify-center rounded-md border border-line px-3 py-2 text-sm font-medium text-ink transition hover:border-ink">
                            Connect Google
                        </a>
                    @else
                        <button type="button" disabled class="inline-flex cursor-not-allowed items-center justify-center rounded-md border border-line px-3 py-2 text-sm font-medium text-muted">
                            Connect Google
                        </button>
                    @endif
                </div>
            </x-ui.card>

            <x-ui.card class="p-5">
                <h2 class="text-base font-semibold text-ink">Prepared use cases</h2>
                <div class="mt-4 space-y-3 text-sm text-muted">
                    <p>Content lifecycle scoring with search demand and decay signals.</p>
                    <p>AI visibility correlation against organic queries and pages.</p>
                    <p>Topic authority rollups across related content assets.</p>
                    <p>Recommendations and campaign reporting for search-led distribution.</p>
                </div>
            </x-ui.card>

            <x-ui.card class="p-5">
                <h2 class="text-base font-semibold text-ink">Discover Search Console sites</h2>
                <div class="mt-4 space-y-4">
                    @forelse ($discovery as $result)
                        <div class="rounded-md border border-line p-3">
                            <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-start">
                                <div>
                                    <p class="text-sm font-semibold text-ink">{{ $result['connection']->name }}</p>
                                    <p class="mt-1 text-xs text-muted">{{ $result['connection']->brand ? $result['connection']->brand->name : $account->name }}</p>
                                </div>
                                <x-ui.badge>{{ str($result['connection']->status)->headline() }}</x-ui.badge>
                            </div>

                            @if ($result['error'])
                                <p class="mt-3 text-sm text-red-700">{{ $result['error'] }}</p>
                            @elseif ($result['sites']->isEmpty())
                                <p class="mt-3 text-sm text-muted">No verified Search Console sites are available for this Google connection.</p>
                            @else
                                <form method="POST" action="{{ route('settings.integrations.search-console.sites.store') }}" class="mt-4 space-y-3">
                                    @csrf
                                    <input type="hidden" name="integration_connection_id" value="{{ $result['connection']->id }}">

                                    @foreach ($result['sites'] as $site)
                                        <label class="block rounded-md border border-line p-3">
                                            <div class="flex gap-3">
                                                <input type="checkbox" name="selected[]" value="{{ $site['site_url'] }}" class="mt-1 h-4 w-4 rounded border-line">
                                                <div>
                                                    <p class="text-sm font-medium text-ink">{{ $site['site_url'] }}</p>
                                                    <p class="mt-1 text-xs text-muted">{{ $site['permission_level'] }} · {{ str($site['site_type'])->headline() }}</p>
                                                </div>
                                            </div>
                                        </label>
                                    @endforeach

                                    <button type="submit" class="inline-flex items-center justify-center rounded-md border border-line px-3 py-2 text-sm font-medium text-ink transition hover:border-ink">
                                        Save selected sites
                                    </button>
                                </form>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-muted">Connect Google before discovering Search Console sites.</p>
                    @endforelse
                </div>
            </x-ui.card>

            <x-ui.card class="p-5">
                <h2 class="text-base font-semibold text-ink">Connections</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($connections as $connection)
                        <div class="flex flex-col justify-between gap-3 rounded-md border border-line bg-panel p-3 sm:flex-row sm:items-center">
                            <div>
                                <p class="text-sm font-semibold text-ink">{{ $connection->name }}</p>
                                <p class="mt-1 text-xs text-muted">{{ $connection->integration?->name ?? 'Google' }}{{ $connection->brand ? ' · '.$connection->brand->name : '' }} · {{ str($connection->status)->headline() }}</p>
                                @if (in_array($connection->status, ['error', 'expired', 'revoked'], true))
                                    <p class="mt-2 text-xs text-red-700">{{ $connection->metadata['token_error_message'] ?? 'Google connection needs attention.' }}</p>
                                @endif
                            </div>
                            <form method="POST" action="{{ route('settings.integrations.google.disconnect', $connection) }}">
                                @csrf
                                <button type="submit" class="inline-flex items-center justify-center rounded-md border border-line px-3 py-2 text-sm font-medium text-ink transition hover:border-ink">
                                    Disconnect
                                </button>
                            </form>
                        </div>
                    @empty
                        <p class="text-sm text-muted">No Google or Search Console connection exists yet.</p>
                    @endforelse
                </div>
            </x-ui.card>
        </div>
    </div>
</x-app.settings.layout>
