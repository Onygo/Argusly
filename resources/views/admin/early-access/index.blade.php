@extends('layouts.admin', ['title' => 'Pilot Program'])

@php
    $metricCards = [
        'new' => ['label' => 'New', 'class' => 'text-amber-800 bg-amber-500/10 border-amber-300/80'],
        'reviewed' => ['label' => 'Reviewed', 'class' => 'text-slate-800 bg-slate-500/10 border-slate-300/80'],
        'approved' => ['label' => 'Approved', 'class' => 'text-sky-800 bg-sky-500/10 border-sky-300/80'],
        'invited' => ['label' => 'Invited', 'class' => 'text-indigo-800 bg-indigo-500/10 border-indigo-300/80'],
        'activated' => ['label' => 'Activated', 'class' => 'text-emerald-800 bg-emerald-500/10 border-emerald-300/80'],
    ];

    $badgeClasses = [
        'new' => 'border-amber-300/80 bg-amber-500/10 text-amber-800',
        'reviewed' => 'border-slate-300/80 bg-slate-500/10 text-slate-800',
        'approved' => 'border-sky-300/80 bg-sky-500/10 text-sky-800',
        'invited' => 'border-indigo-300/80 bg-indigo-500/10 text-indigo-800',
        'activated' => 'border-emerald-300/80 bg-emerald-500/10 text-emerald-800',
        'rejected' => 'border-rose-300/80 bg-rose-500/10 text-rose-800',
    ];
@endphp

@section('pageHeader')
    <x-page-header title="Pilot Program" />
@endsection

@section('pageDescription')
    <x-page-description>Review pilot applications, qualify candidates, and activate invited users.</x-page-description>
@endsection

@section('primaryActions')
    <a href="#invite-pilot-user" class="pl-btn-secondary">
        <i data-lucide="send" class="h-4 w-4"></i>
        Invite pilot user
    </a>
    <a href="#add-existing-user" class="pl-btn-ghost h-10 border border-border">
        <i data-lucide="user-plus" class="h-4 w-4"></i>
        Add existing user
    </a>
@endsection

@section('filterBar')
    <form method="GET" class="grid w-full gap-2 sm:grid-cols-2 lg:grid-cols-5">
        <input
            type="text"
            name="q"
            value="{{ $filters['q'] }}"
            placeholder="Search name, email, company"
            class="pl-input sm:col-span-2 lg:w-64"
        >
        <select name="status" class="pl-select bg-surface lg:w-40">
            <option value="">All status</option>
            @foreach ($statuses as $status)
                <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ $status->label() }}</option>
            @endforeach
        </select>
        <input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="pl-input bg-surface lg:w-40">
        <input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="pl-input bg-surface lg:w-40">
        <div class="flex gap-2 sm:col-span-2 lg:col-span-5 lg:justify-end">
            <button class="pl-btn-secondary">
                <i data-lucide="search" class="h-4 w-4"></i>
                Apply
            </button>
            <a href="{{ route('admin.early-access.index') }}" class="pl-btn-ghost h-10 border border-border">
                <i data-lucide="rotate-ccw" class="h-4 w-4"></i>
                Reset
            </a>
        </div>
    </form>
@endsection

@section('metricSection')
    <x-metric-section>
        @foreach ($metricCards as $key => $card)
            <x-metric-card :label="$card['label']" :value="number_format((int) ($metrics[$key] ?? 0))" />
        @endforeach
    </x-metric-section>
@endsection

