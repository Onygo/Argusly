@extends('layouts.admin', ['title' => 'Pilot Application Detail'])

@php
    $status = $signup->status?->value ?? (string) $signup->status;
    $statusClass = match ($status) {
        'new' => 'border-amber-300/80 bg-amber-500/10 text-amber-800',
        'reviewed' => 'border-slate-300/80 bg-slate-500/10 text-slate-800',
        'approved' => 'border-sky-300/80 bg-sky-500/10 text-sky-800',
        'invited' => 'border-indigo-300/80 bg-indigo-500/10 text-indigo-800',
        'activated' => 'border-emerald-300/80 bg-emerald-500/10 text-emerald-800',
        'rejected' => 'border-rose-300/80 bg-rose-500/10 text-rose-800',
        default => 'border-border bg-background text-textSecondary',
    };
@endphp

@section('pageHeader')
    <x-page-header :title="$signup->full_name">
        <x-slot:description>{{ $signup->email }} · submitted {{ optional($signup->submitted_at ?? $signup->created_at)->format('Y-m-d H:i') }}</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
    <span class="inline-flex items-center rounded-md border px-2.5 py-1 text-xs font-medium {{ $statusClass }}">
        {{ $signup->status?->label() ?? ucfirst($status) }}
    </span>
    <a href="{{ route('admin.early-access.index') }}" class="pl-btn-secondary">Back to Pilot Program</a>
@endsection

