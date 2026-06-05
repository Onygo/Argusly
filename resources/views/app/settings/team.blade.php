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
                            <form method="POST" action="{{ route('settings.team.memberships.update', $member['membership']) }}" class="grid gap-2 text-left sm:grid-cols-[minmax(0,150px)_minmax(0,150px)_auto] sm:items-end">
                                @csrf
                                @method('PATCH')
                                <label class="block">
                                    <span class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Role</span>
                                    <select name="role_id" class="mt-1 w-full rounded-md border border-line bg-white px-2 py-1.5 text-xs text-ink">
                                        @foreach ($roles as $role)
                                            <option value="{{ $role->id }}" @selected($member['role_id'] === $role->id)>{{ $role->display_name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="block">
                                    <span class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Status</span>
                                    <select name="status" class="mt-1 w-full rounded-md border border-line bg-white px-2 py-1.5 text-xs text-ink">
                                        @foreach (['active', 'inactive'] as $status)
                                            <option value="{{ $status }}" @selected($member['status'] === $status)>{{ str($status)->headline() }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <button class="rounded-md border border-line bg-white px-3 py-2 text-xs font-semibold text-ink transition hover:bg-white/70">Save</button>
                            </form>
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
                                <form method="POST" action="{{ route('settings.team.brand-memberships.update', $member['membership']) }}" class="grid gap-2 text-left sm:grid-cols-[minmax(0,150px)_minmax(0,150px)_auto] sm:items-end">
                                    @csrf
                                    @method('PATCH')
                                    <label class="block">
                                        <span class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Role</span>
                                        <select name="role_id" class="mt-1 w-full rounded-md border border-line bg-white px-2 py-1.5 text-xs text-ink">
                                            @foreach ($roles as $role)
                                                <option value="{{ $role->id }}" @selected($member['role_id'] === $role->id)>{{ $role->display_name }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="block">
                                        <span class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">Status</span>
                                        <select name="status" class="mt-1 w-full rounded-md border border-line bg-white px-2 py-1.5 text-xs text-ink">
                                            @foreach (['active', 'inactive'] as $status)
                                                <option value="{{ $status }}" @selected($member['status'] === $status)>{{ str($status)->headline() }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <button class="rounded-md border border-line bg-white px-3 py-2 text-xs font-semibold text-ink transition hover:bg-white/70">Save</button>
                                </form>
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
                @if ($brandAssignableMembers->isNotEmpty())
                    <form method="POST" action="{{ route('settings.team.brand-memberships.store') }}" class="mt-5 grid gap-3 rounded-md border border-line bg-panel p-4 md:grid-cols-[minmax(0,1fr)_minmax(0,180px)_auto] md:items-end">
                        @csrf
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Add workspace member to brand</span>
                            <select name="user_id" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach ($brandAssignableMembers as $member)
                                    <option value="{{ $member->id }}">{{ $member->name }} · {{ $member->email }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Brand role</span>
                            <select name="role_id" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                @foreach ($roles as $role)
                                    <option value="{{ $role->id }}">{{ $role->display_name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <x-ui.button type="submit" size="sm">Assign</x-ui.button>
                    </form>
                @endif
            @endif
        </x-dashboard.section>
    </div>
</x-app.settings.layout>
