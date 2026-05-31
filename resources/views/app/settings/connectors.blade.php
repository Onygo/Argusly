<x-app.settings.layout :title="__('connectors.title')" :description="__('connectors.description')">
    @if (session('status'))
        <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    @if (session('connector_plain_token'))
        <div class="mb-5 rounded-lg border border-blue/20 bg-blue/5 px-4 py-3">
            <p class="text-sm font-semibold text-ink">{{ __('connectors.token') }}</p>
            <p class="mt-1 text-sm text-muted">{{ __('connectors.token_once') }}</p>
            <code class="mt-3 block overflow-x-auto rounded-lg bg-ink px-3 py-2 text-sm text-white">{{ session('connector_plain_token') }}</code>
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <p class="font-semibold">{{ __('connectors.could_not_save') }}</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
        <div class="space-y-4">
            @if ($installations->isEmpty())
                <x-dashboard.empty-state :title="__('connectors.none_registered')" :message="__('connectors.none_registered_message')" />
            @else
                @foreach ($installations as $installation)
                    <x-ui.card class="p-5">
                        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="text-base font-semibold text-ink">{{ $installation->name }}</h2>
                                    <x-ui.badge variant="blue">{{ $types[$installation->manifest?->type] ?? str($installation->manifest?->type)->headline() }}</x-ui.badge>
                                    <x-ui.badge variant="{{ $installation->status === 'active' ? 'success' : ($installation->status === 'unhealthy' ? 'dark' : 'default') }}">{{ str($installation->status)->headline() }}</x-ui.badge>
                                </div>
                                <p class="mt-2 text-sm text-muted">
                                    {{ $installation->manifest?->name ?? __('common.connector') }} {{ $installation->version ? 'v'.$installation->version->version : '' }}
                                    {{ $installation->brand ? ' · '.$installation->brand->name : ' · '.__('common.account_level') }}
                                </p>
                            </div>

                            <form method="POST" action="{{ route('settings.connectors.update', $installation) }}" class="flex flex-wrap items-end gap-2">
                                @csrf
                                @method('PATCH')
                                <label class="text-xs font-semibold text-muted">
                                    {{ __('common.status') }}
                                    <select name="status" class="mt-1 rounded-lg border-line text-sm">
                                        @foreach (\App\Models\ConnectorInstallation::STATUSES as $status)
                                            <option value="{{ $status }}" @selected($installation->status === $status)>{{ str($status)->headline() }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="text-xs font-semibold text-muted">
                                    {{ __('common.health') }}
                                    <input name="last_health_status" value="{{ $installation->last_health_check['status'] ?? '' }}" class="mt-1 w-28 rounded-lg border-line text-sm" placeholder="ok">
                                </label>
                                <x-ui.button size="sm" variant="secondary">{{ __('common.update') }}</x-ui.button>
                            </form>
                        </div>

                        <div class="mt-5 grid gap-4 md:grid-cols-4">
                            <x-settings.field :label="__('connectors.property')" :value="$installation->property?->name" :empty="__('common.none')" />
                            <x-settings.field :label="__('connectors.channel')" :value="$installation->channel?->name" :empty="__('common.none')" />
                            <x-settings.field label="Endpoint" :value="$installation->endpoint_url" :empty="__('common.not_set')" />
                            <x-settings.field :label="__('connectors.tokens')" :value="$installation->tokens->whereNull('revoked_at')->count().' active'" />
                            <x-settings.field :label="__('connectors.last_health_check')" :value="$installation->last_health_checked_at?->diffForHumans()" empty="Not checked" />
                            <x-settings.field :label="__('connectors.health_status')" :value="$installation->last_health_check['status'] ?? null" :empty="__('common.unknown')" />
                            <x-settings.field :label="__('connectors.installed')" :value="$installation->installed_at?->diffForHumans()" :empty="__('common.unknown')" />
                            <x-settings.field :label="__('connectors.logs')" :value="$installation->logs->count().' recent'" />
                        </div>

                        @if ($installation->enabled_capabilities)
                            <div class="mt-5 flex flex-wrap gap-2">
                                @foreach ($installation->enabled_capabilities as $capability)
                                    <x-ui.badge>{{ str($capability)->headline() }}</x-ui.badge>
                                @endforeach
                            </div>
                        @endif

                        @if ($installation->tokens->isNotEmpty())
                            <div class="mt-5 divide-y divide-line rounded-lg border border-line">
                                @foreach ($installation->tokens as $token)
                                    <div class="flex flex-col justify-between gap-3 px-3 py-3 md:flex-row md:items-center">
                                        <div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <p class="text-sm font-semibold text-ink">{{ $token->name }}</p>
                                                <x-ui.badge variant="{{ $token->revoked_at ? 'dark' : ($token->expires_at && $token->expires_at->isPast() ? 'default' : 'success') }}">
                                                    {{ $token->revoked_at ? __('common.revoked') : ($token->expires_at && $token->expires_at->isPast() ? 'Expired' : __('common.active')) }}
                                                </x-ui.badge>
                                            </div>
                                            <p class="mt-1 text-xs text-muted">
                                                {{ collect($token->abilities ?? [])->join(', ') ?: 'No abilities' }}
                                                · {{ __('common.last_used') }} {{ $token->last_used_at?->diffForHumans() ?? __('common.never') }}
                                                @if ($token->expires_at)
                                                    · Expires {{ $token->expires_at->diffForHumans() }}
                                                @endif
                                            </p>
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            <form method="POST" action="{{ route('settings.connectors.tokens.rotate', $token) }}">
                                                @csrf
                                                <x-ui.button size="sm" variant="secondary">{{ __('connectors.rotate') }}</x-ui.button>
                                            </form>
                                            @if (! $token->revoked_at)
                                                <form method="POST" action="{{ route('settings.connectors.tokens.revoke', $token) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <x-ui.button size="sm" variant="secondary">{{ __('connectors.revoke') }}</x-ui.button>
                                                </form>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if ($installation->logs->isNotEmpty())
                            <div class="mt-5 divide-y divide-line rounded-lg border border-line">
                                @foreach ($installation->logs as $log)
                                    <div class="grid gap-2 px-3 py-2 text-sm md:grid-cols-[10rem_8rem_1fr]">
                                        <span class="font-semibold text-ink">{{ $log->occurred_at?->diffForHumans() }}</span>
                                        <span class="text-muted">{{ str($log->event)->after('connector.')->headline() }}</span>
                                        <span class="text-muted">{{ $log->message ?? str($log->status)->headline() }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </x-ui.card>
                @endforeach
            @endif
        </div>

        <div class="space-y-6">
            <x-ui.card class="p-5">
                <h2 class="text-base font-semibold text-ink">{{ __('connectors.register_connector') }}</h2>
                <form method="POST" action="{{ route('settings.connectors.store') }}" class="mt-5 space-y-4">
                    @csrf

                <label class="block text-sm font-semibold text-ink">
                    {{ __('common.connector') }}
                    <select name="connector_version_id" class="mt-1 w-full rounded-lg border-line text-sm" required>
                        @foreach ($manifests as $manifest)
                            @foreach ($manifest->versions as $version)
                                <option value="{{ $version->id }}">{{ $manifest->name }} v{{ $version->version }}</option>
                            @endforeach
                        @endforeach
                    </select>
                </label>

                <label class="block text-sm font-semibold text-ink">
                    {{ __('common.name') }}
                    <input name="name" class="mt-1 w-full rounded-lg border-line text-sm" placeholder="Production WordPress" required>
                </label>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm font-semibold text-ink">
                        {{ __('connectors.scope') }}
                        <select name="scope" class="mt-1 w-full rounded-lg border-line text-sm">
                            @if ($brand)
                                <option value="brand" selected>{{ __('common.current_brand') }}</option>
                            @endif
                            <option value="account" @selected(! $brand)>{{ __('common.account') }}</option>
                        </select>
                    </label>
                    <label class="block text-sm font-semibold text-ink">
                        {{ __('common.status') }}
                        <select name="status" class="mt-1 w-full rounded-lg border-line text-sm">
                            <option value="pending">{{ __('common.pending') }}</option>
                            <option value="active">{{ __('common.active') }}</option>
                            <option value="disabled">{{ __('common.disabled') }}</option>
                        </select>
                    </label>
                </div>

                <label class="block text-sm font-semibold text-ink">
                    {{ __('connectors.endpoint_url') }}
                    <input name="endpoint_url" type="url" class="mt-1 w-full rounded-lg border-line text-sm" placeholder="https://example.com">
                </label>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm font-semibold text-ink">
                        {{ __('connectors.property') }}
                        <select name="property_id" class="mt-1 w-full rounded-lg border-line text-sm">
                            <option value="">{{ __('common.none') }}</option>
                            @foreach ($properties as $property)
                                <option value="{{ $property->id }}">{{ $property->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-sm font-semibold text-ink">
                        {{ __('connectors.channel') }}
                        <select name="channel_id" class="mt-1 w-full rounded-lg border-line text-sm">
                            <option value="">{{ __('common.none') }}</option>
                            @foreach ($channels as $channel)
                                <option value="{{ $channel->id }}">{{ $channel->name }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <fieldset>
                    <legend class="text-sm font-semibold text-ink">{{ __('connectors.capabilities') }}</legend>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        @foreach ($capabilities as $capability)
                            <label class="flex items-center gap-2 text-sm text-muted">
                                <input type="checkbox" name="enabled_capabilities[]" value="{{ $capability }}" class="rounded border-line text-ink" checked>
                                <span>{{ str($capability)->headline() }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                    <x-ui.button class="w-full">{{ __('connectors.register_connector') }}</x-ui.button>
                </form>
            </x-ui.card>

            <x-ui.card class="p-5">
                <h2 class="text-base font-semibold text-ink">Create API token</h2>
                <form method="POST" action="{{ route('settings.connectors.tokens.store') }}" class="mt-5 space-y-4">
                    @csrf

                <label class="block text-sm font-semibold text-ink">
                    Connector installation
                    <select name="connector_installation_id" class="mt-1 w-full rounded-lg border-line text-sm" required>
                        @foreach ($installations as $installation)
                            <option value="{{ $installation->id }}">{{ $installation->name }}{{ $installation->channel ? ' · '.$installation->channel->name : '' }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block text-sm font-semibold text-ink">
                    Token name
                    <input name="name" class="mt-1 w-full rounded-lg border-line text-sm" placeholder="Production connector" required>
                </label>

                <label class="block text-sm font-semibold text-ink">
                    Expires at
                    <input name="expires_at" type="datetime-local" class="mt-1 w-full rounded-lg border-line text-sm">
                </label>

                <fieldset>
                    <legend class="text-sm font-semibold text-ink">Abilities</legend>
                    <div class="mt-2 grid gap-2">
                        @foreach ($tokenAbilities as $ability)
                            <label class="flex items-center gap-2 text-sm text-muted">
                                <input type="checkbox" name="abilities[]" value="{{ $ability }}" class="rounded border-line text-ink" checked>
                                <span>{{ $ability }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                    <x-ui.button class="w-full">Create token</x-ui.button>
                </form>
            </x-ui.card>
        </div>
    </div>
</x-app.settings.layout>
