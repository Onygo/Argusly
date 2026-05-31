<x-app.settings.layout title="LinkedIn integration" description="Personal LinkedIn profile connections for the current account and brand.">
    <div class="space-y-6">
        <x-ui.card class="p-5">
            @if (session('linkedin_status'))
                <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                    {{ session('linkedin_status') }}
                </div>
            @endif

            @if (session('linkedin_error'))
                <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                    {{ session('linkedin_error') }}
                </div>
            @endif

            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                <div>
                    <h2 class="text-base font-semibold text-ink">Status</h2>
                    <p class="mt-1 text-sm text-muted">
                        {{ $provider->oauthConfigured() ? 'OAuth ready' : 'OAuth credentials not configured yet' }}
                    </p>
                    <p class="mt-2 text-sm text-muted">
                        {{ $brand ? 'Brand scope: '.$brand->name : 'Account scope: '.$account->name }}
                    </p>
                </div>
                <x-ui.badge>{{ $provider->oauthConfigured() ? 'Ready' : 'Needs credentials' }}</x-ui.badge>
            </div>
        </x-ui.card>

        <x-ui.card class="p-5">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                <div>
                    <h2 class="text-base font-semibold text-ink">Personal profile connection</h2>
                    <p class="mt-1 text-sm text-muted">Connect a personal LinkedIn profile for member publishing.</p>
                </div>
                @if ($provider->oauthConfigured())
                    <a href="{{ route('settings.integrations.linkedin.connect') }}" class="inline-flex items-center justify-center rounded-md border border-line px-3 py-2 text-sm font-medium text-ink transition hover:border-ink">
                        Connect LinkedIn
                    </a>
                @else
                    <button type="button" disabled class="inline-flex cursor-not-allowed items-center justify-center rounded-md border border-line px-3 py-2 text-sm font-medium text-muted">
                        Connect LinkedIn
                    </button>
                @endif
            </div>
        </x-ui.card>

        <x-ui.card class="p-5">
            <h2 class="text-base font-semibold text-ink">Permissions</h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach ($provider->scopes() as $scope)
                    <div class="rounded-md border border-line p-3">
                        <p class="text-sm font-medium text-ink">{{ $scope }}</p>
                        <p class="mt-1 text-xs text-muted">Required for personal profile identity, email access, or member publishing.</p>
                    </div>
                @endforeach
            </div>

            <h3 class="mt-5 text-sm font-semibold text-ink">Future organization/page scopes</h3>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($provider->futureScopes() as $scope)
                    <x-ui.badge>{{ $scope }}</x-ui.badge>
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.card class="p-5">
            <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-start">
                <div>
                    <h2 class="text-base font-semibold text-ink">Connected profiles</h2>
                    <p class="mt-1 text-sm text-muted">Personal profile connections available to this account and brand.</p>
                </div>
                <x-ui.badge>{{ $connections->where('status', 'active')->count() }} connected</x-ui.badge>
            </div>

            @if ($connections->isEmpty())
                <div class="mt-4 rounded-md border border-dashed border-line p-4 text-sm text-muted">
                    No LinkedIn profiles connected yet.
                </div>
            @else
                <div class="mt-4 space-y-3">
                    @foreach ($connections as $connection)
                        <div class="flex flex-col justify-between gap-3 rounded-md border border-line p-4 sm:flex-row sm:items-center">
                            <div>
                                <div class="flex items-center gap-3">
                                    @if ($connection->metadata['avatar_url'] ?? null)
                                        <img src="{{ $connection->metadata['avatar_url'] }}" alt="" class="h-9 w-9 rounded-full object-cover">
                                    @endif
                                    <div>
                                        <p class="text-sm font-medium text-ink">{{ $connection->provider_account_name ?? $connection->name }}</p>
                                        <p class="mt-1 text-xs text-muted">{{ $connection->brand ? $connection->brand->name : $account->name }} · {{ str($connection->status)->headline() }}</p>
                                    </div>
                                </div>
                                @if (in_array($connection->status, ['error', 'expired'], true))
                                    <p class="mt-2 text-xs text-red-700">{{ $connection->metadata['token_error_message'] ?? $connection->metadata['error_message'] ?? 'LinkedIn profile needs attention.' }}</p>
                                @elseif ($connection->metadata['profile_url'] ?? null)
                                    <a href="{{ $connection->metadata['profile_url'] }}" target="_blank" rel="noreferrer" class="mt-2 inline-flex text-xs font-medium text-ink underline">
                                        View LinkedIn profile
                                    </a>
                                @endif
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if (in_array($connection->status, ['error', 'expired'], true) && $provider->oauthConfigured())
                                    <a href="{{ route('settings.integrations.linkedin.connect') }}" class="inline-flex items-center justify-center rounded-md border border-line px-3 py-2 text-sm font-medium text-ink transition hover:border-ink">
                                        Reconnect
                                    </a>
                                @endif
                                <form method="POST" action="{{ route('settings.integrations.linkedin.disconnect', $connection) }}">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center justify-center rounded-md border border-line px-3 py-2 text-sm font-medium text-ink transition hover:border-ink">
                                        Disconnect
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-ui.card>

        <x-ui.card class="p-5">
            <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                <div>
                    <h2 class="text-base font-semibold text-ink">Pages</h2>
                    <p class="mt-1 text-sm font-medium text-ink">Organization publishing requires LinkedIn approval</p>
                    <p class="mt-1 text-sm text-muted">LinkedIn pages will appear here after organization scopes and page roles are approved.</p>
                </div>
                <x-ui.badge>Approval required</x-ui.badge>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach (['r_organization_social', 'w_organization_social'] as $scope)
                    <div class="rounded-md border border-line p-3">
                        <p class="text-sm font-medium text-ink">{{ $scope }}</p>
                        <p class="mt-1 text-xs text-muted">
                            {{ collect($connections)->contains(fn ($connection) => in_array($scope, $connection->scopes ?? [], true)) ? 'Granted on at least one connection' : 'Not granted' }}
                        </p>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 rounded-md border border-dashed border-line p-4 text-sm text-muted">
                Placeholder list of pages. Reconnect LinkedIn after LinkedIn approves organization social scopes, then validate page roles before enabling publishing.
            </div>
        </x-ui.card>
    </div>
</x-app.settings.layout>
