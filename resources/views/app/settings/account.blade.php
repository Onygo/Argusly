<x-app.settings.layout :title="__('settings.account')" description="Basic account configuration for the current tenant.">
    <div class="grid gap-6 lg:grid-cols-[1fr_0.8fr]">
        <x-dashboard.section :title="__('common.account')">
            <form method="POST" action="{{ route('settings.account.update') }}" class="grid gap-4 sm:grid-cols-2">
                @csrf
                @method('PATCH')

                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('common.name') }}</span>
                    <input name="name" value="{{ old('name', $account->name) }}" required class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                </label>
                <x-settings.field label="Slug" :value="$account->slug" />
                <x-settings.field :label="__('common.status')" :value="str($account->status)->headline()" />
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ __('settings.ui_locale') }}</span>
                    <input name="default_locale" value="{{ old('default_locale', $account->default_locale) }}" placeholder="en" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Default content language</span>
                    <input name="default_content_language" value="{{ old('default_content_language', $account->default_content_language) }}" placeholder="en" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Timezone</span>
                    <input name="timezone" value="{{ old('timezone', $account->settings['timezone'] ?? '') }}" placeholder="Europe/Amsterdam" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                </label>
                <x-settings.field :label="__('dashboard.current_brand')" :value="$brand?->name" :empty="__('dashboard.no_brand_selected')" />

                <div class="sm:col-span-2">
                    <x-ui.button type="submit">Save workspace</x-ui.button>
                </div>
            </form>
        </x-dashboard.section>

        <x-dashboard.section title="Workspace metadata">
            @if (blank($account->settings))
                <x-dashboard.empty-state title="No metadata configured" message="Workspace metadata appears here after account-level preferences are saved." />
            @else
                <pre class="overflow-x-auto rounded-md bg-ink p-4 text-xs leading-6 text-white">{{ json_encode($account->settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            @endif
        </x-dashboard.section>
    </div>
</x-app.settings.layout>
