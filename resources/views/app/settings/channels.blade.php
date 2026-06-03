<x-app.settings.layout title="Publishing channel settings" description="External publishing targets prepared for future WordPress, Laravel, social, email and API publishing.">
    @if (session('status'))
        <div class="mb-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-5 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <p class="font-semibold">Could not update channel</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (! $brand)
        <x-dashboard.empty-state title="No brand selected" message="Select a brand before configuring publishing channels." />
    @elseif ($channels->isEmpty())
        <x-dashboard.empty-state title="No publishing channels configured" message="Channels will appear here after connector setup is implemented." />
    @else
        <div class="space-y-4">
            @foreach ($channels as $channel)
                @php
                    $connector = $channel->connectorInstallation;
                    $availableConnectors = $connectorInstallations
                        ->filter(fn ($installation) => $installation->manifest?->type === $channel->provider)
                        ->filter(fn ($installation) => $installation->property_id === null || $installation->property_id === $channel->property_id)
                        ->values();
                    $canPublish = $connector
                        && $connector->status === 'active'
                        && $connector->revoked_at === null
                        && in_array('publish_content', $connector->enabled_capabilities ?? [], true);
                @endphp
                <x-ui.card class="p-5">
                    <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-base font-semibold text-ink">{{ $channel->name }}</h2>
                                <x-ui.badge variant="blue">{{ str($channel->provider)->headline() }}</x-ui.badge>
                                <x-ui.badge variant="{{ $canPublish ? 'success' : 'dark' }}">
                                    {{ $canPublish ? 'Connector ready' : 'Publishing blocked' }}
                                </x-ui.badge>
                            </div>
                            <p class="mt-2 text-sm text-muted">{{ $channel->property?->name ?? 'Brand-level channel' }}</p>
                        </div>
                        <x-ui.badge variant="{{ $channel->status === 'active' ? 'success' : ($channel->status === 'failed' ? 'dark' : 'default') }}">{{ str($channel->status)->headline() }}</x-ui.badge>
                    </div>

                    <div class="mt-5 grid gap-4 md:grid-cols-3">
                        <x-settings.field label="Provider" :value="str($channel->provider)->headline()" />
                        <x-settings.field label="Property" :value="$channel->property?->name" empty="Brand-level" />
                        <x-settings.field label="Last connected" :value="$channel->last_connected_at?->diffForHumans()" empty="Not connected" />
                    </div>

                    <div class="mt-5 border-t border-line pt-5">
                        <form method="POST" action="{{ route('settings.channels.update', $channel) }}" class="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                            @csrf
                            @method('PATCH')

                            <label class="block text-sm font-semibold text-ink">
                                Connector installation
                                <select name="connector_installation_id" class="mt-1 w-full rounded-md border-line text-sm">
                                    <option value="">No connector selected</option>
                                    @foreach ($availableConnectors as $installation)
                                        <option value="{{ $installation->id }}" @selected($channel->connector_installation_id === $installation->id)>
                                            {{ $installation->name }} · {{ $installation->manifest?->name }}{{ $installation->version ? ' v'.$installation->version->version : '' }} · {{ str($installation->status)->headline() }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>

                            <x-ui.button size="sm" variant="secondary">Update connector</x-ui.button>
                        </form>

                        @if ($connector)
                            <div class="mt-5 grid gap-4 md:grid-cols-4">
                                <x-settings.field label="Connector status" :value="str($connector->status)->headline()" />
                                <x-settings.field label="Connector version" :value="$connector->version?->version" empty="Unknown" />
                                <x-settings.field label="Last health check" :value="$connector->last_health_checked_at?->diffForHumans()" empty="Not checked" />
                                <x-settings.field label="Health status" :value="$connector->last_health_check['status'] ?? null" empty="Unknown" />
                            </div>

                            <div class="mt-4 flex flex-wrap gap-2">
                                @forelse ($connector->enabled_capabilities ?? [] as $capability)
                                    <x-ui.badge variant="{{ $capability === 'publish_content' ? 'success' : 'default' }}">{{ str($capability)->headline() }}</x-ui.badge>
                                @empty
                                    <x-ui.badge variant="dark">No capabilities enabled</x-ui.badge>
                                @endforelse
                            </div>
                        @else
                            <p class="mt-3 text-sm text-muted">Select an active connector with Publish Content capability before publishing through this channel.</p>
                        @endif
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    @endif
</x-app.settings.layout>
