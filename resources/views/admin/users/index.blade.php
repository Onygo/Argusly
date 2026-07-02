@extends('layouts.admin', ['title' => 'Users'])

@section('pageHeader')
    <x-page-header title="Users" />
@endsection

@section('pageDescription')
    <x-page-description>Manage platform users and access states.</x-page-description>
@endsection

@section('filterBar')
    <form method="GET" class="grid w-full gap-2 sm:grid-cols-2 lg:grid-cols-4">
        <input
            type="text"
            name="q"
            value="{{ $filters['q'] }}"
            placeholder="Search name or email"
            class="pl-input sm:col-span-2 lg:w-56"
        >
        <select name="organization_id" class="pl-select bg-surface lg:w-44">
            <option value="">All organizations</option>
            @foreach ($organizations as $organization)
                <option value="{{ $organization->id }}" @selected($filters['organization_id'] === (string) $organization->id)>{{ $organization->name }}</option>
            @endforeach
        </select>
        <div class="flex gap-2">
            <select name="status" class="pl-select w-full bg-surface lg:w-36">
                <option value="">All status</option>
                <option value="active" @selected($filters['status'] === 'active')>Active</option>
                <option value="disabled" @selected($filters['status'] === 'disabled')>Disabled</option>
                <option value="pending" @selected($filters['status'] === 'pending')>Pending</option>
            </select>
            <select name="sort" class="pl-select w-full bg-surface lg:w-32">
                <option value="newest" @selected($filters['sort'] === 'newest')>Newest</option>
                <option value="oldest" @selected($filters['sort'] === 'oldest')>Oldest</option>
            </select>
        </div>
        <div class="flex gap-2 sm:col-span-2 lg:col-span-4 lg:justify-end">
            <button class="pl-btn-secondary">
                <i data-lucide="search" class="h-4 w-4"></i>
                Apply
            </button>
            <a href="{{ route('admin.users') }}" class="pl-btn-ghost h-10 border border-border">
                <i data-lucide="rotate-ccw" class="h-4 w-4"></i>
                Reset
            </a>
        </div>
    </form>
@endsection

