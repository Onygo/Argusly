@extends('layouts.admin', ['title' => 'Organization detail'])

@php
    $primaryUser = $organization->primaryUser
        ?? $organization->users->sortBy('created_at')->firstWhere('role', 'owner')
        ?? $organization->users->sortBy('created_at')->first();
    $organizationActivated = $organization->isActive();
    $primaryUserActivated = $primaryUser && $primaryUser->active && $primaryUser->approved_at;
    $needsActivation = ! $organizationActivated || ! $primaryUserActivated;
    $billingAddress = is_array($organization->billing_address) ? $organization->billing_address : [];

    $statusLabel = $organization->getStatusLabel();
    $statusClass = $organization->getStatusBadgeClasses();
    $accessLabel = $organizationAccess['label'] ?? 'Free';
    $accessClass = $organizationAccess['badge_classes'] ?? 'border-border bg-background text-textSecondary';
@endphp

@section('pageHeader')
    <x-page-header :title="$organization->name">
        <x-slot:actions>
            <span class="inline-flex items-center rounded-md border px-2.5 py-1 text-xs font-medium {{ $statusClass }}">{{ $statusLabel }}</span>
            <span class="inline-flex items-center rounded-md border px-2.5 py-1 text-xs font-medium {{ $accessClass }}">{{ $accessLabel }}</span>
        </x-slot:actions>
    </x-page-header>
@endsection

@section('pageDescription')
    <x-page-description>Slug: {{ $organization->slug }} · {{ $organization->users->count() }} users · {{ $organization->workspaces->count() }} workspaces</x-page-description>
@endsection

@section('primaryActions')
    <a href="{{ route('admin.organizations') }}" class="inline-flex items-center rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-background">Back to organizations</a>
@endsection

@section('metricSection')
    <x-metric-section>
        <x-metric-card label="Users" :value="$organization->users->count()" />
        <x-metric-card label="Workspaces" :value="$organization->workspaces->count()" />
        <x-metric-card label="Organization status" :value="$statusLabel" />
        <x-metric-card label="Account access" :value="$accessLabel" />
    </x-metric-section>
@endsection