@section('content')

    <x-settings.section-card id="invite-pilot-user" title="Invite Pilot User" description="Create a pilot application manually and send the activation invite immediately.">
        <form method="POST" action="{{ route('admin.early-access.invite-pilot-user') }}" class="grid gap-3 lg:grid-cols-12">
            @csrf
            <input type="text" name="full_name" value="{{ old('full_name') }}" placeholder="Full name" class="pl-input lg:col-span-2" required>
            <input type="email" name="email" value="{{ old('email') }}" placeholder="Email" class="pl-input lg:col-span-2" required>
            <input type="text" name="company_name" value="{{ old('company_name') }}" placeholder="Company" class="pl-input lg:col-span-2">
            <input type="text" name="website" value="{{ old('website') }}" placeholder="Website" class="pl-input lg:col-span-2">
            <input type="text" name="notes" value="{{ old('notes') }}" placeholder="Notes" class="pl-input lg:col-span-3">
            <button class="pl-btn-secondary lg:col-span-1">
                <i data-lucide="send" class="h-4 w-4"></i>
                Invite
            </button>
        </form>
    </x-settings.section-card>

    <x-settings.section-card id="add-existing-user" title="Add Existing User" description="Link an existing account to the Pilot Program without sending a new activation invite." class="mt-4">
        <form method="POST" action="{{ route('admin.early-access.add-existing-user') }}" class="grid gap-3 lg:grid-cols-12">
            @csrf
            <input type="email" name="email" value="{{ old('email') }}" placeholder="Existing user email" class="pl-input lg:col-span-3" required>
            <input type="text" name="workspace_id" value="{{ old('workspace_id') }}" placeholder="Workspace UUID (optional)" class="pl-input lg:col-span-3">
            <input type="datetime-local" name="ends_at" value="{{ old('ends_at') }}" class="pl-input lg:col-span-2">
            <input type="text" name="notes" value="{{ old('notes') }}" placeholder="Internal notes" class="pl-input lg:col-span-3">
            <button class="pl-btn-secondary lg:col-span-1" onclick="return confirm('Add this existing user to the Pilot Program?');">
                <i data-lucide="user-plus" class="h-4 w-4"></i>
                Add
            </button>
        </form>
    </x-settings.section-card>

    <div class="mb-6 mt-6 grid gap-3 md:grid-cols-3">
        <div class="rounded-lg border border-border bg-surface px-4 py-3">
            <p class="text-xs font-medium uppercase tracking-wide text-textSecondary">AI cost</p>
            <p class="mt-2 text-2xl font-semibold text-textPrimary">€{{ number_format((float) ($costMetrics['llm_cost_eur'] ?? 0), 2) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface px-4 py-3">
            <p class="text-xs font-medium uppercase tracking-wide text-textSecondary">Manual cost</p>
            <p class="mt-2 text-2xl font-semibold text-textPrimary">€{{ number_format((float) ($costMetrics['manual_cost_eur'] ?? 0), 2) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface px-4 py-3">
            <p class="text-xs font-medium uppercase tracking-wide text-textSecondary">Total pilot cost</p>
            <p class="mt-2 text-2xl font-semibold text-textPrimary">€{{ number_format((float) ($costMetrics['total_cost_eur'] ?? 0), 2) }}</p>
        </div>
    </div>

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first() }}</div>
    @endif

    <x-data-table label="Pilot applications" description="Pilot applications with qualification, status, cost, submission time, and review actions.">
                <x-data-table.header>
                    <x-data-table.row>
                        <x-data-table.cell heading>Name</x-data-table.cell>
                        <x-data-table.cell heading>Email</x-data-table.cell>
                        <x-data-table.cell heading>Company</x-data-table.cell>
                        <x-data-table.cell heading>Status</x-data-table.cell>
                        <x-data-table.cell heading>Qualification</x-data-table.cell>
                        <x-data-table.cell heading align="right">Pilot cost</x-data-table.cell>
                        <x-data-table.cell heading>Submitted</x-data-table.cell>
                        <x-data-table.cell heading align="right">Actions</x-data-table.cell>
                    </x-data-table.row>
                </x-data-table.header>
                <tbody>
                    @forelse ($signups as $signup)
                        @php($status = $signup->status?->value ?? (string) $signup->status)
                        <x-data-table.row>
                            <x-data-table.cell label="Name">
                                <a href="{{ route('admin.early-access.show', $signup) }}" class="font-medium text-textPrimary hover:underline">{{ $signup->full_name }}</a>
                            </x-data-table.cell>
                            <x-data-table.cell label="Email" class="text-textSecondary">{{ $signup->email }}</x-data-table.cell>
                            <x-data-table.cell label="Company" class="text-textSecondary">{{ $signup->company_name ?: 'n/a' }}</x-data-table.cell>
                            <x-data-table.cell label="Status">
                                <x-data-table.badge :tone="in_array($status, ['activated', 'approved'], true) ? 'success' : (in_array($status, ['new', 'reviewed'], true) ? 'warning' : ($status === 'rejected' ? 'danger' : 'info'))" :label="$signup->status?->label() ?? ucfirst($status)" />
                            </x-data-table.cell>
                            <x-data-table.cell label="Qualification">
                                @php($score = (int) data_get($signup->pilot_qualification, 'score', $signup->qualification_score ?? 0))
                                @php($label = (string) data_get($signup->pilot_qualification, 'label', $qualification->label($score)))
                                <x-data-table.badge>
                                    {{ $score }}/100 · {{ $label }}
                                </x-data-table.badge>
                            </x-data-table.cell>
                            <x-data-table.cell label="Pilot cost" align="right" class="text-textPrimary">
                                €{{ number_format((float) data_get($signup->pilot_cost_summary, 'total_cost_eur', 0), 2) }}
                                @if ((float) data_get($signup->pilot_cost_summary, 'credits_consumed', 0) > 0)
                                    <p class="text-xs text-textSecondary">{{ number_format((float) data_get($signup->pilot_cost_summary, 'credits_consumed', 0), 1) }} credits</p>
                                @endif
                            </x-data-table.cell>
                            <x-data-table.cell label="Submitted" class="text-xs text-textSecondary">{{ optional($signup->submitted_at ?? $signup->created_at)->format('Y-m-d H:i') }}</x-data-table.cell>
                            <x-data-table.cell label="Actions">
                                <x-data-table.actions>
                                    <a href="{{ route('admin.early-access.show', $signup) }}" class="inline-flex h-8 w-8 items-center justify-center rounded border border-border text-textSecondary hover:text-textPrimary" title="View" aria-label="View {{ $signup->full_name }}">
                                        <i data-lucide="eye" class="h-4 w-4"></i>
                                    </a>
                                    @if ($status === 'new' || $status === 'rejected')
                                        <form method="POST" action="{{ route('admin.early-access.review', $signup) }}">
                                            @csrf
                                            <button class="inline-flex h-8 items-center justify-center rounded border border-border px-2 text-xs text-textSecondary hover:text-textPrimary">Review</button>
                                        </form>
                                    @endif
                                    @if (in_array($status, ['new', 'reviewed', 'rejected'], true))
                                        <form method="POST" action="{{ route('admin.early-access.approve', $signup) }}">
                                            @csrf
                                            <button class="inline-flex h-8 items-center justify-center rounded border border-sky-300/80 px-2 text-xs text-sky-800">Approve</button>
                                        </form>
                                    @endif
                                    @if ($status === 'approved')
                                        <form method="POST" action="{{ route('admin.early-access.send-invite', $signup) }}">
                                            @csrf
                                            <button class="inline-flex h-8 items-center justify-center rounded border border-indigo-300/80 px-2 text-xs text-indigo-800">Send invite</button>
                                        </form>
                                    @elseif ($status === 'invited')
                                        <form method="POST" action="{{ route('admin.early-access.resend-invite', $signup) }}">
                                            @csrf
                                            <button class="inline-flex h-8 items-center justify-center rounded border border-indigo-300/80 px-2 text-xs text-indigo-800">Resend</button>
                                        </form>
                                    @endif
                                    @if ($status !== 'activated' && $status !== 'rejected')
                                        <form method="POST" action="{{ route('admin.early-access.reject', $signup) }}">
                                            @csrf
                                            <button class="inline-flex h-8 items-center justify-center rounded border border-rose-300/80 px-2 text-xs text-rose-800">Reject</button>
                                        </form>
                                    @endif
                                </x-data-table.actions>
                            </x-data-table.cell>
                        </x-data-table.row>
                    @empty
                        <x-data-table.empty colspan="8" title="No pilot applications found" description="No pilot applications match the current filters." />
                    @endforelse
                </tbody>
        <x-slot:pagination>{{ $signups->links() }}</x-slot:pagination>
    </x-data-table>
@endsection
