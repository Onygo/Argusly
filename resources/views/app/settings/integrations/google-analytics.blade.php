<x-app.settings.layout title="Google Analytics 4" description="GA4 foundations for content performance, lifecycle scoring, recommendations and campaign reporting.">
    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="space-y-4">
            @if ($properties->isEmpty())
                <x-dashboard.empty-state title="No GA4 properties mapped" message="GA4 property mappings will appear here after OAuth and property discovery are implemented." />
            @else
                @foreach ($properties as $ga4Property)
                    <x-ui.card class="p-5">
                        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="text-base font-semibold text-ink">{{ $ga4Property->display_name }}</h2>
                                    <x-ui.badge variant="{{ $ga4Property->status === 'connected' ? 'success' : ($ga4Property->status === 'error' ? 'dark' : 'default') }}">{{ str($ga4Property->status)->headline() }}</x-ui.badge>
                                </div>
                                <p class="mt-2 text-sm text-muted">{{ $ga4Property->website_url ?? $ga4Property->property?->url ?? 'No website URL mapped' }}</p>
                            </div>
                            <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Last synced {{ $ga4Property->last_synced_at?->diffForHumans() ?? 'never' }}</p>
                        </div>

                        <div class="mt-5 grid gap-4 md:grid-cols-4">
                            <x-settings.field label="Integration" :value="$ga4Property->integrationConnection?->name" empty="Not connected" />
                            <x-settings.field label="Brand property" :value="$ga4Property->property?->name" empty="Unmapped" />
                            <x-settings.field label="Snapshots" :value="$ga4Property->metric_snapshots_count" />
                            <x-settings.field label="GA4 property ID" :value="$ga4Property->metadata['property_id'] ?? null" empty="Pending OAuth" />
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
                        Reconnect Google integration to restore GA4 sync.
                        @if ($googleNeedsAttention->metadata['token_error_message'] ?? null)
                            <span class="block text-xs text-amber-800">{{ $googleNeedsAttention->metadata['token_error_message'] }}</span>
                        @endif
                    </div>
                @endif

                <h2 class="text-base font-semibold text-ink">Google OAuth</h2>
                <p class="mt-2 text-sm leading-6 text-muted">{{ $provider->oauthConfigured() ? 'OAuth is ready for GA4 read access.' : 'Google OAuth credentials are not configured yet.' }}</p>
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
                    <p>Content performance by content asset.</p>
                    <p>Lifecycle scoring performance inputs.</p>
                    <p>Recommendations for declining or under-distributed content.</p>
                    <p>Campaign reporting across assigned content.</p>
                </div>
            </x-ui.card>

            <x-ui.card class="p-5">
                <h2 class="text-base font-semibold text-ink">Discover GA4 properties</h2>
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
                            @elseif ($result['accounts']->isEmpty() || $result['accounts']->every(fn ($analyticsAccount) => $analyticsAccount['properties']->isEmpty()))
                                <p class="mt-3 text-sm text-muted">No GA4 properties are available for this Google connection.</p>
                            @else
                                <form method="POST" action="{{ route('settings.integrations.google-analytics.properties.store') }}" class="mt-4 space-y-3">
                                    @csrf
                                    <input type="hidden" name="integration_connection_id" value="{{ $result['connection']->id }}">

                                    @foreach ($result['accounts'] as $analyticsAccount)
                                        <div>
                                            <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ $analyticsAccount['display_name'] }}</p>
                                            <div class="mt-2 space-y-2">
                                                @foreach ($analyticsAccount['properties'] as $property)
                                                    <label class="block rounded-md border border-line p-3">
                                                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                            <div class="flex gap-3">
                                                                <input type="checkbox" name="selected[]" value="{{ $property['name'] }}" class="mt-1 h-4 w-4 rounded border-line">
                                                                <div>
                                                                    <p class="text-sm font-medium text-ink">{{ $property['display_name'] }}</p>
                                                                    <p class="mt-1 text-xs text-muted">{{ $property['name'] }}</p>
                                                                    <p class="mt-1 text-xs text-muted">{{ $property['website_url'] ?? 'No website URL reported' }}</p>
                                                                </div>
                                                            </div>
                                                            <select name="property_map[{{ $property['name'] }}]" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                                                <option value="">No brand property</option>
                                                                @foreach ($brandProperties as $brandProperty)
                                                                    <option value="{{ $brandProperty->id }}">{{ $brandProperty->name }}</option>
                                                                @endforeach
                                                            </select>
                                                        </div>
                                                    </label>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach

                                    <button type="submit" class="inline-flex items-center justify-center rounded-md border border-line px-3 py-2 text-sm font-medium text-ink transition hover:border-ink">
                                        Save selected properties
                                    </button>
                                </form>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-muted">Connect Google before discovering GA4 properties.</p>
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
                        <p class="text-sm text-muted">No Google connection exists yet.</p>
                    @endforelse
                </div>
            </x-ui.card>
        </div>
    </div>
</x-app.settings.layout>
