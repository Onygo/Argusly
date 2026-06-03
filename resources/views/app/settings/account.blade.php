<x-app.settings.layout :title="__('settings.account')" description="Basic account configuration for the current tenant.">
    <div class="grid gap-6 lg:grid-cols-[1fr_0.8fr]">
        <x-dashboard.section :title="__('common.account')">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-settings.field :label="__('common.name')" :value="$account->name" />
                <x-settings.field label="Slug" :value="$account->slug" />
                <x-settings.field :label="__('common.status')" :value="str($account->status)->headline()" />
                <x-settings.field :label="__('settings.ui_locale')" :value="$account->default_locale" />
                <x-settings.field label="Default content language" :value="$account->default_content_language" />
                <x-settings.field :label="__('dashboard.current_brand')" :value="$brand?->name" :empty="__('dashboard.no_brand_selected')" />
            </div>
        </x-dashboard.section>

        <x-dashboard.section title="Basic settings JSON">
            @if (blank($account->settings))
                <x-dashboard.empty-state title="No settings configured" message="Account-level settings JSON is available for future product preferences." />
            @else
                <pre class="overflow-x-auto rounded-md bg-ink p-4 text-xs leading-6 text-white">{{ json_encode($account->settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            @endif
        </x-dashboard.section>
    </div>
</x-app.settings.layout>
