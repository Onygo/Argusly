<x-app.settings.layout title="Integration settings" description="Connected integration foundations scoped to the current account and brand.">
    <div class="mb-6">
        <x-ui.card class="p-5">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                <div>
                    <h2 class="text-base font-semibold text-ink">LinkedIn</h2>
                    <p class="mt-1 text-sm text-muted">Personal profile publishing is prepared. Organization and page publishing is staged for a later OAuth pass.</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($linkedinProvider->scopes() as $scope)
                            <x-ui.badge>{{ $scope }}</x-ui.badge>
                        @endforeach
                    </div>
                </div>
                <a href="{{ route('settings.integrations.linkedin') }}" class="inline-flex items-center justify-center rounded-md border border-line px-3 py-2 text-sm font-medium text-ink transition hover:border-ink">
                    Manage LinkedIn
                </a>
            </div>
        </x-ui.card>
    </div>

    @if ($connections->isEmpty())
        <x-dashboard.empty-state title="No integrations connected" message="Connected integrations will appear here after OAuth and provider setup are implemented." />
    @else
        <div class="space-y-4">
            @foreach ($connections as $connection)
                <x-ui.card class="p-5">
                    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                        <div>
                            <h2 class="text-base font-semibold text-ink">{{ $connection->name }}</h2>
                            <p class="mt-1 text-sm text-muted">{{ $connection->integration?->name ?? 'Integration' }}{{ $connection->brand ? ' · '.$connection->brand->name : '' }}</p>
                        </div>
                        <x-ui.badge variant="success">{{ str($connection->status)->headline() }}</x-ui.badge>
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    @endif

    <div class="mt-6">
        <x-dashboard.empty-state title="No OAuth yet" message="OAuth connect, reconnect and disconnect actions are placeholders until provider redirects are implemented." />
    </div>
</x-app.settings.layout>
