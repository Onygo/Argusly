<x-app.settings.layout title="Team settings" description="Account and brand membership for the current tenant context.">
    @php($impersonationActive = session()->has('impersonator_user_id'))
    <div class="grid gap-6 xl:grid-cols-2">
        <x-dashboard.section title="Account members">
            <div class="space-y-3">
                @forelse ($accountMembers as $member)
                    <div class="flex items-center justify-between gap-4 rounded-md border border-line bg-panel p-4">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-ink">{{ $member['user']->name }}</p>
                            <p class="truncate text-xs text-muted">{{ $member['user']->email }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-3 text-right">
                            <div>
                            <p class="text-sm font-semibold text-ink">{{ $member['role'] ?? 'No role' }}</p>
                            <p class="text-xs text-muted">{{ str($member['status'])->headline() }}</p>
                            </div>
                            @if ($impersonationActive)
                                <span class="text-xs font-semibold text-muted">Impersonation active</span>
                            @elseif (auth()->id() === $member['user']->id)
                                <span class="text-xs font-semibold text-muted">Current user</span>
                            @elseif ($member['status'] === 'active')
                                <form method="POST" action="{{ route('workspace.users.impersonate', $member['user']) }}">
                                    @csrf
                                    <button class="rounded-md border border-line bg-white px-3 py-2 text-sm font-semibold text-ink transition hover:bg-white/70">Impersonate</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @empty
                    <x-dashboard.empty-state title="No account members" message="No account members are assigned yet." />
                @endforelse
            </div>
        </x-dashboard.section>

        <x-dashboard.section title="Brand members">
            @if (! $brand)
                <x-dashboard.empty-state title="No brand selected" message="Select a brand to view brand-level members." />
            @else
                <div class="space-y-3">
                    @forelse ($brandMembers as $member)
                        <div class="flex items-center justify-between gap-4 rounded-md border border-line bg-panel p-4">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-ink">{{ $member['user']->name }}</p>
                                <p class="truncate text-xs text-muted">{{ $member['user']->email }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-3 text-right">
                                <div>
                                <p class="text-sm font-semibold text-ink">{{ $member['role'] ?? 'No role' }}</p>
                                <p class="text-xs text-muted">{{ str($member['status'])->headline() }}</p>
                                </div>
                                @if ($impersonationActive)
                                    <span class="text-xs font-semibold text-muted">Impersonation active</span>
                                @elseif (auth()->id() === $member['user']->id)
                                    <span class="text-xs font-semibold text-muted">Current user</span>
                                @elseif ($member['status'] === 'active')
                                    <form method="POST" action="{{ route('workspace.users.impersonate', $member['user']) }}">
                                        @csrf
                                        <button class="rounded-md border border-line bg-white px-3 py-2 text-sm font-semibold text-ink transition hover:bg-white/70">Impersonate</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @empty
                        <x-dashboard.empty-state title="No brand members" message="No members are assigned to the current brand yet." />
                    @endforelse
                </div>
            @endif
        </x-dashboard.section>
    </div>

    <div class="mt-6">
        <x-dashboard.empty-state title="Invite placeholder" message="Invitations will be added later. No email or role mutation is performed on this screen." />
    </div>
</x-app.settings.layout>