@section('content')
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
            <x-settings.section-card title="Pilot Application" description="Original application details stored at intake.">
                <dl class="grid gap-4 md:grid-cols-2">
                    <div>
                        <dt class="text-xs text-textSecondary">Full name</dt>
                        <dd class="mt-1 text-sm font-medium text-textPrimary">{{ $signup->full_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Email</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $signup->email }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Phone</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $signup->phone ?: 'n/a' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Country</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $signup->country ?: 'n/a' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Job title</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $signup->job_title ?: 'n/a' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Company</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $signup->company_name ?: 'n/a' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Company size</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $signup->company_size ?: 'n/a' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Industry</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $signup->industry ?: 'n/a' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Website</dt>
                        <dd class="mt-1 text-sm text-textPrimary break-all">{{ $signup->website ?: 'n/a' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Source</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $signup->source ?: 'n/a' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Submitted</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ optional($signup->submitted_at ?? $signup->created_at)->format('Y-m-d H:i') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Priority</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $signup->priority ? ucfirst($signup->priority) : 'n/a' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Qualification</dt>
                        @php($score = (int) ($signup->qualification_score ?? $qualification->score($signup)))
                        <dd class="mt-1 text-sm text-textPrimary">{{ $score }}/100 · {{ $qualification->label($score) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Assigned admin</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $signup->assignedAdmin?->name ?: 'n/a' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Marketing consent</dt>
                        <dd class="mt-1 text-sm text-textPrimary">{{ $signup->marketing_consent ? 'Yes' : 'No' }}</dd>
                    </div>
                    <div class="md:col-span-2">
                        <dt class="text-xs text-textSecondary">UTM</dt>
                        <dd class="mt-1 text-sm text-textPrimary">
                            {{ $signup->utm_source ?: 'n/a' }}
                            @if ($signup->utm_medium || $signup->utm_campaign)
                                · {{ $signup->utm_medium ?: 'n/a' }} · {{ $signup->utm_campaign ?: 'n/a' }}
                            @endif
                        </dd>
                    </div>
                    <div class="md:col-span-2">
                        <dt class="text-xs text-textSecondary">Use case</dt>
                        <dd class="mt-1 whitespace-pre-wrap text-sm text-textPrimary">{{ $signup->use_case ?: 'n/a' }}</dd>
                    </div>
                    <div class="md:col-span-2">
                        <dt class="text-xs text-textSecondary">Notes</dt>
                        <dd class="mt-1 whitespace-pre-wrap text-sm text-textPrimary">{{ $signup->notes ?: 'n/a' }}</dd>
                    </div>
                </dl>
            </x-settings.section-card>

            <x-settings.section-card title="Internal notes" description="Private admin notes for review and handoff.">
                <form method="POST" action="{{ route('admin.early-access.notes.update', $signup) }}" class="space-y-4">
                    @csrf
                    <textarea name="internal_notes" rows="6" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">{{ old('internal_notes', $signup->internal_notes) }}</textarea>
                    <x-settings.form-actions>
                        <button class="inline-flex items-center justify-center rounded-md border border-border px-3 py-2 text-sm font-medium">Save internal note</button>
                    </x-settings.form-actions>
                </form>
            </x-settings.section-card>

            <x-settings.section-card title="Pilot cost tracking" description="Internal cost view for this early access pilot.">
                <div class="grid gap-3 md:grid-cols-4">
                    <div class="rounded-md border border-border bg-background px-3 py-3">
                        <p class="text-xs text-textSecondary">Total</p>
                        <p class="mt-1 text-xl font-semibold text-textPrimary">€{{ number_format((float) $pilotCostSummary['total_cost_eur'], 2) }}</p>
                    </div>
                    <div class="rounded-md border border-border bg-background px-3 py-3">
                        <p class="text-xs text-textSecondary">AI cost</p>
                        <p class="mt-1 text-xl font-semibold text-textPrimary">€{{ number_format((float) $pilotCostSummary['llm_cost_eur'], 2) }}</p>
                    </div>
                    <div class="rounded-md border border-border bg-background px-3 py-3">
                        <p class="text-xs text-textSecondary">Manual cost</p>
                        <p class="mt-1 text-xl font-semibold text-textPrimary">€{{ number_format((float) $pilotCostSummary['manual_cost_eur'], 2) }}</p>
                    </div>
                    <div class="rounded-md border border-border bg-background px-3 py-3">
                        <p class="text-xs text-textSecondary">Credits used</p>
                        <p class="mt-1 text-xl font-semibold text-textPrimary">{{ number_format((float) $pilotCostSummary['llm_credits'], 1) }}</p>
                    </div>
                </div>

                <div class="mt-5 rounded-md border border-border">
                    <div class="grid gap-3 border-b border-border bg-surfaceSubtle px-3 py-2 text-xs font-medium text-textSecondary md:grid-cols-12">
                        <span class="md:col-span-2">Date</span>
                        <span class="md:col-span-2">Category</span>
                        <span class="md:col-span-5">Description</span>
                        <span class="text-right md:col-span-2">Amount</span>
                        <span class="text-right md:col-span-1"> </span>
                    </div>
                    <div class="divide-y divide-border">
                        @forelse ($signup->pilotCosts->sortByDesc('incurred_on')->sortByDesc('created_at') as $cost)
                            <div class="grid gap-3 px-3 py-3 text-sm md:grid-cols-12 md:items-center">
                                <div class="text-textSecondary md:col-span-2">{{ optional($cost->incurred_on)->format('Y-m-d') ?: 'n/a' }}</div>
                                <div class="text-textPrimary md:col-span-2">{{ $pilotCostCategories[$cost->category] ?? ucfirst((string) $cost->category) }}</div>
                                <div class="text-textPrimary md:col-span-5">
                                    {{ $cost->description }}
                                    @if ($cost->creator)
                                        <p class="text-xs text-textSecondary">Added by {{ $cost->creator->name }}</p>
                                    @endif
                                </div>
                                <div class="text-right font-medium text-textPrimary md:col-span-2">€{{ number_format(((int) $cost->amount_cents) / 100, 2) }}</div>
                                <div class="text-right md:col-span-1">
                                    <form method="POST" action="{{ route('admin.early-access.pilot-costs.destroy', [$signup, $cost]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="inline-flex h-8 w-8 items-center justify-center rounded border border-border text-textSecondary hover:text-rose-700" title="Remove cost">
                                            <i data-lucide="trash-2" class="h-4 w-4"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @empty
                            <p class="px-3 py-4 text-sm text-textSecondary">No manual pilot costs recorded yet.</p>
                        @endforelse
                    </div>
                </div>

                <form method="POST" action="{{ route('admin.early-access.pilot-costs.store', $signup) }}" class="mt-5 grid gap-3 md:grid-cols-12">
                    @csrf
                    <select name="category" class="pl-select bg-surface md:col-span-2">
                        @foreach ($pilotCostCategories as $value => $label)
                            <option value="{{ $value }}" @selected(old('category') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <input type="text" name="description" value="{{ old('description') }}" placeholder="Description" class="pl-input md:col-span-4">
                    <input type="number" name="amount_eur" value="{{ old('amount_eur') }}" min="0" step="0.01" placeholder="Amount EUR" class="pl-input md:col-span-2">
                    <input type="date" name="incurred_on" value="{{ old('incurred_on', now()->toDateString()) }}" class="pl-input md:col-span-2">
                    <button class="pl-btn-secondary md:col-span-2">
                        <i data-lucide="plus" class="h-4 w-4"></i>
                        Add cost
                    </button>
                </form>

                <p class="mt-3 text-xs text-textSecondary">
                    AI cost is calculated from {{ number_format((int) $pilotCostSummary['llm_requests']) }} logged LLM requests for the linked workspace.
                </p>
            </x-settings.section-card>

            <x-settings.section-card title="Action history" description="Recent audited state changes for this signup.">
                <div class="space-y-3">
                    @forelse ($activity as $entry)
                        <div class="rounded border border-border bg-background px-3 py-3">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-sm font-medium text-textPrimary">{{ str_replace('.', ' ', $entry->action) }}</p>
                                    <p class="text-xs text-textSecondary">
                                        {{ $entry->created_at?->format('Y-m-d H:i') }}
                                        @if ($entry->actor_id)
                                            · actor #{{ $entry->actor_id }}
                                        @endif
                                    </p>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-textSecondary">No audit entries recorded yet.</p>
                    @endforelse
                </div>
            </x-settings.section-card>
        </div>

        <div class="space-y-6">
            <x-settings.section-card title="Actions" description="Status transitions and invitation workflow.">
                <div class="flex flex-wrap gap-2">
                    @if (in_array($status, ['new', 'rejected'], true))
                        <form method="POST" action="{{ route('admin.early-access.review', $signup) }}">
                            @csrf
                            <button class="inline-flex items-center justify-center rounded-md border border-border px-3 py-2 text-sm font-medium">Mark reviewed</button>
                        </form>
                    @endif

                    @if (in_array($status, ['new', 'reviewed', 'rejected'], true))
                        <form method="POST" action="{{ route('admin.early-access.approve', $signup) }}">
                            @csrf
                            <button class="inline-flex items-center justify-center rounded-md border border-sky-300/80 px-3 py-2 text-sm font-medium text-sky-800">Approve</button>
                        </form>
                    @endif

                    @if ($status === 'approved')
                        <form method="POST" action="{{ route('admin.early-access.send-invite', $signup) }}">
                            @csrf
                            <button class="inline-flex items-center justify-center rounded-md border border-indigo-300/80 px-3 py-2 text-sm font-medium text-indigo-800">Send invite</button>
                        </form>
                    @elseif ($status === 'invited')
                        <form method="POST" action="{{ route('admin.early-access.resend-invite', $signup) }}">
                            @csrf
                            <button class="inline-flex items-center justify-center rounded-md border border-indigo-300/80 px-3 py-2 text-sm font-medium text-indigo-800">Resend invite</button>
                        </form>
                    @endif

                    @if (! in_array($status, ['activated', 'rejected'], true))
                        <form method="POST" action="{{ route('admin.early-access.reject', $signup) }}">
                            @csrf
                            <button class="inline-flex items-center justify-center rounded-md border border-rose-300/80 px-3 py-2 text-sm font-medium text-rose-800">Reject</button>
                        </form>
                    @endif
                </div>
            </x-settings.section-card>

            <x-settings.section-card title="Status timeline" description="Core lifecycle timestamps.">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-textSecondary">Submitted</dt>
                        <dd class="text-textPrimary">{{ optional($signup->submitted_at ?? $signup->created_at)->format('Y-m-d H:i') }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-textSecondary">Reviewed</dt>
                        <dd class="text-textPrimary">{{ optional($signup->reviewed_at)->format('Y-m-d H:i') ?: 'n/a' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-textSecondary">Approved</dt>
                        <dd class="text-textPrimary">{{ optional($signup->approved_at)->format('Y-m-d H:i') ?: 'n/a' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-textSecondary">Invited</dt>
                        <dd class="text-textPrimary">{{ optional($signup->invited_at)->format('Y-m-d H:i') ?: 'n/a' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-textSecondary">Activated</dt>
                        <dd class="text-textPrimary">{{ optional($signup->activated_at)->format('Y-m-d H:i') ?: 'n/a' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-textSecondary">Rejected</dt>
                        <dd class="text-textPrimary">{{ optional($signup->rejected_at)->format('Y-m-d H:i') ?: 'n/a' }}</dd>
                    </div>
                </dl>
            </x-settings.section-card>

            <x-settings.section-card title="Linked entities" description="User and workspace references after activation.">
                <div class="space-y-3 text-sm">
                    <div>
                        <p class="text-xs text-textSecondary">Existing user by email</p>
                        @if ($existingUser)
                            <p class="mt-1 text-textPrimary">
                                {{ $existingUser->name }} · {{ $existingUser->email }}
                                @if ($existingUser->organization)
                                    · org: {{ $existingUser->organization->name }}
                                @endif
                            </p>
                        @else
                            <p class="mt-1 text-textSecondary">No matching user found.</p>
                        @endif
                    </div>
                    <div>
                        <p class="text-xs text-textSecondary">Activated user</p>
                        <p class="mt-1 text-textPrimary">
                            @if ($signup->activatedUser)
                                <a href="{{ route('admin.users', ['q' => $signup->activatedUser->email]) }}" class="hover:underline">{{ $signup->activatedUser->email }}</a>
                            @else
                                n/a
                            @endif
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-textSecondary">Workspace</p>
                        <p class="mt-1 text-textPrimary">
                            @if ($signup->workspace)
                                {{ $signup->workspace->display_name }}
                                @if ($signup->workspace->organization)
                                    · <a href="{{ route('admin.organizations.show', $signup->workspace->organization) }}" class="hover:underline">{{ $signup->workspace->organization->name }}</a>
                                @endif
                            @else
                                n/a
                            @endif
                        </p>
                    </div>
                </div>
            </x-settings.section-card>

            <x-settings.section-card title="Invite history" description="Most recent invite records and activation links.">
                <div class="space-y-3 text-sm">
                    @forelse ($signup->invites as $invite)
                        <div class="rounded border border-border bg-background px-3 py-3">
                            <p class="font-medium text-textPrimary">{{ $invite->email }}</p>
                            <p class="mt-1 text-xs text-textSecondary">
                                Created {{ optional($invite->created_at)->format('Y-m-d H:i') }}
                                · expires {{ optional($invite->expires_at)->format('Y-m-d H:i') ?: 'n/a' }}
                                · accepted {{ optional($invite->accepted_at)->format('Y-m-d H:i') ?: 'n/a' }}
                            </p>
                        </div>
                    @empty
                        <p class="text-textSecondary">No invites sent yet.</p>
                    @endforelse
                </div>
            </x-settings.section-card>

            @if ($duplicates->isNotEmpty())
                <x-settings.section-card title="Other submissions" description="Recent duplicate requests with the same email address.">
                    <div class="space-y-2 text-sm">
                        @foreach ($duplicates as $duplicate)
                            <a href="{{ route('admin.early-access.show', $duplicate) }}" class="block rounded border border-border bg-background px-3 py-3 hover:bg-surfaceSubtle">
                                <p class="font-medium text-textPrimary">{{ $duplicate->full_name }}</p>
                                <p class="mt-1 text-xs text-textSecondary">{{ optional($duplicate->submitted_at ?? $duplicate->created_at)->format('Y-m-d H:i') }} · {{ $duplicate->status?->label() ?? ucfirst((string) $duplicate->status) }}</p>
                            </a>
                        @endforeach
                    </div>
                </x-settings.section-card>
            @endif
        </div>
    </div>
@endsection
