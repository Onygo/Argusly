@extends('layouts.admin', ['title' => 'Support Mode'])

@section('pageHeader')
    <x-page-header title="Support Mode">
        <x-slot:description>Read-only troubleshooting context without impersonation.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif
    @if ($errors->any())
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first() }}</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="mb-3 text-lg font-semibold text-textPrimary">Start Support Mode</h2>
            <form method="POST" action="{{ route('admin.support.start') }}" class="space-y-3">
                @csrf
                <div>
                    <label class="text-sm text-textSecondary">Company</label>
                    <select name="company_id" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" required>
                        <option value="">Select company</option>
                        @foreach ($organizations as $organization)
                            <option value="{{ $organization->id }}" @selected(old('company_id') == $organization->id)>{{ $organization->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-sm text-textSecondary">User</label>
                    <select name="user_id" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" required>
                        <option value="">Select user</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" @selected(old('user_id') == $user->id)>
                                {{ $user->name }} ({{ $user->email }}) · {{ $user->role }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-sm text-textSecondary">Reason (optional)</label>
                    <input name="reason" value="{{ old('reason') }}" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" maxlength="200">
                </div>
                <button class="inline-flex items-center rounded-md border border-border px-4 py-2 text-sm font-medium">Start Support Mode</button>
            </form>
        </div>

        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="mb-3 text-lg font-semibold text-textPrimary">Current Session</h2>
            @if ($support->isEnabled() && $support->targetCompany() && $support->targetUser())
                <div class="space-y-1 text-sm text-textSecondary">
                    <p><strong class="text-textPrimary">Target company:</strong> {{ $support->targetCompany()->name }} (#{{ $support->targetCompany()->id }})</p>
                    <p><strong class="text-textPrimary">Target user:</strong> {{ $support->targetUser()->name }} ({{ $support->targetUser()->email }})</p>
                    <p><strong class="text-textPrimary">Started at:</strong> {{ $support->startedAt() }}</p>
                    <p><strong class="text-textPrimary">Reason:</strong> {{ $support->reason() ?: 'n/a' }}</p>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <a href="{{ route('admin.support.diagnostics') }}" class="inline-flex items-center rounded-md border border-border px-3 py-2 text-sm">View diagnostics</a>
                    <a href="{{ route('admin.support.snapshot') }}" class="inline-flex items-center rounded-md border border-border px-3 py-2 text-sm">Download snapshot</a>
                    <form method="POST" action="{{ route('admin.support.stop') }}">
                        @csrf
                        <button class="inline-flex items-center rounded-md border border-rose-500/40 bg-rose-500/10 px-3 py-2 text-sm text-rose-700">Stop Support Mode</button>
                    </form>
                </div>
            @else
                <p class="text-sm text-textSecondary">Support mode is currently inactive.</p>
            @endif
        </div>
    </div>

    @if ($summary)
        <div class="mt-6 rounded-lg border border-border bg-surface p-5">
            <h2 class="mb-3 text-lg font-semibold text-textPrimary">Target summary</h2>
            <div class="grid gap-3 md:grid-cols-3">
                <div class="rounded border border-border p-3 text-sm">
                    <div class="text-textSecondary">User role</div>
                    <div class="font-medium text-textPrimary">{{ $summary['user_role'] }}</div>
                </div>
                <div class="rounded border border-border p-3 text-sm">
                    <div class="text-textSecondary">Sites</div>
                    <div class="font-medium text-textPrimary">{{ $summary['sites_count'] }}</div>
                </div>
                <div class="rounded border border-border p-3 text-sm">
                    <div class="text-textSecondary">Drafts</div>
                    <div class="font-medium text-textPrimary">{{ $summary['drafts_count'] }}</div>
                </div>
                <div class="rounded border border-border p-3 text-sm">
                    <div class="text-textSecondary">Briefs</div>
                    <div class="font-medium text-textPrimary">{{ $summary['briefs_count'] }}</div>
                </div>
                <div class="rounded border border-border p-3 text-sm">
                    <div class="text-textSecondary">Last login</div>
                    <div class="font-medium text-textPrimary">{{ $summary['last_login_at'] ?? 'n/a' }}</div>
                </div>
                <div class="rounded border border-border p-3 text-sm">
                    <div class="text-textSecondary">Plan</div>
                    <div class="font-medium text-textPrimary">{{ $summary['plan'] }}</div>
                </div>
            </div>
        </div>
    @endif
@endsection
