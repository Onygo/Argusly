<x-app.settings.layout title="Module settings" description="Subscription-backed module availability for the current account.">
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($modules as $item)
            <x-ui.card class="p-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-base font-semibold text-ink">{{ $item['module']->name }}</h2>
                        <p class="mt-1 text-sm text-muted">{{ $item['module']->description ?: 'Module foundation' }}</p>
                    </div>
                    <x-ui.badge variant="{{ $item['active'] ? 'success' : 'default' }}">{{ $item['active'] ? 'Active' : 'Inactive' }}</x-ui.badge>
                </div>
            </x-ui.card>
        @endforeach
    </div>

    <div class="mt-6">
        <x-dashboard.empty-state title="No payment integration yet" message="Plan changes and billing are intentionally not connected at this stage." />
    </div>
</x-app.settings.layout>
