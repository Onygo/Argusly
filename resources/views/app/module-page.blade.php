<x-app.layout :title="$title.' | Argusly'">
    <div class="mx-auto max-w-7xl">
        <div>
            <p class="eyebrow">{{ $module }}</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ $title }}</h1>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">This module route is protected by the current account subscription and the user permission model.</p>
        </div>

        <x-dashboard.section class="mt-8" :title="$title.' foundation'">
            <x-dashboard.empty-state
                title="No module features yet"
                message="The access-controlled route is ready. Product functionality will be implemented in a later prompt."
            />
        </x-dashboard.section>
    </div>
</x-app.layout>