@section('content')
    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first() }}</div>
    @endif

    <x-data-table label="Users" description="Filtered user list with organization, role, status, created date, and row actions." sticky max-height="68vh">
                    <x-data-table.header sticky>
                        <x-data-table.row>
                            <x-data-table.cell heading>User</x-data-table.cell>
                            <x-data-table.cell heading>Organization</x-data-table.cell>
                            <x-data-table.cell heading>Role</x-data-table.cell>
                            <x-data-table.cell heading>Status</x-data-table.cell>
                            <x-data-table.cell heading>Created</x-data-table.cell>
                            <x-data-table.cell heading align="right">Actions</x-data-table.cell>
                        </x-data-table.row>
                    </x-data-table.header>
                    <tbody>
                        @forelse ($users as $user)
                            @php
                                $statusLabel = 'Disabled';
                                $statusClass = 'pl-badge';
                                $latestOverride = $user->latestAccessOverride;
                                $latestOverrideStatus = $latestOverride?->effectiveStatus();

                                if (! $user->approved_at) {
                                    $statusLabel = 'Pending';
                                    $statusClass = 'inline-flex items-center rounded-sm bg-warning/10 px-2 py-0.5 text-xs font-medium text-warning';
                                } elseif ($user->active) {
                                    $statusLabel = 'Active';
                                    $statusClass = 'inline-flex items-center rounded-sm bg-success/10 px-2 py-0.5 text-xs font-medium text-success';
                                }

                                $userPayload = [
                                    'id' => (string) $user->id,
                                    'name' => (string) $user->name,
                                    'email' => (string) $user->email,
                                    'organization_id' => $user->organization_id ? (string) $user->organization_id : '',
                                    'role' => (string) $user->role,
                                    'active' => (bool) $user->active,
                                    'approved' => (bool) $user->approved_at,
                                    'organization_name' => (string) ($user->organization?->name ?? 'N/A'),
                                    'update_url' => route('admin.users.update', $user),
                                    'role_update_url' => route('admin.users.role.update', $user),
                                    'disable_url' => route('admin.users.disable', $user),
                                    'activate_url' => route('admin.users.activate', $user),
                                    'approve_url' => route('admin.users.approve', $user),
                                    'admin_role' => (string) ($user->admin_role ?? ''),
                                ];
                            @endphp
                            <x-data-table.row>
                                <x-data-table.cell label="User">
                                    <p class="font-medium text-textPrimary">{{ $user->name }}</p>
                                    <p class="text-xs text-textSecondary">{{ $user->email }}</p>
                                </x-data-table.cell>
                                <x-data-table.cell label="Organization" class="text-textSecondary">{{ $user->organization?->name ?? 'N/A' }}</x-data-table.cell>
                                <x-data-table.cell label="Role">
                                    <span class="rounded border border-border px-2 py-0.5 text-xs text-textPrimary">{{ $user->role }}</span>
                                    <div class="mt-2">
                                        <span class="rounded border border-border px-2 py-0.5 text-xs text-textSecondary">
                                            Admin role: {{ $user->resolvedAdminRole() }}
                                        </span>
                                    </div>
                                    @can('admin-area-superadmin')
                                        <form method="POST" action="{{ route('admin.users.role.update', $user) }}" class="mt-2 flex items-center gap-1.5">
                                            @csrf
                                            <select name="admin_role" class="rounded border border-border bg-background px-2 py-1 text-xs">
                                                @foreach (['user', 'admin', 'superadmin'] as $adminRole)
                                                    <option value="{{ $adminRole }}" @selected($user->resolvedAdminRole() === $adminRole)>{{ $adminRole }}</option>
                                                @endforeach
                                            </select>
                                            <button class="inline-flex items-center justify-center rounded border border-border px-2 py-1 text-xs font-medium">Set</button>
                                        </form>
                                    @endcan
                                </x-data-table.cell>
                                <x-data-table.cell label="Status">
                                    <x-data-table.badge :tone="$statusLabel === 'Active' ? 'success' : ($statusLabel === 'Pending' ? 'warning' : 'neutral')" :label="$statusLabel" />
                                    @if ($latestOverrideStatus)
                                        <div class="mt-2">
                                            <span class="inline-flex items-center rounded-sm border px-2 py-0.5 text-xs font-medium {{ $latestOverrideStatus->badgeClasses() }}">
                                                {{ $latestOverrideStatus->label() }}
                                            </span>
                                        </div>
                                    @endif
                                </x-data-table.cell>
                                <x-data-table.cell label="Created" class="text-xs text-textSecondary">{{ $user->created_at?->format('Y-m-d H:i') }}</x-data-table.cell>
                                <x-data-table.cell label="Actions">
                                    <x-data-table.actions>
                                        <a href="{{ route('admin.users.show', $user) }}" class="inline-flex h-8 w-8 items-center justify-center rounded border border-border text-textSecondary hover:text-textPrimary" title="View" aria-label="View {{ $user->name }}">
                                            <i data-lucide="eye" class="h-4 w-4"></i>
                                        </a>
                                        <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded border border-border text-textSecondary hover:text-textPrimary" title="Edit" aria-label="Edit {{ $user->name }}" data-open-user-drawer data-drawer-mode="edit" data-user='@json($userPayload)'>
                                            <i data-lucide="pencil" class="h-4 w-4"></i>
                                        </button>

                                        @if (! $user->approved_at)
                                            <form method="POST" action="{{ route('admin.users.approve', $user) }}">
                                                @csrf
                                                <button class="inline-flex h-8 w-8 items-center justify-center rounded border border-emerald-500/40 text-emerald-700" title="Approve" aria-label="Approve {{ $user->name }}">
                                                    <i data-lucide="badge-check" class="h-4 w-4"></i>
                                                </button>
                                            </form>
                                        @endif

                                        @if ($user->active)
                                            <form method="POST" action="{{ route('admin.users.disable', $user) }}">
                                                @csrf
                                                <button class="inline-flex h-8 w-8 items-center justify-center rounded border border-rose-500/40 text-rose-700" title="Disable" aria-label="Disable {{ $user->name }}">
                                                    <i data-lucide="user-x" class="h-4 w-4"></i>
                                                </button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('admin.users.activate', $user) }}">
                                                @csrf
                                                <button class="inline-flex h-8 w-8 items-center justify-center rounded border border-border text-textSecondary hover:text-textPrimary" title="Enable" aria-label="Enable {{ $user->name }}">
                                                    <i data-lucide="user-check" class="h-4 w-4"></i>
                                                </button>
                                            </form>
                                        @endif
                                    </x-data-table.actions>
                                </x-data-table.cell>
                            </x-data-table.row>
                        @empty
                            <x-data-table.empty colspan="6" title="No users found" description="No users match the current filters." />
                        @endforelse
                    </tbody>
        <x-slot:pagination>{{ $users->links() }}</x-slot:pagination>
    </x-data-table>

    <div id="users-drawer-overlay" class="fixed inset-0 z-40 hidden bg-black/30" aria-hidden="true"></div>
    <aside id="users-drawer" class="fixed right-0 top-0 z-50 h-full w-full max-w-xl translate-x-full border-l border-border bg-surface shadow-2xl transition-transform duration-200">
        <div class="flex items-center justify-between border-b border-border px-4 py-3">
            <h3 id="users-drawer-title" class="text-sm font-semibold text-textPrimary">User details</h3>
            <button type="button" id="users-drawer-close" class="rounded border border-border p-1.5" aria-label="Close user drawer">
                <i data-lucide="x" class="h-4 w-4"></i>
            </button>
        </div>

        <div class="h-[calc(100%-57px)] overflow-y-auto p-4">
            {{-- Drawer form replaces bulky inline row forms and keeps edits focused. --}}
            <form id="users-drawer-form" method="POST" class="space-y-3">
                @csrf
                <input type="hidden" name="edit_user_id" id="drawer-edit-user-id" value="{{ old('edit_user_id') }}">

                <div>
                    <label for="drawer-name" class="text-xs text-textSecondary">Name</label>
                    <input id="drawer-name" name="name" value="{{ old('name') }}" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm" required>
                    @error('name')
                        <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="drawer-email" class="text-xs text-textSecondary">Email</label>
                    <input id="drawer-email" name="email" type="email" value="{{ old('email') }}" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm" required>
                    @error('email')
                        <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="drawer-organization-id" class="text-xs text-textSecondary">Organization</label>
                    <select id="drawer-organization-id" name="organization_id" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        <option value="">No organization</option>
                        @foreach ($organizations as $organization)
                            <option value="{{ $organization->id }}" @selected(old('organization_id') === (string) $organization->id)>{{ $organization->name }}</option>
                        @endforeach
                    </select>
                    @error('organization_id')
                        <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="drawer-role" class="text-xs text-textSecondary">Role</label>
                    <select id="drawer-role" name="role" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm" required>
                        @foreach (['owner', 'admin', 'editor', 'member'] as $role)
                            <option value="{{ $role }}" @selected(old('role') === $role)>{{ $role }}</option>
                        @endforeach
                    </select>
                    @error('role')
                        <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </div>

                <label class="inline-flex items-center gap-2 text-sm text-textPrimary">
                    <input id="drawer-active" type="checkbox" name="active" value="1" @checked(old('active'))>
                    Active user
                </label>

                <div class="flex flex-wrap gap-2 border-t border-border pt-3">
                    <button id="drawer-save-button" class="inline-flex items-center gap-2 rounded border border-border px-3 py-2 text-sm font-medium">
                        <i data-lucide="save" class="h-4 w-4"></i>
                        Save user
                    </button>
                </div>
            </form>

            <div class="mt-3 flex flex-wrap gap-2 border-t border-border pt-3">
                <form id="drawer-toggle-active-form" method="POST">
                    @csrf
                    <button id="drawer-toggle-active-button" class="inline-flex items-center gap-2 rounded border border-border px-3 py-2 text-sm">
                        <i data-lucide="power" class="h-4 w-4"></i>
                        Toggle active
                    </button>
                </form>

                <form id="drawer-approve-form" method="POST" class="hidden">
                    @csrf
                    <button id="drawer-approve-button" class="inline-flex items-center gap-2 rounded border border-emerald-500/40 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-700">
                        <i data-lucide="badge-check" class="h-4 w-4"></i>
                        Approve user
                    </button>
                </form>
            </div>

            <p id="drawer-user-meta" class="mt-4 text-xs text-textSecondary"></p>
        </div>
    </aside>

    <script>
        (() => {
            // Drawer keeps row actions compact by centralizing edit controls in one panel.
            const overlay = document.getElementById('users-drawer-overlay');
            const drawer = document.getElementById('users-drawer');
            const closeButton = document.getElementById('users-drawer-close');
            const title = document.getElementById('users-drawer-title');
            const form = document.getElementById('users-drawer-form');
            const saveButton = document.getElementById('drawer-save-button');
            const inputEditUserId = document.getElementById('drawer-edit-user-id');
            const inputName = document.getElementById('drawer-name');
            const inputEmail = document.getElementById('drawer-email');
            const inputOrganization = document.getElementById('drawer-organization-id');
            const inputRole = document.getElementById('drawer-role');
            const inputActive = document.getElementById('drawer-active');
            const meta = document.getElementById('drawer-user-meta');
            const toggleForm = document.getElementById('drawer-toggle-active-form');
            const toggleButton = document.getElementById('drawer-toggle-active-button');
            const approveForm = document.getElementById('drawer-approve-form');

            const openDrawer = (user, mode = 'edit', preserveInputs = false) => {
                title.textContent = mode === 'view' ? 'User details' : 'Edit user';
                form.action = user.update_url;
                inputEditUserId.value = user.id || '';

                if (!preserveInputs) {
                    inputName.value = user.name || '';
                    inputEmail.value = user.email || '';
                    inputOrganization.value = user.organization_id || '';
                    inputRole.value = user.role || 'member';
                    inputActive.checked = Boolean(user.active);
                }

                const readOnly = mode === 'view';
                [inputName, inputEmail, inputOrganization, inputRole, inputActive].forEach((field) => {
                    field.disabled = readOnly;
                });
                saveButton.classList.toggle('hidden', readOnly);

                toggleForm.action = user.active ? user.disable_url : user.activate_url;
                toggleButton.innerHTML = user.active
                    ? '<i data-lucide="user-x" class="h-4 w-4"></i> Disable user'
                    : '<i data-lucide="user-check" class="h-4 w-4"></i> Enable user';
                toggleButton.classList.toggle('border-rose-500/40', user.active);
                toggleButton.classList.toggle('text-rose-700', user.active);

                if (!user.approved) {
                    approveForm.action = user.approve_url;
                    approveForm.classList.remove('hidden');
                } else {
                    approveForm.classList.add('hidden');
                }

                meta.textContent = `${user.organization_name || 'No organization'} • ${user.email || ''}`;

                overlay.classList.remove('hidden');
                drawer.classList.remove('translate-x-full');
                if (window.lucide) {
                    window.lucide.createIcons();
                }
            };

            const closeDrawer = () => {
                drawer.classList.add('translate-x-full');
                overlay.classList.add('hidden');
            };

            document.querySelectorAll('[data-open-user-drawer]').forEach((button) => {
                button.addEventListener('click', () => {
                    const mode = button.getAttribute('data-drawer-mode') || 'edit';
                    const raw = button.getAttribute('data-user') || '{}';
                    try {
                        openDrawer(JSON.parse(raw), mode);
                    } catch (_) {
                        // no-op
                    }
                });
            });

            overlay.addEventListener('click', closeDrawer);
            closeButton.addEventListener('click', closeDrawer);
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    closeDrawer();
                }
            });

            const oldEditUserId = @json(old('edit_user_id'));
            const hasValidationErrors = @json($errors->any());
            if (hasValidationErrors && oldEditUserId) {
                for (const source of document.querySelectorAll('[data-open-user-drawer]')) {
                    try {
                        const payload = JSON.parse(source.getAttribute('data-user') || '{}');
                        if (String(payload.id || '') === String(oldEditUserId)) {
                            openDrawer(payload, 'edit', true);
                            break;
                        }
                    } catch (_) {
                        // no-op
                    }
                }
            }
        })();
    </script>
@endsection
