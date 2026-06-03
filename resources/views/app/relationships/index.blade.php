<x-app.layout title="Relationships | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Relationship intelligence</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Relationship Graph</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Account-level CRM-style graph for contacts, organizations, media, creators, journalists, experts and stakeholders.</p>
            </div>
            <x-ui.badge variant="blue">{{ $graph['relationships'] }} relationships</x-ui.badge>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-md border border-line bg-white p-4 text-sm font-medium text-ink">{{ session('status') }}</div>
        @endif

        <div class="mt-8 grid gap-3 sm:grid-cols-3">
            <x-dashboard.info-card label="Contacts" :value="$graph['contacts']" />
            <x-dashboard.info-card label="Organizations" :value="$graph['organizations']" />
            <x-dashboard.info-card label="Relationships" :value="$graph['relationships']" />
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[0.8fr_1.2fr]">
            <div class="space-y-6">
                <x-dashboard.section title="Add contact">
                    <form method="POST" action="{{ route('app.relationships.contacts.store') }}" class="space-y-4">
                        @csrf
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">First name</span>
                                <input name="first_name" required class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                            </label>
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Last name</span>
                                <input name="last_name" required class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                            </label>
                        </div>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Email</span>
                            <input name="email" type="email" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">LinkedIn URL</span>
                            <input name="linkedin_url" type="url" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        </label>
                        <x-ui.button type="submit">Create contact</x-ui.button>
                    </form>
                </x-dashboard.section>

                <x-dashboard.section title="Add organization">
                    <form method="POST" action="{{ route('app.relationships.organizations.store') }}" class="space-y-4">
                        @csrf
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Name</span>
                            <input name="name" required class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Website</span>
                            <input name="website" type="url" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Industry</span>
                            <input name="industry" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        </label>
                        <x-ui.button type="submit">Create organization</x-ui.button>
                    </form>
                </x-dashboard.section>
            </div>

            <div class="space-y-6">
                <x-dashboard.section title="Create relationship" description="Connect contacts and organizations with a typed account-safe edge.">
                    @if ($nodes->count() < 2)
                        <x-dashboard.empty-state title="Add more records" message="At least two contacts or organizations are required before a relationship can be created." />
                    @else
                        <form method="POST" action="{{ route('app.relationships.edges.store') }}" class="grid gap-3 md:grid-cols-2">
                            @csrf
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">From</span>
                                <select name="from_id" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                    @foreach ($nodes as $node)
                                        <option value="{{ $node->id }}" data-type="{{ $node instanceof \App\Models\Contact ? 'contact' : 'organization' }}">
                                            {{ $node instanceof \App\Models\Contact ? $node->display_name : $node->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <select name="from_type" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                    <option value="contact">Contact</option>
                                    <option value="organization">Organization</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">To</span>
                                <select name="to_id" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                    @foreach ($nodes as $node)
                                        <option value="{{ $node->id }}">{{ $node instanceof \App\Models\Contact ? $node->display_name : $node->name }}</option>
                                    @endforeach
                                </select>
                                <select name="to_type" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                    <option value="organization">Organization</option>
                                    <option value="contact">Contact</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Relationship</span>
                                <select name="relationship_type" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                    @foreach ($relationshipTypes as $type)
                                        <option value="{{ $type }}">{{ str($type)->headline() }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Strength</span>
                                <input name="strength" type="number" min="0" max="100" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                            </label>
                            <div class="md:col-span-2">
                                <x-ui.button type="submit">Create relationship</x-ui.button>
                            </div>
                        </form>
                    @endif
                </x-dashboard.section>

                <x-dashboard.section title="Graph edges">
                    @if ($relationships->isEmpty())
                        <x-dashboard.empty-state title="No relationships" message="Create relationship edges to build the account graph." />
                    @else
                        <div class="space-y-3">
                            @foreach ($relationships as $relationship)
                                <div class="rounded-md border border-line bg-panel p-4">
                                    <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold text-ink">
                                                {{ $relationship->from instanceof \App\Models\Contact ? $relationship->from->display_name : $relationship->from->name }}
                                                <span class="text-muted">→</span>
                                                {{ $relationship->to instanceof \App\Models\Contact ? $relationship->to->display_name : $relationship->to->name }}
                                            </p>
                                            <p class="mt-1 text-xs text-muted">Strength {{ $relationship->strength ?? 'not set' }}</p>
                                        </div>
                                        <x-ui.badge>{{ str($relationship->relationship_type)->headline() }}</x-ui.badge>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-dashboard.section>
            </div>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-2">
            <x-dashboard.section title="Contacts">
                @if ($contacts->isEmpty())
                    <x-dashboard.empty-state title="No contacts" message="Add contacts such as influencers, journalists, analysts or experts." />
                @else
                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach ($contacts as $contact)
                            <a href="{{ route('app.relationships.contacts.show', $contact) }}" class="rounded-md border border-line bg-panel p-4 hover:bg-white">
                                <p class="truncate text-sm font-semibold text-ink">{{ $contact->display_name }}</p>
                                <p class="mt-1 truncate text-xs text-muted">{{ $contact->email ?: 'No email' }}</p>
                            </a>
                        @endforeach
                    </div>
                    <div class="mt-5">{{ $contacts->links() }}</div>
                @endif
            </x-dashboard.section>

            <x-dashboard.section title="Organizations">
                @if ($organizations->isEmpty())
                    <x-dashboard.empty-state title="No organizations" message="Add media outlets, partner companies, customer organizations or competitor entities." />
                @else
                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach ($organizations as $organization)
                            <a href="{{ route('app.relationships.organizations.show', $organization) }}" class="rounded-md border border-line bg-panel p-4 hover:bg-white">
                                <p class="truncate text-sm font-semibold text-ink">{{ $organization->name }}</p>
                                <p class="mt-1 truncate text-xs text-muted">{{ $organization->industry ?: 'No industry' }}</p>
                            </a>
                        @endforeach
                    </div>
                    <div class="mt-5">{{ $organizations->links() }}</div>
                @endif
            </x-dashboard.section>
        </div>

        <div class="mt-6">
            <x-dashboard.section title="Prepared relationship lanes" description="No social integrations yet; the graph is ready for specialized relationship workflows.">
                <div class="grid gap-3 md:grid-cols-5">
                    @foreach ($graph['futureLanes'] as $lane)
                        <div class="rounded-md border border-line bg-panel p-4">
                            <p class="text-sm font-semibold text-ink">{{ $lane['label'] }}</p>
                            <p class="mt-1 text-xs text-muted">{{ str($lane['status'])->headline() }}</p>
                        </div>
                    @endforeach
                </div>
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
