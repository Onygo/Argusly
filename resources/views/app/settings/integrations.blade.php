<x-app.settings.layout title="Integration settings" description="Connected integration foundations scoped to the current account and brand.">
    <div class="mb-6">
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

        @php
            $googleNeedsAttention = $googleConnections->first(fn ($connection) => in_array($connection->status, ['error', 'expired', 'revoked'], true));
        @endphp

        @if ($googleNeedsAttention)
            <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                Reconnect Google integration to restore GA4 and Search Console sync.
                @if ($googleNeedsAttention->metadata['token_error_message'] ?? null)
                    <span class="block text-xs text-amber-800">{{ $googleNeedsAttention->metadata['token_error_message'] }}</span>
                @endif
            </div>
        @endif

        <div class="grid gap-4 lg:grid-cols-2">
        <x-ui.card class="p-5">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                <div>
                    <h2 class="text-base font-semibold text-ink">LinkedIn</h2>
                    <p class="mt-1 text-sm text-muted">Personal profile publishing is prepared. Organization and page publishing is staged for a later OAuth pass.</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($linkedinProvider->scopes() as $scope)
                            <x-ui.badge>{{ $scope }}</x-ui.badge>
                        @endforeach
                    </div>
                </div>
                <a href="{{ route('settings.integrations.linkedin') }}" class="inline-flex items-center justify-center rounded-md border border-line px-3 py-2 text-sm font-medium text-ink transition hover:border-ink">
                    Manage LinkedIn
                </a>
            </div>
        </x-ui.card>
        <x-ui.card class="p-5">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                <div>
                    <h2 class="text-base font-semibold text-ink">Google Analytics 4</h2>
                    <p class="mt-1 text-sm text-muted">GA4 property mapping is prepared for content performance, lifecycle scoring, recommendations and campaign reporting.</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-ui.badge>analytics.readonly</x-ui.badge>
                        <x-ui.badge>content performance</x-ui.badge>
                        <x-ui.badge>campaign reporting</x-ui.badge>
                    </div>
                </div>
                <a href="{{ route('settings.integrations.google-analytics') }}" class="inline-flex items-center justify-center rounded-md border border-line px-3 py-2 text-sm font-medium text-ink transition hover:border-ink">
                    Manage GA4
                </a>
            </div>
        </x-ui.card>
        <x-ui.card class="p-5">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                <div>
                    <h2 class="text-base font-semibold text-ink">Google Search Console</h2>
                    <p class="mt-1 text-sm text-muted">Search Console site mapping is prepared for search performance, topic authority, AI visibility correlation and campaign reporting.</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-ui.badge>webmasters.readonly</x-ui.badge>
                        <x-ui.badge>SEO performance</x-ui.badge>
                        <x-ui.badge>topic authority</x-ui.badge>
                    </div>
                </div>
                <a href="{{ route('settings.integrations.search-console') }}" class="inline-flex items-center justify-center rounded-md border border-line px-3 py-2 text-sm font-medium text-ink transition hover:border-ink">
                    Manage Search Console
                </a>
            </div>
        </x-ui.card>
        </div>
    </div>

    @if ($connections->isEmpty())
        <x-dashboard.empty-state title="No integrations connected" message="Connected integrations will appear here after OAuth and provider setup are implemented." />
    @else
        <div class="space-y-4">
            @foreach ($connections as $connection)
                <x-ui.card class="p-5">
                    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                        <div>
                            <h2 class="text-base font-semibold text-ink">{{ $connection->name }}</h2>
                            <p class="mt-1 text-sm text-muted">{{ $connection->integration?->name ?? 'Integration' }}{{ $connection->brand ? ' · '.$connection->brand->name : '' }}</p>
                        </div>
                        <x-ui.badge variant="success">{{ str($connection->status)->headline() }}</x-ui.badge>
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    @endif

    <div class="mt-6">
        <x-ui.card class="p-5">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                <div>
                    <h2 class="text-base font-semibold text-ink">Google OAuth</h2>
                    <p class="mt-1 text-sm text-muted">{{ $googleProvider->oauthConfigured() ? 'Google OAuth credentials are configured.' : 'Google OAuth credentials are not configured yet.' }}</p>
                </div>
                @if ($googleProvider->oauthConfigured())
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
    </div>
</x-app.settings.layout>
