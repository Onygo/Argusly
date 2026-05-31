<x-app.settings.layout title="Publishing channel settings" description="External publishing targets prepared for future WordPress, Laravel, social, email and API publishing.">
    @if (! $brand)
        <x-dashboard.empty-state title="No brand selected" message="Select a brand before configuring publishing channels." />
    @elseif ($channels->isEmpty())
        <x-dashboard.empty-state title="No publishing channels configured" message="Channels will appear here after connector setup is implemented." />
    @else
        <div class="space-y-4">
            @foreach ($channels as $channel)
                <x-ui.card class="p-5">
                    <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-base font-semibold text-ink">{{ $channel->name }}</h2>
                                <x-ui.badge variant="blue">{{ str($channel->provider)->headline() }}</x-ui.badge>
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
                </x-ui.card>
            @endforeach
        </div>
    @endif

    <div class="mt-6">
        <x-dashboard.empty-state title="No OAuth or publishing yet" message="Connection, credential refresh and publish dispatch flows will be added in later connector passes." />
    </div>
</x-app.settings.layout>
