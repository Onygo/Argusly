<x-app.settings.layout title="Brand settings" description="Brand profiles available inside the current account.">
    <div class="space-y-4">
        @forelse ($brands as $item)
            <x-ui.card class="p-6">
                <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-semibold text-ink">{{ $item->name }}</h2>
                            @if ($brand?->is($item))
                                <x-ui.badge variant="blue">Current</x-ui.badge>
                            @endif
                        </div>
                        <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">{{ $item->description ?: 'No brand description configured yet.' }}</p>
                    </div>
                    <x-ui.badge>{{ str($item->status)->headline() }}</x-ui.badge>
                </div>

                <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <x-settings.field label="Slug" :value="$item->slug" />
                    <x-settings.field label="Website URL" :value="$item->website_url ?? $item->domain" empty="No website configured" />
                    <x-settings.field label="Market" :value="$item->market" />
                    <x-settings.field label="Legacy language" :value="$item->language" />
                    <x-settings.field label="Default content language" :value="$item->default_content_language" />
                    <x-settings.field label="Enabled content languages" :value="is_array($item->enabled_content_languages) ? implode(', ', $item->enabled_content_languages) : 'All content languages'" />
                </div>

                <form method="POST" action="{{ route('settings.brands.update', $item) }}" class="mt-5 grid gap-4 border-t border-line pt-5 md:grid-cols-2">
                    @csrf
                    @method('PATCH')

                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Name</span>
                        <input name="name" value="{{ old('name', $item->name) }}" required class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Slug</span>
                        <input name="slug" value="{{ old('slug', $item->slug) }}" required class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Domain</span>
                        <input name="domain" value="{{ old('domain', $item->domain) }}" placeholder="example.com" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Website URL</span>
                        <input name="website_url" value="{{ old('website_url', $item->website_url) }}" placeholder="https://example.com" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Market</span>
                        <input name="market" value="{{ old('market', $item->market) }}" placeholder="Netherlands" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Status</span>
                        <select name="status" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                            @foreach (['active', 'inactive', 'archived'] as $status)
                                <option value="{{ $status }}" @selected(old('status', $item->status) === $status)>{{ str($status)->headline() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Language</span>
                        <input name="language" value="{{ old('language', $item->language) }}" placeholder="en" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Default content language</span>
                        <input name="default_content_language" value="{{ old('default_content_language', $item->default_content_language) }}" placeholder="en" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                    <label class="block md:col-span-2">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Enabled content languages</span>
                        <input name="enabled_content_languages" value="{{ old('enabled_content_languages', is_array($item->enabled_content_languages) ? implode(', ', $item->enabled_content_languages) : '') }}" placeholder="en, nl" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                    </label>
                    <label class="block md:col-span-2">
                        <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Description</span>
                        <textarea name="description" rows="3" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">{{ old('description', $item->description) }}</textarea>
                    </label>
                    <div class="md:col-span-2">
                        <x-ui.button type="submit" size="sm">Save brand</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @empty
            <x-dashboard.empty-state title="No brands" message="This account does not have any brands yet." />
        @endforelse
    </div>
</x-app.settings.layout>
