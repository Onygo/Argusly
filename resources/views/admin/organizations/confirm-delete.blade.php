@extends('layouts.admin', ['title' => 'Delete Organization'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Delete Organization</x-slot:title>
        <x-slot:description>Permanently delete {{ $organization->name }}</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
    <a href="{{ route('admin.organizations.show', $organization) }}" class="inline-flex items-center rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-background">Back to organization</a>
@endsection

@section('content')
    @if ($errors->any())
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="space-y-6">
            <x-settings.section-card title="Organization Details" description="Review the organization before deletion.">
                <dl class="grid gap-4 md:grid-cols-2">
                    <div>
                        <dt class="text-xs text-textSecondary">Organization name</dt>
                        <dd class="mt-1 text-sm font-medium text-textPrimary">{{ $organization->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Slug</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $organization->slug }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Status</dt>
                        <dd class="mt-1">
                            <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium {{ $organization->getStatusBadgeClasses() }}">
                                {{ $organization->getStatusLabel() }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Created</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $organization->created_at?->format('Y-m-d H:i') }}</dd>
                    </div>
                </dl>
            </x-settings.section-card>

            <x-settings.section-card title="Related Data" description="Data that will be affected by this deletion.">
                <div class="grid gap-3 md:grid-cols-2">
                    @foreach ($relatedData as $type => $count)
                        <div class="flex items-center justify-between rounded border border-border bg-background px-3 py-2">
                            <span class="text-sm text-textSecondary">{{ str_replace('_', ' ', ucfirst($type)) }}</span>
                            <span class="text-sm font-medium {{ $count > 0 ? 'text-amber-700' : 'text-textSecondary' }}">{{ $count }}</span>
                        </div>
                    @endforeach
                </div>
            </x-settings.section-card>
        </div>

        <div class="space-y-6">
            @if (! $canDelete)
                <div class="rounded-lg border border-amber-300/70 bg-amber-500/10 p-4">
                    <p class="text-sm font-semibold text-amber-900">Organization has related data</p>
                    <p class="mt-1 text-sm text-amber-900/90">This organization cannot be safely deleted because it has existing data:</p>
                    <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-amber-900/80">
                        @foreach ($reasons as $reason)
                            <li>{{ $reason }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-3 text-sm text-amber-900/90">You can still force delete this organization, but <strong>all related data will be permanently lost</strong>.</p>
                </div>
            @endif

            <x-settings.section-card title="Confirm Deletion" description="This action is permanent and cannot be undone.">
                <div class="rounded-lg border border-rose-300/70 bg-rose-500/10 p-4">
                    <p class="text-sm font-semibold text-rose-900">Danger zone</p>
                    <p class="mt-1 text-sm text-rose-900/90">Deleting this organization will permanently remove:</p>
                    <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-rose-900/80">
                        <li>The organization and all its settings</li>
                        <li>All users belonging to this organization</li>
                        <li>All workspaces and client sites</li>
                        <li>All subscriptions, invoices, and billing data</li>
                        <li>All brand voices, personas, and content series</li>
                    </ul>
                    <p class="mt-3 text-sm font-medium text-rose-900">This action cannot be undone.</p>
                </div>

                <form method="POST" action="{{ route('admin.organizations.delete', $organization) }}" class="mt-4 space-y-4" x-data="{ confirmName: '', forceDelete: false }">
                    @csrf
                    @method('DELETE')

                    <div>
                        <label class="mb-1 block text-sm font-medium text-textPrimary">
                            Type the organization name to confirm
                        </label>
                        <input
                            type="text"
                            name="confirmation_name"
                            x-model="confirmName"
                            class="w-full rounded border border-border px-3 py-2 text-sm"
                            placeholder="{{ $organization->name }}"
                            autocomplete="off"
                        >
                        <p class="mt-1 text-xs text-textSecondary">Please type <strong>{{ $organization->name }}</strong> exactly to confirm.</p>
                    </div>

                    @if (! $canDelete)
                        <div class="rounded border border-amber-300/70 bg-amber-500/10 p-3">
                            <label class="flex items-start gap-2 text-sm text-amber-900">
                                <input
                                    type="checkbox"
                                    name="force_delete"
                                    value="1"
                                    x-model="forceDelete"
                                    class="mt-0.5"
                                >
                                <span>
                                    <strong>Force delete</strong> - I understand that all related data will be permanently deleted and this action cannot be undone.
                                </span>
                            </label>
                        </div>
                    @endif

                    <x-settings.form-actions align="between">
                        <a href="{{ route('admin.organizations.show', $organization) }}" class="inline-flex items-center justify-center rounded-md border border-border px-3 py-2 text-sm font-medium">Cancel</a>
                        <button
                            type="submit"
                            class="inline-flex items-center justify-center rounded-md border border-rose-700/30 bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="confirmName.toLowerCase() !== '{{ strtolower($organization->name) }}'{{ ! $canDelete ? ' || !forceDelete' : '' }}"
                        >
                            Delete organization permanently
                        </button>
                    </x-settings.form-actions>
                </form>
            </x-settings.section-card>
        </div>
    </div>
@endsection
