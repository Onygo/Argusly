<x-app.settings.layout title="Property settings" description="Websites, apps and external surfaces available to the current brand.">
    @if (! $brand)
        <x-dashboard.empty-state title="No brand selected" message="Select a brand before configuring properties." />
    @else
        <x-dashboard.section title="Create property" description="Create a brand-owned surface before connecting analytics, search or publishing infrastructure.">
            <form method="POST" action="{{ route('settings.properties.store') }}" class="grid gap-4 md:grid-cols-2">
                @csrf
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Name</span>
                    <input name="name" value="{{ old('name') }}" required placeholder="Marketing website" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Type</span>
                    <select name="type" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        @foreach ($types as $type)
                            <option value="{{ $type }}">{{ str($type)->replace('_', ' ')->headline() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block md:col-span-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">URL</span>
                    <input name="url" value="{{ old('url') }}" required placeholder="https://example.com" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Primary language</span>
                    <input name="primary_language" value="{{ old('primary_language', $brand->default_content_language) }}" placeholder="en" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Status</span>
                    <select name="status" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}">{{ str($status)->headline() }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="md:col-span-2">
                    <x-ui.button type="submit">Create property</x-ui.button>
                </div>
            </form>
        </x-dashboard.section>

        <div class="mt-6">
            @if ($properties->isEmpty())
                <x-dashboard.empty-state title="No properties configured" message="Create a property to connect content, visibility, analytics and publishing data for this brand." />
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

                            <form method="POST" action="{{ route('settings.properties.update', $property) }}" class="mt-5 grid gap-3 border-t border-line pt-5 md:grid-cols-2">
                                @csrf
                                @method('PATCH')
                                <input name="name" value="{{ old('name', $property->name) }}" required class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                <select name="type" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                    @foreach ($types as $type)
                                        <option value="{{ $type }}" @selected($property->type === $type)>{{ str($type)->replace('_', ' ')->headline() }}</option>
                                    @endforeach
                                </select>
                                <input name="url" value="{{ old('url', $property->url) }}" required class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink md:col-span-2">
                                <input name="primary_language" value="{{ old('primary_language', $property->primary_language) }}" placeholder="en" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                <select name="status" class="rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                    @foreach ($statuses as $status)
                                        <option value="{{ $status }}" @selected($property->status === $status)>{{ str($status)->headline() }}</option>
                                    @endforeach
                                </select>
                                <div class="md:col-span-2">
                                    <x-ui.button type="submit" size="sm" variant="secondary">Save property</x-ui.button>
                                </div>
                            </form>
                        </x-ui.card>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</x-app.settings.layout>
