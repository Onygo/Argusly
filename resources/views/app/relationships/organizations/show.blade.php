<x-app.layout title="{{ $organization->name }} | Argusly">
    <div class="mx-auto max-w-7xl">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Organization detail</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ $organization->name }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">{{ $organization->description ?: 'Account-level organization for relationship intelligence.' }}</p>
            </div>
            <x-ui.button href="{{ route('app.relationships') }}" variant="secondary">Back to graph</x-ui.button>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-lg border border-line bg-white p-4 text-sm font-medium text-ink">{{ session('status') }}</div>
        @endif

        <div class="mt-8 grid gap-4 md:grid-cols-3">
            <x-dashboard.info-card label="Industry" :value="$organization->industry" empty="No industry" />
            <x-dashboard.info-card label="Website" :value="$organization->website" empty="No website" />
            <x-dashboard.info-card label="Relationships" :value="$organization->outgoingRelationships->count() + $organization->incomingRelationships->count()" />
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[0.8fr_1.2fr]">
            <x-dashboard.section title="Organization profile">
                <div class="rounded-lg border border-line bg-panel p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Description</p>
                    <p class="mt-2 text-sm leading-6 text-ink">{{ $organization->description ?: 'No description yet.' }}</p>
                </div>
                @if ($organization->website)
                    <div class="mt-4">
                        <x-ui.button href="{{ $organization->website }}" variant="secondary" target="_blank" rel="noreferrer">Open website</x-ui.button>
                    </div>
                @endif
            </x-dashboard.section>

            <x-dashboard.section title="Relationships" description="Incoming and outgoing account graph edges for this organization.">
                @include('app.relationships._relationship-list', ['model' => $organization])
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
