<x-app.settings.layout title="Property settings" description="Websites, apps and external surfaces available to the current brand.">
    @if (! $brand)
        <x-dashboard.empty-state title="No brand selected" message="Select a brand before configuring properties." />
    @elseif ($properties->isEmpty())
        <x-dashboard.empty-state title="No properties configured" message="Brand properties will appear here before WordPress, Laravel and other publishing integrations are connected." />
    @else
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ($properties as $property)
                <x-ui.card class="p-5">
                    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-base font-semibold text-ink">{{ $property->name }}</h2>
                                <x-ui.badge>{{ str($property->type)->replace('_', ' ')->headline() }}</x-ui.badge>
                            </div>
                            <p class="mt-2 break-words text-sm text-muted">{{ $property->url }}</p>
                        </div>
                        <x-ui.badge variant="{{ $property->status === 'active' ? 'success' : 'default' }}">{{ str($property->status)->headline() }}</x-ui.badge>
                    </div>

                    <div class="mt-5 grid gap-4 sm:grid-cols-3">
                        <x-settings.field label="Language" :value="$property->primary_language" empty="Not set" />
                        <x-settings.field label="Channels" :value="$property->publishing_channels_count" />
                        <x-settings.field label="Assets" :value="$property->content_assets_count" />
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    @endif

    <div class="mt-6">
        <x-dashboard.empty-state title="Creation placeholder" message="Property creation and verification will be added with connector setup. No external system is contacted on this screen." />
    </div>
</x-app.settings.layout>
