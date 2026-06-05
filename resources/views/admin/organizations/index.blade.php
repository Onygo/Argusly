@extends('layouts.admin', ['title' => 'Organizations'])

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Organizations</h1>
        <p class="text-textSecondary mt-1">All organizations and status.</p>
    </div>

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    <x-mobile-card-list>
        @forelse ($organizations as $organization)
            @php
                $workspaces = $organization->workspaces->sortBy('created_at')->values();
                $workspaceCount = $workspaces->count();
                $primaryWorkspace = $workspaces->first();
            @endphp
            <article class="pl-mobile-card {{ $organization->isArchived() ? 'opacity-60' : '' }}">
                <div class="pl-mobile-card__header">
                    <div class="min-w-0 flex-1">
                        <a class="pl-mobile-card__title" href="{{ route('admin.organizations.show', $organization) }}">{{ $organization->name }}</a>
                        <div class="mt-1 text-xs text-textSecondary">{{ $organization->slug }}</div>
                    </div>
                    <span class="pl-badge {{ $organization->getStatusBadgeClasses() }}">
                        <span class="pl-badge__label">{{ $organization->getStatusLabel() }}</span>
                    </span>
                </div>

                <div class="pl-mobile-card__meta">
                    <x-metadata-row label="Created" :value="$organization->created_at?->format('Y-m-d H:i') ?? 'n/a'" />
                    <x-metadata-row label="Workspaces" :value="$workspaceCount" />
                    @if ($primaryWorkspace)
                        <x-metadata-row label="Primary workspace" :value="$primaryWorkspace->display_name ?: $primaryWorkspace->name" />
                    @endif
                </div>

                <details class="mt-3 border-t border-divider pt-3">
                    <summary class="flex cursor-pointer items-center justify-between text-sm font-medium text-textPrimary">
                        <span>Details</span>
                        <i data-lucide="chevron-down" class="h-4 w-4 text-textSecondary"></i>
                    </summary>
                    <div class="mt-3 space-y-2">
                        @foreach ($workspaces->slice(1, 4) as $workspace)
                            <x-metadata-row label="Workspace" :value="$workspace->display_name ?: $workspace->name" />
                        @endforeach
                    </div>
                </details>

                <div class="pl-mobile-card__actions">
                    <div class="min-w-0 flex-1">
                        @can('admin-area-access')
                            @if ($workspaceCount === 1 && $primaryWorkspace)
                                <form method="POST" action="{{ route('admin.organizations.impersonate', $organization) }}" data-impersonate-form>
                                    @csrf
                                    <button
                                        type="submit"
                                        class="pl-btn-secondary w-full justify-center"
                                        title="Open this organization as workspace user"
                                        data-impersonate-button
                                        data-loading-text="Opening..."
                                    >
                                        <i data-lucide="log-in" class="h-4 w-4"></i>
                                        <span>Impersonate</span>
                                    </button>
                                </form>
                            @elseif ($workspaceCount > 1)
                                <details class="relative" data-impersonate-selector>
                                    <summary class="pl-btn-secondary w-full cursor-pointer justify-center">
                                        <i data-lucide="log-in" class="h-4 w-4"></i>
                                        <span>Impersonate</span>
                                    </summary>
                                    <div class="pl-action-menu__panel left-0 right-0 mt-2 w-auto">
                                        <div class="px-3 py-1 text-[11px] font-semibold uppercase tracking-wide text-textSecondary">Choose workspace</div>
                                        @foreach ($workspaces as $workspace)
                                            <form method="POST" action="{{ route('admin.organizations.impersonate', $organization) }}" data-impersonate-form>
                                                @csrf
                                                <input type="hidden" name="workspace_id" value="{{ $workspace->id }}">
                                                <button
                                                    type="submit"
                                                    class="pl-action-menu__item justify-between text-xs"
                                                    data-impersonate-button
                                                    data-loading-text="Opening..."
                                                >
                                                    <span class="truncate">{{ $workspace->display_name ?: $workspace->name }}</span>
                                                    <span class="ml-3 shrink-0 text-textSecondary">Open</span>
                                                </button>
                                            </form>
                                        @endforeach
                                    </div>
                                </details>
                            @else
                                <a href="{{ route('admin.organizations.show', $organization) }}" class="pl-btn-secondary w-full justify-center">Open</a>
                            @endif
                        @else
                            <a href="{{ route('admin.organizations.show', $organization) }}" class="pl-btn-secondary w-full justify-center">Open</a>
                        @endcan
                    </div>

                    <x-action-menu>
                        <a href="{{ route('admin.organizations.show', $organization) }}" class="pl-action-menu__item">View organization</a>
                        @if ($organization->isArchived())
                            <form method="POST" action="{{ route('admin.organizations.unarchive', $organization) }}" onsubmit="return confirm('Restore this organization from archive?');">
                                @csrf
                                <button type="submit" class="pl-action-menu__item">Restore</button>
                            </form>
                        @elseif ($organization->isOnHold())
                            <form method="POST" action="{{ route('admin.organizations.activate', $organization) }}">
                                @csrf
                                <button type="submit" class="pl-action-menu__item">Activate</button>
                            </form>
                            <form method="POST" action="{{ route('admin.organizations.archive', $organization) }}" onsubmit="return confirm('Archive this organization? It will be hidden from active operations.');">
                                @csrf
                                <button type="submit" class="pl-action-menu__item">Archive</button>
                            </form>
                        @elseif ($organization->isActive())
                            <form method="POST" action="{{ route('admin.organizations.hold', $organization) }}" onsubmit="return confirm('Set this organization on hold? Customer operations will be restricted.');">
                                @csrf
                                <button type="submit" class="pl-action-menu__item">Deactivate</button>
                            </form>
                            <form method="POST" action="{{ route('admin.organizations.archive', $organization) }}" onsubmit="return confirm('Archive this organization? It will be hidden from active operations.');">
                                @csrf
                                <button type="submit" class="pl-action-menu__item">Archive</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('admin.organizations.activate', $organization) }}">
                                @csrf
                                <button type="submit" class="pl-action-menu__item">Activate</button>
                            </form>
                        @endif

                        @can('admin-area-superadmin')
                            <a href="{{ route('admin.organizations.confirm-delete', $organization) }}" class="pl-action-menu__item text-rose-700">Delete</a>
                        @endcan
                    </x-action-menu>
                </div>
            </article>
        @empty
            <div class="pl-mobile-card text-sm text-textSecondary">No organizations found.</div>
        @endforelse
    </x-mobile-card-list>

    <x-responsive-table table-class="min-w-[760px]">
        <thead>
            <tr class="text-left text-textSecondary">
                <th class="pb-2 font-medium">Name</th>
                <th class="pb-2 font-medium">Slug</th>
                <th class="pb-2 font-medium">Status</th>
                <th class="pb-2 font-medium">Created</th>
                <th class="pb-2 font-medium text-right">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-border">
            @forelse ($organizations as $organization)
                @php
                    $isArchived = $organization->status === \App\Models\Organization::STATUS_ARCHIVED;
                    $isOnHold = $organization->status === \App\Models\Organization::STATUS_ON_HOLD;
                    $rowClasses = $isArchived ? 'opacity-60' : ($isOnHold ? 'bg-amber-50/30' : '');
                    $workspaces = $organization->workspaces->sortBy('created_at')->values();
                    $workspaceCount = $workspaces->count();
                    $primaryWorkspace = $workspaces->first();
                @endphp
                <tr class="{{ $rowClasses }}">
                    <td class="py-3">
                        <a class="font-medium text-textPrimary hover:underline" href="{{ route('admin.organizations.show', $organization) }}">{{ $organization->name }}</a>
                    </td>
                    <td class="py-3 text-textSecondary">{{ $organization->slug }}</td>
                    <td class="py-3">
                        <span class="pl-badge {{ $organization->getStatusBadgeClasses() }}">
                            <span class="pl-badge__label">{{ $organization->getStatusLabel() }}</span>
                        </span>
                    </td>
                    <td class="py-3 text-textSecondary">{{ $organization->created_at?->format('Y-m-d H:i') }}</td>
                    <td class="py-3 text-right">
                        <div class="inline-flex flex-wrap items-center justify-end gap-1.5">
                            @can('admin-area-access')
                                @if ($workspaceCount === 1 && $primaryWorkspace)
                                    <form method="POST" action="{{ route('admin.organizations.impersonate', $organization) }}" data-impersonate-form>
                                        @csrf
                                        <button
                                            type="submit"
                                            class="inline-flex items-center gap-1 rounded border border-border bg-background px-2 py-1 text-xs font-medium text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary disabled:cursor-not-allowed disabled:opacity-60"
                                            title="Open this organization as workspace user"
                                            data-impersonate-button
                                            data-loading-text="Opening..."
                                        >
                                            <i data-lucide="log-in" class="h-3.5 w-3.5"></i>
                                            <span>Impersonate</span>
                                        </button>
                                    </form>
                                @elseif ($workspaceCount > 1)
                                    <details class="relative text-left" data-impersonate-selector>
                                        <summary class="inline-flex cursor-pointer items-center gap-1 rounded border border-border bg-background px-2 py-1 text-xs font-medium text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary">
                                            <i data-lucide="log-in" class="h-3.5 w-3.5"></i>
                                            <span>Impersonate</span>
                                            <i data-lucide="chevron-down" class="h-3.5 w-3.5"></i>
                                        </summary>
                                        <div class="pl-action-menu__panel w-64">
                                            <div class="px-2 py-1 text-[11px] font-semibold uppercase tracking-wide text-textSecondary">Choose workspace</div>
                                            <div class="mt-1 space-y-1">
                                                @foreach ($workspaces as $workspace)
                                                    <form method="POST" action="{{ route('admin.organizations.impersonate', $organization) }}" data-impersonate-form>
                                                        @csrf
                                                        <input type="hidden" name="workspace_id" value="{{ $workspace->id }}">
                                                        <button
                                                            type="submit"
                                                            class="pl-action-menu__item justify-between text-xs"
                                                            data-impersonate-button
                                                            data-loading-text="Opening..."
                                                        >
                                                            <span class="truncate">{{ $workspace->display_name ?: $workspace->name }}</span>
                                                            <span class="ml-3 shrink-0 text-textSecondary">Open</span>
                                                        </button>
                                                    </form>
                                                @endforeach
                                            </div>
                                        </div>
                                    </details>
                                @endif
                            @endcan

                            @if ($organization->isArchived())
                                <form method="POST" action="{{ route('admin.organizations.unarchive', $organization) }}" onsubmit="return confirm('Restore this organization from archive?');">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center rounded border border-border px-2 py-1 text-xs font-medium hover:bg-background">
                                        Restore
                                    </button>
                                </form>
                            @elseif ($organization->isOnHold())
                                <form method="POST" action="{{ route('admin.organizations.activate', $organization) }}">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center rounded border border-border px-2 py-1 text-xs font-medium hover:bg-background">
                                        Activate
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('admin.organizations.archive', $organization) }}" onsubmit="return confirm('Archive this organization? It will be hidden from active operations.');">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center rounded border border-slate-300/80 bg-slate-500/10 px-2 py-1 text-xs font-medium text-slate-600 hover:bg-slate-500/20">
                                        Archive
                                    </button>
                                </form>
                            @elseif ($organization->isActive())
                                <form method="POST" action="{{ route('admin.organizations.hold', $organization) }}" onsubmit="return confirm('Set this organization on hold? Customer operations will be restricted.');">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center rounded border border-amber-300/80 bg-amber-500/10 px-2 py-1 text-xs font-medium text-amber-800 hover:bg-amber-500/20">
                                        Deactivate
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('admin.organizations.archive', $organization) }}" onsubmit="return confirm('Archive this organization? It will be hidden from active operations.');">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center rounded border border-slate-300/80 bg-slate-500/10 px-2 py-1 text-xs font-medium text-slate-600 hover:bg-slate-500/20">
                                        Archive
                                    </button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('admin.organizations.activate', $organization) }}">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center rounded border border-border px-2 py-1 text-xs font-medium hover:bg-background">
                                        Activate
                                    </button>
                                </form>
                            @endif

                            @can('admin-area-superadmin')
                                <a href="{{ route('admin.organizations.confirm-delete', $organization) }}" class="inline-flex items-center rounded border border-rose-300/80 bg-rose-500/10 px-2 py-1 text-xs font-medium text-rose-800 hover:bg-rose-500/20">
                                    Delete
                                </a>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td class="py-6 text-center text-textSecondary" colspan="5">No organizations found.</td>
                </tr>
            @endforelse
        </tbody>
    </x-responsive-table>

    <div class="mt-4">{{ $organizations->links() }}</div>

    @once
        <script>
            (() => {
                document.querySelectorAll('[data-impersonate-form]').forEach((form) => {
                    form.addEventListener('submit', () => {
                        const button = form.querySelector('[data-impersonate-button]');
                        if (!button || button.disabled) {
                            return false;
                        }

                        button.disabled = true;

                        const label = button.querySelector('span:last-child');
                        const loadingText = button.dataset.loadingText || 'Opening...';

                        if (label) {
                            label.textContent = loadingText;
                        } else {
                            button.textContent = loadingText;
                        }
                    }, { once: true });
                });

                document.addEventListener('click', (event) => {
                    document.querySelectorAll('[data-impersonate-selector]').forEach((selector) => {
                        if (!selector.contains(event.target)) {
                            selector.removeAttribute('open');
                        }
                    });
                });
            })();
        </script>
    @endonce
@endsection