@section('content')

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if (session('new_api_key'))
        <x-alert class="mb-4">
            New API key (copy now): <code>{{ session('new_api_key') }}</code>
        </x-alert>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <x-settings.section-card title="Overview" description="Read-only organization summary and operational account state.">
                <dl class="grid gap-4 md:grid-cols-2">
                    <div>
                        <dt class="text-xs text-textSecondary">Organization name</dt>
                        <dd class="mt-1 text-sm font-medium text-textPrimary">{{ $organization->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Status</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $statusLabel }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Account access</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $accessLabel }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Slug</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $organization->slug }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Primary user</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $primaryUser?->email ?? 'n/a' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Legal name</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $organization->legal_name ?: $organization->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Billing email</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $organization->billing_email ?: 'n/a' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">VAT ID</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $organization->vat_id ?: 'n/a' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">API enabled</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $organization->api_enabled ? 'Yes' : 'No' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Custom domain</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $organization->custom_domain ?: 'n/a' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Webhook URL</dt>
                        <dd class="mt-1 text-sm text-textPrimary break-all">{{ $organization->webhook_url ?: 'n/a' }}</dd>
                    </div>
                    <div class="md:col-span-2">
                        <dt class="text-xs text-textSecondary">Billing address</dt>
                        <dd class="mt-1 text-sm text-textPrimary">
                            {{ $billingAddress['line1'] ?? '' }}
                            @if (! empty($billingAddress['line2']))
                                · {{ $billingAddress['line2'] }}
                            @endif
                            @if (! empty($billingAddress['postal_code']) || ! empty($billingAddress['city']) || ! empty($billingAddress['country_code']))
                                · {{ trim(($billingAddress['postal_code'] ?? '') . ' ' . ($billingAddress['city'] ?? '')) }}
                                @if (! empty($billingAddress['country_code']))
                                    ({{ strtoupper((string) $billingAddress['country_code']) }})
                                @endif
                            @endif
                            @if (empty($billingAddress['line1']) && empty($billingAddress['line2']) && empty($billingAddress['postal_code']) && empty($billingAddress['city']) && empty($billingAddress['country_code']))
                                n/a
                            @endif
                        </dd>
                    </div>
                </dl>
            </x-settings.section-card>

            <x-settings.section-card title="Organization settings" description="Platform-level organization configuration used across all workspaces.">
                @can('admin-area-superadmin')
                    <form method="POST" action="{{ route('admin.organizations.update', $organization) }}" class="space-y-4">
                        @csrf
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="md:col-span-2">
                                <label class="mb-1 block text-xs text-textSecondary">Organization name</label>
                                <input name="name" value="{{ old('name', $organization->name) }}" class="w-full rounded border border-border px-3 py-2 text-sm" maxlength="190" required>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-textSecondary">Slug</label>
                                <input name="slug" value="{{ old('slug', $organization->slug) }}" class="w-full rounded border border-border px-3 py-2 text-sm" maxlength="190" required>
                                <p class="mt-1 text-xs text-textSecondary">Technical identifier used in admin links and references.</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-textSecondary">Custom domain</label>
                                <input name="custom_domain" value="{{ old('custom_domain', $organization->custom_domain) }}" class="w-full rounded border border-border px-3 py-2 text-sm" maxlength="190" placeholder="example.com">
                            </div>
                            <div class="md:col-span-2">
                                <label class="mb-1 block text-xs text-textSecondary">Webhook URL</label>
                                <input name="webhook_url" value="{{ old('webhook_url', $organization->webhook_url) }}" class="w-full rounded border border-border px-3 py-2 text-sm" maxlength="255" placeholder="https://...">
                            </div>
                        </div>
                        <label class="inline-flex items-center gap-2 text-sm text-textPrimary">
                            <input type="checkbox" name="api_enabled" value="1" @checked(old('api_enabled', $organization->api_enabled))>
                            API enabled
                        </label>
                        <x-settings.form-actions>
                            <button class="inline-flex items-center justify-center rounded-md border border-border px-3 py-2 text-sm font-medium">Save organization settings</button>
                        </x-settings.form-actions>
                    </form>
                @else
                    <x-settings.empty-state title="Read-only for your admin role" description="Only superadmins can edit organization settings from this workspace." />
                @endcan
            </x-settings.section-card>

            <x-settings.section-card title="Legal and billing profile" description="Legal identity and billing contact details used for invoicing.">
                @can('admin-area-superadmin')
                    <form method="POST" action="{{ route('admin.organizations.legal-profile.update', $organization) }}" class="space-y-4">
                        @csrf
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs text-textSecondary">Legal name</label>
                                <input name="legal_name" value="{{ old('legal_name', $organization->legal_name) }}" class="w-full rounded border border-border px-3 py-2 text-sm" maxlength="200" placeholder="Legal company name">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-textSecondary">Billing email</label>
                                <input name="billing_email" value="{{ old('billing_email', $organization->billing_email) }}" class="w-full rounded border border-border px-3 py-2 text-sm" maxlength="255" placeholder="billing@company.com">
                            </div>
                            <div class="md:col-span-2">
                                <label class="mb-1 block text-xs text-textSecondary">VAT ID</label>
                                <input name="vat_id" value="{{ old('vat_id', $organization->vat_id) }}" class="w-full rounded border border-border px-3 py-2 text-sm" maxlength="64" placeholder="VAT ID">
                            </div>
                            <div class="md:col-span-2">
                                <label class="mb-1 block text-xs text-textSecondary">Billing address line 1</label>
                                <input name="billing_address_line1" value="{{ old('billing_address_line1', $billingAddress['line1'] ?? '') }}" class="w-full rounded border border-border px-3 py-2 text-sm" maxlength="255">
                            </div>
                            <div class="md:col-span-2">
                                <label class="mb-1 block text-xs text-textSecondary">Billing address line 2</label>
                                <input name="billing_address_line2" value="{{ old('billing_address_line2', $billingAddress['line2'] ?? '') }}" class="w-full rounded border border-border px-3 py-2 text-sm" maxlength="255">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-textSecondary">Postal code</label>
                                <input name="billing_postal_code" value="{{ old('billing_postal_code', $billingAddress['postal_code'] ?? '') }}" class="w-full rounded border border-border px-3 py-2 text-sm" maxlength="64">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-textSecondary">City</label>
                                <input name="billing_city" value="{{ old('billing_city', $billingAddress['city'] ?? '') }}" class="w-full rounded border border-border px-3 py-2 text-sm" maxlength="128">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-textSecondary">Country</label>
                                <input name="billing_country_code" value="{{ old('billing_country_code', $billingAddress['country_code'] ?? '') }}" class="w-full rounded border border-border px-3 py-2 text-sm" maxlength="2" placeholder="NL">
                            </div>
                        </div>
                        <x-settings.form-actions>
                            <button class="inline-flex items-center justify-center rounded-md border border-border px-3 py-2 text-sm font-medium">Save legal profile</button>
                        </x-settings.form-actions>
                    </form>
                @else
                    <x-settings.empty-state title="Read-only for your admin role" description="Only superadmins can update legal and billing profile fields." />
                @endcan
            </x-settings.section-card>

            <x-settings.section-card title="Account access" description="Manage commercial access state for this organization.">
                <div class="space-y-4">
                    <dl class="grid gap-4 md:grid-cols-2">
                        <div>
                            <dt class="text-xs text-textSecondary">Current access tier</dt>
                            <dd class="mt-1">
                                <span class="inline-flex items-center rounded-md border px-2.5 py-1 text-xs font-medium {{ $accessClass }}">{{ $accessLabel }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-textSecondary">Updated by</dt>
                            <dd class="mt-1 text-sm text-textPrimary">{{ $organization->accessUpdatedBy?->email ?? 'n/a' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-textSecondary">Early Bird start date</dt>
                            <dd class="mt-1 text-sm text-textPrimary">{{ optional($organization->early_bird_started_at)->format('Y-m-d H:i') ?? 'n/a' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-textSecondary">Early Bird end date</dt>
                            <dd class="mt-1 text-sm text-textPrimary">
                                {{ optional($organization->early_bird_ends_at)->format('Y-m-d') ?? 'n/a' }}
                                @if (($organizationAccess['is_early_bird_active'] ?? false) && $organization->early_bird_ends_at)
                                    <div class="mt-1 text-xs text-textSecondary">Early Bird active until {{ $organization->early_bird_ends_at->format('Y-m-d') }}</div>
                                @elseif ($organizationAccess['is_early_bird_expired'] ?? false)
                                    <div class="mt-1 text-xs text-amber-800">Early Bird expired</div>
                                @endif
                            </dd>
                        </div>
                        <div class="md:col-span-2">
                            <dt class="text-xs text-textSecondary">Note</dt>
                            <dd class="mt-1 whitespace-pre-line text-sm text-textPrimary">{{ $organization->early_bird_note ?: 'n/a' }}</dd>
                        </div>
                    </dl>

                    <div class="rounded-lg border border-border bg-background p-3 text-xs text-textSecondary">
                        Temporary access for testing, onboarding or commercial pilots.
                    </div>

                    @if (auth()->user()?->is_admin)
                        <div class="grid gap-4 xl:grid-cols-2">
                            <form method="POST" action="{{ route('admin.organizations.access.grant-early-bird', $organization) }}" class="space-y-3 rounded-lg border border-border p-4" onsubmit="return confirm('Grant Early Bird access for this organization?');">
                                @csrf
                                <div class="text-sm font-medium text-textPrimary">Grant Early Bird access</div>
                                <div>
                                    <label class="mb-1 block text-xs text-textSecondary">End date</label>
                                    <input type="date" name="early_bird_ends_at" value="{{ old('early_bird_ends_at', optional($organization->early_bird_ends_at)->toDateString()) }}" class="w-full rounded border border-border px-3 py-2 text-sm" required>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs text-textSecondary">Note</label>
                                    <textarea name="early_bird_note" rows="3" class="w-full rounded border border-border px-3 py-2 text-sm" placeholder="Reason for temporary access">{{ old('early_bird_note', $organization->early_bird_note) }}</textarea>
                                </div>
                                <button class="inline-flex items-center justify-center rounded-md border border-border px-3 py-2 text-sm font-medium">Grant Early Bird access</button>
                            </form>

                            <div class="space-y-3 rounded-lg border border-border p-4">
                                <div class="text-sm font-medium text-textPrimary">Access actions</div>
                                <form method="POST" action="{{ route('admin.organizations.access.extend-early-bird', $organization) }}" class="space-y-3" onsubmit="return confirm('Extend Early Bird access for this organization?');">
                                    @csrf
                                    <div>
                                        <label class="mb-1 block text-xs text-textSecondary">New end date</label>
                                        <input type="date" name="early_bird_ends_at" value="{{ old('early_bird_ends_at', optional($organization->early_bird_ends_at)->toDateString()) }}" class="w-full rounded border border-border px-3 py-2 text-sm" required>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs text-textSecondary">Note</label>
                                        <textarea name="early_bird_note" rows="2" class="w-full rounded border border-border px-3 py-2 text-sm" placeholder="Optional update note">{{ old('early_bird_note', $organization->early_bird_note) }}</textarea>
                                    </div>
                                    <button class="inline-flex items-center justify-center rounded-md border border-border px-3 py-2 text-sm font-medium">Extend Early Bird</button>
                                </form>

                                <form method="POST" action="{{ route('admin.organizations.access.convert-to-paid', $organization) }}" onsubmit="return confirm('Convert this organization to a paid account?');">
                                    @csrf
                                    <button class="w-full inline-flex items-center justify-center rounded-md border border-emerald-700/20 bg-emerald-500/10 px-3 py-2 text-sm font-medium text-emerald-900">Convert to paid account</button>
                                </form>

                                <form method="POST" action="{{ route('admin.organizations.access.end-early-bird', $organization) }}" onsubmit="return confirm('End Early Bird access now?');">
                                    @csrf
                                    <button class="w-full inline-flex items-center justify-center rounded-md border border-amber-700/20 bg-amber-500/10 px-3 py-2 text-sm font-medium text-amber-900">End Early Bird</button>
                                </form>
                            </div>
                        </div>
                    @else
                        <x-settings.empty-state title="Read-only for your admin role" description="Only platform admins can change organization access state." />
                    @endif
                </div>
            </x-settings.section-card>

            <x-settings.section-card title="Users" description="Organization members, role assignments, and account state.">
                <x-data-table label="Organization users" description="Organization members with role, access status, and account actions." density="compact" class="border-0 shadow-none">
                    <x-data-table.header>
                        <x-data-table.row>
                            <x-data-table.cell heading>Name</x-data-table.cell>
                            <x-data-table.cell heading>Email</x-data-table.cell>
                            <x-data-table.cell heading>Role</x-data-table.cell>
                            <x-data-table.cell heading>Status</x-data-table.cell>
                            <x-data-table.cell heading>Actions</x-data-table.cell>
                        </x-data-table.row>
                    </x-data-table.header>
                    <tbody class="divide-y divide-border">
                        @forelse ($organization->users as $user)
                            @php($isUserActive = $user->active && $user->approved_at)
                            @php($latestAccessOverrideStatus = $user->latestAccessOverride?->effectiveStatus())
                            <x-data-table.row>
                                <x-data-table.cell label="Name" class="text-textPrimary">
                                    <a href="{{ route('admin.users.show', $user) }}" class="font-medium hover:underline">{{ $user->name }}</a>
                                </x-data-table.cell>
                                <x-data-table.cell label="Email" class="text-textPrimary">
                                    <div>{{ $user->email }}</div>
                                    @if ($latestAccessOverrideStatus)
                                        <div class="mt-1">
                                            <span class="inline-flex items-center rounded border px-2 py-0.5 text-[11px] font-medium {{ $latestAccessOverrideStatus->badgeClasses() }}">
                                                {{ $latestAccessOverrideStatus->label() }}
                                            </span>
                                        </div>
                                    @endif
                                </x-data-table.cell>
                                <x-data-table.cell label="Role" class="text-textPrimary">{{ $user->role }}</x-data-table.cell>
                                <x-data-table.cell label="Status">
                                    <x-data-table.badge :tone="$isUserActive ? 'success' : 'warning'" :label="$isUserActive ? 'Active' : 'Pending'" />
                                </x-data-table.cell>
                                <x-data-table.cell label="Actions">
                                    <x-data-table.actions align="start">
                                        @if (! $user->approved_at)
                                            <form method="POST" action="{{ route('admin.users.approve', $user) }}">
                                                @csrf
                                                <button class="inline-flex items-center justify-center rounded-md border border-border px-3 py-1 text-xs font-medium">Approve</button>
                                            </form>
                                        @endif
                                        @if ($user->active)
                                            <form method="POST" action="{{ route('admin.users.disable', $user) }}" onsubmit="return confirm('Disable this user account? They will lose access until reactivated.');">
                                                @csrf
                                                <button class="inline-flex items-center justify-center rounded-md border border-border px-3 py-1 text-xs font-medium">Disable user</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('admin.users.activate', $user) }}">
                                                @csrf
                                                <button class="inline-flex items-center justify-center rounded-md border border-border px-3 py-1 text-xs font-medium">Activate user</button>
                                            </form>
                                        @endif
                                    </x-data-table.actions>
                                </x-data-table.cell>
                            </x-data-table.row>
                        @empty
                            <x-data-table.empty colspan="5" title="No users found for this organization" />
                        @endforelse
                    </tbody>
                </x-data-table>
            </x-settings.section-card>

            <x-settings.section-card title="Workspaces" description="Workspace operations, site footprint, and context switching for support.">
                <x-data-table label="Organization workspaces" description="Workspace names, site counts, notifications, and impersonation controls." density="compact" class="border-0 shadow-none">
                    <x-data-table.header>
                        <x-data-table.row>
                            <x-data-table.cell heading>Workspace</x-data-table.cell>
                            <x-data-table.cell heading>Sites</x-data-table.cell>
                            <x-data-table.cell heading>Actions</x-data-table.cell>
                        </x-data-table.row>
                    </x-data-table.header>
                    <tbody class="divide-y divide-border">
                        @forelse ($organization->workspaces as $workspace)
                            <x-data-table.row>
                                <x-data-table.cell label="Workspace">
                                    <div class="text-sm font-medium text-textPrimary">{{ $workspace->display_name ?: $workspace->name }}</div>
                                    <div class="mt-2">
                                        @can('admin-area-superadmin')
                                            <form method="POST" action="{{ route('admin.organizations.workspaces.display-name.update', [$organization, $workspace]) }}" class="flex flex-wrap items-center gap-2">
                                                @csrf
                                                <input
                                                    name="display_name"
                                                    value="{{ old('display_name', $workspace->display_name ?: $workspace->name) }}"
                                                    class="w-full rounded border border-border px-2 py-1 text-xs md:w-72"
                                                    maxlength="120"
                                                    required
                                                >
                                                <button class="inline-flex items-center justify-center rounded-md border border-border px-2 py-1 text-xs font-medium">
                                                    Save name
                                                </button>
                                            </form>
                                        @endcan
                                    </div>
                                </x-data-table.cell>
                                <x-data-table.cell label="Sites" class="text-textPrimary">{{ $workspace->clientSites->count() }}</x-data-table.cell>
                                <x-data-table.cell label="Actions">
                                    <x-data-table.actions align="start">
                                        <a href="{{ route('admin.workspaces.notifications', $workspace) }}" class="inline-flex items-center justify-center rounded-md border border-border px-3 py-1 text-xs font-medium">Notifications</a>
                                        @can('admin-area-access')
                                            <form method="POST" action="{{ route('admin.workspaces.impersonate', $workspace) }}" onsubmit="return confirm('Impersonate this workspace using its primary active user context?');">
                                                @csrf
                                                <button class="inline-flex items-center justify-center rounded-md border border-border px-3 py-1 text-xs font-medium">Impersonate workspace</button>
                                            </form>
                                        @endcan
                                    </x-data-table.actions>
                                </x-data-table.cell>
                            </x-data-table.row>
                        @empty
                            <x-data-table.empty colspan="3" title="No workspaces linked to this organization" />
                        @endforelse
                    </tbody>
                </x-data-table>
            </x-settings.section-card>
        </div>

        <div class="space-y-6">
            <x-settings.section-card title="Admin actions" description="Operational controls and account context for platform admins.">
                <div class="space-y-4 text-sm">
                    <div class="rounded-lg border border-border bg-background p-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Organization state</p>
                        <div class="mt-2 space-y-2">
                            <div class="flex items-center justify-between">
                                <span class="text-textSecondary">Company activation</span>
                                <span class="text-textPrimary">{{ $organizationActivated ? 'Active' : 'Pending' }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-4">
                                <span class="text-textSecondary">Primary user</span>
                                <span class="text-right text-textPrimary">{{ $primaryUser ? $primaryUser->email : 'n/a' }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-lg border border-border bg-background p-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Operational actions</p>
                        <div class="mt-2 space-y-2">
                            @if ($needsActivation)
                                <form method="POST" action="{{ route('admin.organizations.activate', $organization) }}">
                                    @csrf
                                    <button class="w-full inline-flex items-center justify-center rounded-md border border-border px-3 py-2 text-sm font-medium">Activate customer</button>
                                </form>
                            @endif
                            @can('admin-area-manage-billing')
                                <a class="w-full inline-flex items-center justify-center rounded-md border border-border px-3 py-2 text-sm font-medium" href="{{ route('admin.organizations.billing', $organization) }}">Open billing workspace</a>
                            @else
                                <p class="text-xs text-textSecondary">Billing actions are available to superadmins.</p>
                            @endcan
                        </div>
                    </div>
                </div>
            </x-settings.section-card>

            <x-settings.section-card title="Lifecycle actions" description="Manage organization status and lifecycle.">
                <div class="space-y-3 text-sm">
                    @if ($organization->isActive())
                        <div class="rounded-lg border border-amber-300/70 bg-amber-500/10 p-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-amber-900">Deactivate organization</p>
                            <p class="mt-1 text-xs text-amber-900/90">Setting an organization on hold blocks normal customer operations until reactivated.</p>
                            <form method="POST" action="{{ route('admin.organizations.hold', $organization) }}" class="mt-3" onsubmit="return confirm('Set this organization to on hold? This will restrict customer operations until reactivated.');">
                                @csrf
                                <button class="w-full inline-flex items-center justify-center rounded-md border border-amber-700/30 bg-white/60 px-3 py-2 text-sm font-medium text-amber-900">Deactivate organization</button>
                            </form>
                        </div>

                        <div class="rounded-lg border border-slate-300/70 bg-slate-500/10 p-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-700">Archive organization</p>
                            <p class="mt-1 text-xs text-slate-600">Archiving removes the organization from active lists but preserves all data.</p>
                            <form method="POST" action="{{ route('admin.organizations.archive', $organization) }}" class="mt-3" onsubmit="return confirm('Archive this organization? It will be hidden from active operations but can be restored later.');">
                                @csrf
                                <button class="w-full inline-flex items-center justify-center rounded-md border border-slate-500/30 bg-white/60 px-3 py-2 text-sm font-medium text-slate-700">Archive organization</button>
                            </form>
                        </div>
                    @elseif ($organization->isOnHold())
                        <div class="rounded-lg border border-emerald-300/70 bg-emerald-500/10 p-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-900">Activate organization</p>
                            <p class="mt-1 text-xs text-emerald-900/90">Re-enable customer operations for this organization.</p>
                            <form method="POST" action="{{ route('admin.organizations.activate', $organization) }}" class="mt-3">
                                @csrf
                                <button class="w-full inline-flex items-center justify-center rounded-md border border-emerald-700/30 bg-white/60 px-3 py-2 text-sm font-medium text-emerald-900">Activate organization</button>
                            </form>
                        </div>

                        <div class="rounded-lg border border-slate-300/70 bg-slate-500/10 p-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-700">Archive organization</p>
                            <p class="mt-1 text-xs text-slate-600">Archiving removes the organization from active lists but preserves all data.</p>
                            <form method="POST" action="{{ route('admin.organizations.archive', $organization) }}" class="mt-3" onsubmit="return confirm('Archive this organization? It will be hidden from active operations but can be restored later.');">
                                @csrf
                                <button class="w-full inline-flex items-center justify-center rounded-md border border-slate-500/30 bg-white/60 px-3 py-2 text-sm font-medium text-slate-700">Archive organization</button>
                            </form>
                        </div>
                    @elseif ($organization->isArchived())
                        <div class="rounded-lg border border-emerald-300/70 bg-emerald-500/10 p-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-900">Restore from archive</p>
                            <p class="mt-1 text-xs text-emerald-900/90">Unarchive this organization and set it to on-hold status for review.</p>
                            <form method="POST" action="{{ route('admin.organizations.unarchive', $organization) }}" class="mt-3" onsubmit="return confirm('Restore this organization from archive? It will be set to on-hold status.');">
                                @csrf
                                <button class="w-full inline-flex items-center justify-center rounded-md border border-emerald-700/30 bg-white/60 px-3 py-2 text-sm font-medium text-emerald-900">Restore organization</button>
                            </form>
                        </div>
                    @else
                        {{-- Pending status --}}
                        <div class="rounded-lg border border-emerald-300/70 bg-emerald-500/10 p-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-900">Activate organization</p>
                            <p class="mt-1 text-xs text-emerald-900/90">Approve and activate this pending organization.</p>
                            <form method="POST" action="{{ route('admin.organizations.activate', $organization) }}" class="mt-3">
                                @csrf
                                <button class="w-full inline-flex items-center justify-center rounded-md border border-emerald-700/30 bg-white/60 px-3 py-2 text-sm font-medium text-emerald-900">Activate organization</button>
                            </form>
                        </div>
                    @endif
                </div>
            </x-settings.section-card>

            <x-settings.section-card title="Advanced and danger actions" description="High-impact actions require explicit confirmation.">
                <div class="space-y-3 text-sm">
                    @can('admin-area-superadmin')
                        <div class="rounded-lg border border-amber-300/70 bg-amber-500/10 p-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-amber-900">Technical risk</p>
                            <p class="mt-1 text-xs text-amber-900/90">Regenerating the organization API key immediately invalidates existing API integrations.</p>
                            <form method="POST" action="{{ route('admin.organizations.api-key.regenerate', $organization) }}" class="mt-3" onsubmit="return confirm('Regenerate this organization API key now? Existing integrations will stop working until updated.');">
                                @csrf
                                <button class="w-full inline-flex items-center justify-center rounded-md border border-amber-700/30 bg-white/60 px-3 py-2 text-sm font-medium text-amber-900">Regenerate API key</button>
                            </form>
                        </div>

                        <div class="rounded-lg border border-rose-300/70 bg-rose-500/10 p-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-rose-900">Permanent deletion</p>
                            <p class="mt-1 text-xs text-rose-900/90">Permanently delete this organization and all related data. This action cannot be undone.</p>
                            <a href="{{ route('admin.organizations.confirm-delete', $organization) }}" class="mt-3 w-full inline-flex items-center justify-center rounded-md border border-rose-700/30 bg-white/60 px-3 py-2 text-sm font-medium text-rose-900">Delete organization</a>
                        </div>
                    @else
                        <p class="text-xs text-textSecondary">Advanced actions are available to superadmins only.</p>
                    @endcan
                </div>
            </x-settings.section-card>
        </div>
    </div>
@endsection
