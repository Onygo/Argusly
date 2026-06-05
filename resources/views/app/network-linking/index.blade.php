@extends('layouts.app', ['title' => 'Network Linking'])

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Network Linking</h1>
        <p class="text-textSecondary mt-1">Manage link profiles and cross-domain permissions.</p>
    </div>

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    <div class="space-y-4">
        @foreach ($workspaces as $workspace)
            @php($profile = $workspace->linkProfile)
            <div class="rounded-lg border border-border bg-surface p-4">
                <h2 class="font-semibold text-textPrimary">{{ $workspace->display_name }}</h2>
                <form class="mt-3 grid gap-2 md:grid-cols-3" method="POST" action="{{ route('app.network-linking.profile.update', $workspace) }}">
                    @csrf
                    <label class="text-xs text-textSecondary inline-flex items-center gap-2 md:col-span-3">
                        <input type="checkbox" name="default_internal_linking_enabled" value="1" @checked($profile?->default_internal_linking_enabled ?? true) />
                        Internal linking enabled
                    </label>
                    <label class="text-xs text-textSecondary inline-flex items-center gap-2 md:col-span-3">
                        <input type="checkbox" name="external_suggestions_enabled" value="1" @checked($profile?->external_suggestions_enabled ?? false) />
                        Cross-domain suggestions enabled
                    </label>
                    <input class="rounded border border-border px-2 py-1 text-sm" type="number" min="1" max="30" name="max_outbound_links_per_article" value="{{ $profile?->max_outbound_links_per_article ?? 6 }}" />
                    <input class="rounded border border-border px-2 py-1 text-sm" type="number" min="1" max="300" name="max_cross_domain_links_per_month" value="{{ $profile?->max_cross_domain_links_per_month ?? 20 }}" />
                    <input class="rounded border border-border px-2 py-1 text-sm" type="number" min="0.50" max="0.99" step="0.01" name="min_similarity_threshold" value="{{ number_format($profile?->min_similarity_threshold ?? 0.70, 2) }}" />
                    <input class="rounded border border-border px-2 py-1 text-sm md:col-span-1" type="number" min="0.30" max="0.99" step="0.01" name="min_audience_overlap_threshold" value="{{ number_format($profile?->min_audience_overlap_threshold ?? 0.60, 2) }}" />
                    <div class="md:col-span-3">
                        <button class="rounded-md border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">Save profile</button>
                    </div>
                </form>

                <form class="mt-3 flex flex-wrap items-center gap-2" method="POST" action="{{ route('app.network-linking.permissions.request', $workspace) }}">
                    @csrf
                    <select name="to_workspace_id" class="rounded border border-border px-2 py-1 text-sm" required>
                        <option value="">Select target workspace</option>
                        @foreach ($availableTargets as $target)
                            @if ((string) $target->id !== (string) $workspace->id)
                                <option value="{{ $target->id }}">{{ $target->name }} ({{ $target->organization?->name }})</option>
                            @endif
                        @endforeach
                    </select>
                    <select name="relationship_type" class="rounded border border-border px-2 py-1 text-sm" required>
                        <option value="partner">partner</option>
                        <option value="same_brand">same_brand</option>
                        <option value="franchise">franchise</option>
                        <option value="publisher_pool">publisher_pool</option>
                    </select>
                    <button class="rounded-md border border-sky-500/40 bg-sky-500/10 px-3 py-1.5 text-sm text-sky-700">Request permission</button>
                </form>
                @if ($availableTargets->where('id', '!=', $workspace->id)->isEmpty())
                    <p class="mt-2 text-xs text-textSecondary">No other workspace available yet. Create an additional workspace first.</p>
                @endif
            </div>
        @endforeach
    </div>

    <div class="mt-8 rounded-lg border border-border bg-surface p-4">
        <h2 class="font-semibold text-textPrimary">Permissions</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-textSecondary">
                        <th class="py-2">From</th>
                        <th class="py-2">To</th>
                        <th class="py-2">Status</th>
                        <th class="py-2">Type</th>
                        <th class="py-2">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($permissions as $permission)
                        <tr>
                            <td class="py-2">{{ $permission->fromWorkspace?->name }}</td>
                            <td class="py-2">{{ $permission->toWorkspace?->name }}</td>
                            <td class="py-2">{{ $permission->status }}</td>
                            <td class="py-2">{{ $permission->relationship_type }}</td>
                            <td class="py-2 flex flex-wrap gap-2">
                                @can('approve', $permission)
                                    @if ($permission->status !== 'approved')
                                        <form method="POST" action="{{ route('app.network-linking.permissions.approve', $permission) }}">
                                            @csrf
                                            <select name="rel_attribute" class="rounded border border-border px-2 py-1 text-xs">
                                                <option value="follow">follow</option>
                                                <option value="nofollow">nofollow</option>
                                            </select>
                                            <button class="rounded-md border border-emerald-500/40 bg-emerald-500/10 px-2 py-1 text-xs text-emerald-700">Approve</button>
                                        </form>
                                    @endif
                                    @if ($permission->status !== 'revoked')
                                        <form method="POST" action="{{ route('app.network-linking.permissions.revoke', $permission) }}">
                                            @csrf
                                            <button class="rounded-md border border-rose-500/40 bg-rose-500/10 px-2 py-1 text-xs text-rose-700">Revoke</button>
                                        </form>
                                    @endif
                                @else
                                    <span class="text-xs text-textSecondary">No action</span>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="py-4 text-textSecondary" colspan="5">No permissions yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
