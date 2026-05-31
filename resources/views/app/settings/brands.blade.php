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

                <div class="mt-5">
                    <x-dashboard.empty-state title="Competitors placeholder" message="Competitor tracking will be configured in a later module." />
                </div>
            </x-ui.card>
        @empty
            <x-dashboard.empty-state title="No brands" message="This account does not have any brands yet." />
        @endforelse
    </div>
</x-app.settings.layout>
