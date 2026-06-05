@extends('layouts.admin', ['title' => 'Search'])

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-textPrimary">Search</h1>
        <p class="mt-1 text-sm text-textSecondary">Results for "{{ $q !== '' ? $q : '...' }}"</p>
    </div>

    @if($q === '')
        <div class="rounded-lg border border-border bg-surface p-5 text-sm text-textSecondary">
            Enter a search term in the top navigation.
        </div>
    @else
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-textPrimary">Organizations ({{ $organizations->count() }})</h2>
                <div class="mt-3 space-y-2">
                    @forelse($organizations as $organization)
                        <a href="{{ route('admin.organizations.show', $organization) }}" class="block rounded-md border border-border px-3 py-2 hover:bg-surfaceSubtle">
                            <p class="text-sm font-medium text-textPrimary">{{ $organization->name }}</p>
                            <p class="text-xs text-textSecondary">{{ $organization->slug }}</p>
                        </a>
                    @empty
                        <p class="text-sm text-textSecondary">No organizations match.</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-textPrimary">Users ({{ $users->count() }})</h2>
                <div class="mt-3 space-y-2">
                    @forelse($users as $user)
                        <a href="{{ route('admin.users', ['q' => $user->email]) }}" class="block rounded-md border border-border px-3 py-2 hover:bg-surfaceSubtle">
                            <p class="text-sm font-medium text-textPrimary">{{ $user->name }}</p>
                            <p class="text-xs text-textSecondary">{{ $user->email }}</p>
                        </a>
                    @empty
                        <p class="text-sm text-textSecondary">No users match.</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-textPrimary">Invoices ({{ $invoices->count() }})</h2>
                <div class="mt-3 space-y-2">
                    @forelse($invoices as $invoice)
                        <a href="{{ route('admin.invoices.preview', $invoice) }}" class="block rounded-md border border-border px-3 py-2 hover:bg-surfaceSubtle">
                            <p class="text-sm font-medium text-textPrimary">{{ $invoice->number }}</p>
                            <p class="text-xs text-textSecondary">{{ $invoice->billing_company_name ?: 'Unknown company' }}</p>
                        </a>
                    @empty
                        <p class="text-sm text-textSecondary">No invoices match.</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-textPrimary">Sites ({{ $sites->count() }})</h2>
                <div class="mt-3 space-y-2">
                    @forelse($sites as $site)
                        <a href="{{ route('admin.sites', ['q' => $site->name]) }}" class="block rounded-md border border-border px-3 py-2 hover:bg-surfaceSubtle">
                            <p class="text-sm font-medium text-textPrimary">{{ $site->name }}</p>
                            <p class="text-xs text-textSecondary">{{ $site->base_url ?: $site->site_url }}</p>
                        </a>
                    @empty
                        <p class="text-sm text-textSecondary">No sites match.</p>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
@endsection

