@extends('layouts.admin', ['title' => 'User detail'])

@section('content')
    @php
        $userStatusLabel = ! $managedUser->approved_at
            ? 'Pending'
            : ($managedUser->active ? 'Active' : 'Disabled');
        $userStatusClass = ! $managedUser->approved_at
            ? 'border-amber-300/80 bg-amber-500/10 text-amber-800'
            : ($managedUser->active ? 'border-emerald-300/80 bg-emerald-500/10 text-emerald-800' : 'border-border bg-background text-textSecondary');
        $latestAccessOverride = $managedUser->latestAccessOverride;
        $latestAccessOverrideStatus = $latestAccessOverride?->effectiveStatus();
        $activeOverrideStatus = $activeAccessOverride?->effectiveStatus();
        $formAction = $openAccessOverride
            ? route('admin.users.access-overrides.extend', [$managedUser, $openAccessOverride])
            : route('admin.users.access-overrides.store', $managedUser);
        $defaultStartsAt = old('starts_at', now()->format('Y-m-d\TH:i'));
        $defaultType = old('type', $openAccessOverride?->type?->value ?? \App\Enums\AccessOverrideType::EARLY_ACCESS->value);
        $defaultEndsAt = old('ends_at', optional($openAccessOverride?->ends_at)->format('Y-m-d\TH:i'));
        $defaultReason = old('reason', $openAccessOverride?->reason);
        $defaultNotes = old('notes', $openAccessOverride?->notes);
    @endphp

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">{{ $managedUser->name }}</h1>
            <p class="mt-1 text-sm text-textSecondary">{{ $managedUser->email }}</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center rounded-md border px-2.5 py-1 text-xs font-medium {{ $userStatusClass }}">
                {{ $userStatusLabel }}
            </span>
            @if ($latestAccessOverrideStatus)
                <span class="inline-flex items-center rounded-md border px-2.5 py-1 text-xs font-medium {{ $latestAccessOverrideStatus->badgeClasses() }}">
                    {{ $latestAccessOverrideStatus->label() }}
                </span>
            @endif
            <a href="{{ route('admin.users') }}" class="inline-flex items-center rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-background">Back to users</a>
        </div>
    </div>

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <x-settings.section-card title="Overview" description="Core user and organization context for support and approvals.">
                <dl class="grid gap-4 md:grid-cols-2">
                    <div>
                        <dt class="text-xs text-textSecondary">Name</dt>
                        <dd class="mt-1 text-sm font-medium text-textPrimary">{{ $managedUser->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Email</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $managedUser->email }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Organization</dt>
                        <dd class="mt-1 text-sm text-textPrimary">
                            @if ($managedUser->organization)
                                <a href="{{ route('admin.organizations.show', $managedUser->organization) }}" class="hover:underline">
                                    {{ $managedUser->organization->name }}
                                </a>
                            @else
                                n/a
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Role</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $managedUser->role }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Admin role</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $managedUser->resolvedAdminRole() }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Created</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $managedUser->created_at?->format('Y-m-d H:i') }}</dd>
                    </div>
                </dl>
            </x-settings.section-card>

            <x-settings.section-card title="Workspaces" description="Current organization workspace footprint for this user context.">
                <div class="space-y-3 text-sm">
                    @forelse ($managedUser->organization?->workspaces ?? [] as $workspace)
                        <div class="rounded border border-border bg-background px-3 py-3">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="font-medium text-textPrimary">{{ $workspace->display_name }}</p>
                                    <p class="mt-1 text-xs text-textSecondary">{{ $workspace->clientSites->count() }} connected sites</p>
                                </div>
                                @can('admin-area-superadmin')
                                    <form method="POST" action="{{ route('admin.workspaces.impersonate', $workspace) }}" onsubmit="return confirm('Impersonate this workspace using its primary active user context?');">
                                        @csrf
                                        <button class="inline-flex items-center justify-center rounded-md border border-border px-3 py-2 text-xs font-medium">Impersonate</button>
                                    </form>
                                @endcan
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-textSecondary">No organization workspaces linked to this user.</p>
                    @endforelse
                </div>
            </x-settings.section-card>

            @if ($managedUser->is(auth()->user()))
                <x-settings.section-card title="Password" description="Update the password for your own administrator account.">
                    <form method="POST" action="{{ route('admin.users.password.update', $managedUser) }}" class="grid gap-4 md:grid-cols-2">
                        @csrf
                        <div class="md:col-span-2">
                            <label for="current_password" class="mb-1 block text-xs text-textSecondary">Current password</label>
                            <input id="current_password" name="current_password" type="password" autocomplete="current-password" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" required>
                            @error('current_password')
                                <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="password" class="mb-1 block text-xs text-textSecondary">New password</label>
                            <input id="password" name="password" type="password" autocomplete="new-password" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" required>
                            @error('password')
                                <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="password_confirmation" class="mb-1 block text-xs text-textSecondary">Confirm new password</label>
                            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" required>
                        </div>
                        <div class="md:col-span-2">
                            <button class="inline-flex items-center justify-center rounded-md border border-border px-3 py-2 text-sm font-medium hover:bg-background">
                                Update password
                            </button>
                        </div>
                    </form>
                </x-settings.section-card>
            @endif

            <x-settings.section-card title="Access Override History" description="Audit-friendly history of manual billing bypass periods.">
                <div class="space-y-3">
                    @forelse ($managedUser->accessOverrides as $override)
                        @php($effectiveStatus = $override->effectiveStatus())
                        <div class="rounded border border-border bg-background px-3 py-3">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <p class="text-sm font-medium text-textPrimary">{{ $override->type->label() }}</p>
                                        <span class="inline-flex items-center rounded border px-2 py-0.5 text-[11px] font-medium {{ $effectiveStatus->badgeClasses() }}">
                                            {{ $effectiveStatus->label() }}
                                        </span>
                                    </div>
                                    <p class="mt-1 text-xs text-textSecondary">{{ $override->uiMessage() }}</p>
                                </div>
                                <div class="text-right text-xs text-textSecondary">
                                    <div>Created {{ $override->created_at?->format('Y-m-d H:i') }}</div>
                                    <div>By {{ $override->createdBy?->email ?? 'system' }}</div>
                                </div>
                            </div>
                            <dl class="mt-3 grid gap-3 md:grid-cols-2 text-sm">
                                <div>
                                    <dt class="text-xs text-textSecondary">Starts at</dt>
                                    <dd class="mt-1 text-textPrimary">{{ $override->starts_at?->format('Y-m-d H:i') ?? 'Immediate' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-textSecondary">Ends at</dt>
                                    <dd class="mt-1 text-textPrimary">{{ $override->ends_at?->format('Y-m-d H:i') ?? 'No end date' }}</dd>
                                </div>
                                <div class="md:col-span-2">
                                    <dt class="text-xs text-textSecondary">Reason</dt>
                                    <dd class="mt-1 whitespace-pre-wrap text-textPrimary">{{ $override->reason ?: 'n/a' }}</dd>
                                </div>
                                <div class="md:col-span-2">
                                    <dt class="text-xs text-textSecondary">Notes</dt>
                                    <dd class="mt-1 whitespace-pre-wrap text-textPrimary">{{ $override->notes ?: 'n/a' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-textSecondary">Ended at</dt>
                                    <dd class="mt-1 text-textPrimary">{{ $override->ended_at?->format('Y-m-d H:i') ?? 'n/a' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-textSecondary">Ended by</dt>
                                    <dd class="mt-1 text-textPrimary">{{ $override->endedBy?->email ?? 'n/a' }}</dd>
                                </div>
                            </dl>
                        </div>
                    @empty
                        <p class="text-sm text-textSecondary">No access overrides recorded for this user.</p>
                    @endforelse
                </div>
            </x-settings.section-card>
        </div>

        <div class="space-y-6">
            <x-settings.section-card title="Pilot Program" description="Add this existing account to pilot tracking and early-access override.">
                @if ($managedUser->organization)
                    <form method="POST" action="{{ route('admin.early-access.add-existing-user') }}" class="space-y-3" onsubmit="return confirm('Add this user to the Pilot Program?');">
                        @csrf
                        <input type="hidden" name="email" value="{{ $managedUser->email }}">
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary">Workspace</label>
                            <select name="workspace_id" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
                                <option value="">Use first workspace</option>
                                @foreach ($managedUser->organization?->workspaces ?? [] as $workspace)
                                    <option value="{{ $workspace->id }}">{{ $workspace->display_name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary">Override ends at</label>
                            <input type="datetime-local" name="ends_at" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary">Internal notes</label>
                            <textarea name="notes" rows="3" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">Existing user added from admin user detail.</textarea>
                        </div>
                        <button class="w-full inline-flex items-center justify-center gap-2 rounded-md border border-border px-3 py-2 text-sm font-medium">
                            <i data-lucide="user-plus" class="h-4 w-4"></i>
                            Add to Pilot Program
                        </button>
                    </form>
                @else
                    <p class="text-sm text-textSecondary">Link this user to an organization before adding pilot participation.</p>
                @endif
            </x-settings.section-card>

            <x-settings.section-card title="Current Override" description="Live override state and stop control.">
                @if ($activeAccessOverride)
                    <div class="rounded border border-border bg-background p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-textPrimary">{{ $activeAccessOverride->type->label() }}</p>
                                <p class="mt-1 text-xs text-textSecondary">{{ $activeAccessOverride->uiMessage() }}</p>
                            </div>
                            <span class="inline-flex items-center rounded border px-2 py-0.5 text-[11px] font-medium {{ $activeOverrideStatus?->badgeClasses() }}">
                                {{ $activeOverrideStatus?->label() }}
                            </span>
                        </div>
                        <form method="POST" action="{{ route('admin.users.access-overrides.stop', [$managedUser, $activeAccessOverride]) }}" class="mt-4" onsubmit="return confirm('Stop this access override now? Normal billing enforcement will apply again immediately.');">
                            @csrf
                            <button class="w-full inline-flex items-center justify-center rounded-md border border-rose-300/80 bg-rose-500/10 px-3 py-2 text-sm font-medium text-rose-800">Stop override now</button>
                        </form>
                    </div>
                @elseif ($openAccessOverride)
                    <div class="rounded border border-border bg-background p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-textPrimary">{{ $openAccessOverride->type->label() }}</p>
                                <p class="mt-1 text-xs text-textSecondary">{{ $openAccessOverride->uiMessage() }}</p>
                            </div>
                            <span class="inline-flex items-center rounded border px-2 py-0.5 text-[11px] font-medium {{ $openAccessOverride->effectiveStatus()->badgeClasses() }}">
                                {{ $openAccessOverride->effectiveStatus()->label() }}
                            </span>
                        </div>
                        <form method="POST" action="{{ route('admin.users.access-overrides.stop', [$managedUser, $openAccessOverride]) }}" class="mt-4" onsubmit="return confirm('Cancel this scheduled access override?');">
                            @csrf
                            <button class="w-full inline-flex items-center justify-center rounded-md border border-rose-300/80 bg-rose-500/10 px-3 py-2 text-sm font-medium text-rose-800">Cancel scheduled override</button>
                        </form>
                    </div>
                @else
                    <p class="text-sm text-textSecondary">No active or scheduled override for this user.</p>
                @endif
            </x-settings.section-card>

            <x-settings.section-card title="{{ $openAccessOverride ? 'Extend Override' : 'Create Access Override' }}" description="This temporarily bypasses normal billing enforcement and onboarding checkout requirements for this user.">
                <div class="mb-4 rounded border border-amber-300/70 bg-amber-500/10 px-3 py-2 text-xs text-amber-900">
                    Billing enforcement, checkout gating, and subscription onboarding are bypassed while this override is active.
                </div>

                <form method="POST" action="{{ $formAction }}" class="space-y-4">
                    @csrf
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Type</label>
                        <select name="type" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
                            @foreach (\App\Enums\AccessOverrideType::cases() as $type)
                                <option value="{{ $type->value }}" @selected($defaultType === $type->value)>{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Starts at</label>
                        <input type="datetime-local" name="starts_at" value="{{ $defaultStartsAt }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
                        <p class="mt-1 text-xs text-textSecondary">Leave as now for immediate access, or schedule a future start.</p>
                    </div>

                    <div>
                        <div class="mb-1 flex items-center justify-between gap-3">
                            <label class="block text-xs text-textSecondary">Ends at</label>
                            <div class="flex flex-wrap gap-1">
                                <button type="button" class="access-override-preset rounded border border-border px-2 py-1 text-[11px]" data-days="30">30 days</button>
                                <button type="button" class="access-override-preset rounded border border-border px-2 py-1 text-[11px]" data-days="60">60 days</button>
                                <button type="button" class="access-override-clear rounded border border-border px-2 py-1 text-[11px]">No end date</button>
                            </div>
                        </div>
                        <input id="access-override-ends-at" type="datetime-local" name="ends_at" value="{{ $defaultEndsAt }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Reason</label>
                        <textarea name="reason" rows="3" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">{{ $defaultReason }}</textarea>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Internal notes</label>
                        <textarea name="notes" rows="5" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">{{ $defaultNotes }}</textarea>
                    </div>

                    <x-settings.form-actions>
                        <button class="inline-flex items-center justify-center rounded-md border border-border px-3 py-2 text-sm font-medium">
                            {{ $openAccessOverride ? 'Create replacement override' : 'Create access override' }}
                        </button>
                    </x-settings.form-actions>
                </form>
            </x-settings.section-card>
        </div>
    </div>

    <script>
        (() => {
            const endsAt = document.getElementById('access-override-ends-at');
            if (! endsAt) {
                return;
            }

            const toLocalInput = (date) => {
                const pad = (value) => String(value).padStart(2, '0');
                return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
            };

            document.querySelectorAll('.access-override-preset').forEach((button) => {
                button.addEventListener('click', () => {
                    const days = Number(button.getAttribute('data-days') || '0');
                    if (! Number.isFinite(days) || days <= 0) {
                        return;
                    }

                    const date = new Date();
                    date.setDate(date.getDate() + days);
                    endsAt.value = toLocalInput(date);
                });
            });

            document.querySelector('.access-override-clear')?.addEventListener('click', () => {
                endsAt.value = '';
            });
        })();
    </script>
@endsection
