<x-app.layout title="{{ $contact->display_name }} | Argusly">
    <div class="mx-auto max-w-7xl">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Contact detail</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ $contact->display_name }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">{{ $contact->notes ?: 'Account-level contact for relationship intelligence.' }}</p>
            </div>
            <x-ui.button href="{{ route('app.relationships') }}" variant="secondary">Back to graph</x-ui.button>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-lg border border-line bg-white p-4 text-sm font-medium text-ink">{{ session('status') }}</div>
        @endif

        <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-dashboard.info-card label="Email" :value="$contact->email" empty="No email" />
            <x-dashboard.info-card label="Phone" :value="$contact->phone" empty="No phone" />
            <x-dashboard.info-card label="Website" :value="$contact->website" empty="No website" />
            <x-dashboard.info-card label="LinkedIn" :value="$contact->linkedin_url ? 'Available' : null" empty="No LinkedIn" />
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[0.8fr_1.2fr]">
            <x-dashboard.section title="Contact profile">
                <div class="space-y-3">
                    <div class="rounded-lg border border-line bg-panel p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Name</p>
                        <p class="mt-2 text-sm font-semibold text-ink">{{ $contact->first_name }} {{ $contact->last_name }}</p>
                    </div>
                    @if ($contact->website)
                        <x-ui.button href="{{ $contact->website }}" variant="secondary" target="_blank" rel="noreferrer">Open website</x-ui.button>
                    @endif
                    @if ($contact->linkedin_url)
                        <x-ui.button href="{{ $contact->linkedin_url }}" variant="secondary" target="_blank" rel="noreferrer">Open LinkedIn</x-ui.button>
                    @endif
                </div>
            </x-dashboard.section>

            <x-dashboard.section title="Relationships" description="Incoming and outgoing account graph edges for this contact.">
                @include('app.relationships._relationship-list', ['model' => $contact])
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
