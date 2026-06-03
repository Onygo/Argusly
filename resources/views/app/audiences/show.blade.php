<x-app.layout title="{{ $audience->name }} | Argusly">
    <div class="mx-auto max-w-6xl">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Audience</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ $audience->name }}</h1>
                <div class="mt-3 flex flex-wrap gap-2">
                    <x-ui.badge variant="{{ $audience->status === 'active' ? 'success' : 'default' }}">{{ str($audience->status)->headline() }}</x-ui.badge>
                    <x-ui.badge>{{ $audience->brand?->name ?? 'Account-wide' }}</x-ui.badge>
                    <x-ui.badge>{{ $audience->members_count }} members</x-ui.badge>
                </div>
            </div>
            <x-ui.button href="{{ route('app.audiences') }}" variant="secondary">Audiences</x-ui.button>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-md border border-line bg-white p-4 text-sm font-medium text-ink">{{ session('status') }}</div>
        @endif

        <div class="mt-8 grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
            <x-dashboard.section title="Members" description="{{ $audience->description ?: 'Contacts grouped for future campaign targeting.' }}">
                @if ($audience->members->isEmpty())
                    <x-dashboard.empty-state title="No members" message="Add a known contact or email address to this audience." />
                @else
                    <div class="space-y-3">
                        @foreach ($audience->members as $member)
                            <div class="rounded-md border border-line bg-panel p-4">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-semibold text-ink">{{ trim("{$member->first_name} {$member->last_name}") ?: $member->contact?->display_name ?: $member->email }}</p>
                                    <x-ui.badge variant="{{ $member->status === 'active' ? 'success' : 'default' }}">{{ str($member->status)->headline() }}</x-ui.badge>
                                    @if ($member->contact)
                                        <x-ui.badge>Contact</x-ui.badge>
                                    @endif
                                </div>
                                <p class="mt-2 text-sm text-muted">{{ $member->email }}</p>
                                <p class="mt-2 text-xs text-muted">{{ $member->source ?? 'manual' }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-dashboard.section>

            <x-dashboard.section title="Add member" description="Reuse an existing contact when available, or keep the address as a standalone member.">
                <form method="POST" action="{{ route('app.audiences.members.store', $audience) }}" class="space-y-4">
                    @csrf
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Existing contact</span>
                        <select name="contact_id" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                            <option value="">Match by email</option>
                            @foreach ($contacts as $contact)
                                <option value="{{ $contact->id }}">{{ $contact->display_name ?: trim("{$contact->first_name} {$contact->last_name}") }} · {{ $contact->email }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Email</span>
                        <input name="email" type="email" required class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="maya@example.com">
                    </label>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">First name</span>
                            <input name="first_name" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Last name</span>
                            <input name="last_name" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        </label>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Status</span>
                            <select name="status" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach ($statuses as $status)
                                    <option value="{{ $status }}">{{ str($status)->headline() }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Source</span>
                            <input name="source" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="manual">
                        </label>
                    </div>
                    <x-ui.button type="submit">Add member</x-ui.button>
                </form>
            </x-dashboard.section>
        </div>

        <div class="mt-6">
            <x-dashboard.section title="Segments" description="Segments linked to this audience.">
                @if ($audience->segments->isEmpty())
                    <p class="text-sm text-muted">No linked segments.</p>
                @else
                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach ($audience->segments as $segment)
                            <div class="rounded-md border border-line bg-panel p-4">
                                <p class="text-sm font-semibold text-ink">{{ $segment->name }}</p>
                                <p class="mt-2 line-clamp-2 text-sm leading-6 text-muted">{{ $segment->description ?: 'No description yet.' }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
