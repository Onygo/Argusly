@extends('layouts.admin', ['title' => 'Billing'])

@section('content')
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Billing</h1>
            <p class="mt-1 text-textSecondary">{{ $organization->name }}</p>
        </div>
    </div>

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if (($organizationAccess['is_early_bird_active'] ?? false) || ($organizationAccess['is_early_bird_expired'] ?? false))
        <div class="mb-4 rounded-lg border px-4 py-3 {{ $organizationAccess['badge_classes'] }}">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold">Early Bird</p>
                    @if ($organizationAccess['is_early_bird_active'] ?? false)
                        <p class="mt-1 text-xs">This organization has temporary Early Bird access and is not currently billed.</p>
                    @else
                        <p class="mt-1 text-xs">Early Bird expired{{ $organizationAccess['early_bird_ends_at'] ? ' on ' . $organizationAccess['early_bird_ends_at']->format('Y-m-d') : '' }}.</p>
                    @endif
                </div>
                <form method="POST" action="{{ route('admin.organizations.access.convert-to-paid', $organization) }}" onsubmit="return confirm('Convert this organization to a paid account?');">
                    @csrf
                    <button class="inline-flex items-center justify-center rounded-md border border-current/20 bg-white/50 px-3 py-2 text-sm font-medium">Convert to paid account</button>
                </form>
            </div>
        </div>
    @endif

    @include('admin.billing.partials.overview')
    @include('admin.billing.partials.health')
    @include('admin.billing.partials.plan-quotas')
    @include('admin.billing.partials.workspace-usage')
    @include('admin.billing.partials.actions')
    @include('admin.billing.partials.wallets')
    @include('admin.billing.partials.tabs')

    @include('admin.billing.partials.drawer')
@endsection
