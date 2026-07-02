@extends('layouts.admin', ['title' => 'Reservation: ' . substr($reservation->id, 0, 8)])

@section('pageHeader')
    <x-page-header title="Credit Reservation">
        <x-slot:description>Reservation {{ substr($reservation->id, 0, 8) }}...</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
    <a href="{{ route('admin.credit-reservations.index') }}" class="pl-btn-secondary">Back to Reservations</a>
@endsection

@section('content')
    @if(session('status'))
        <div class="mb-4 rounded-lg border border-green-500/20 bg-green-500/10 px-4 py-3 text-sm text-green-600">
            {{ session('status') }}
        </div>
    @endif

    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-500/20 bg-red-500/10 px-4 py-3 text-sm text-red-600">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            <div class="rounded-lg border border-border bg-surface p-6">
                <h2 class="mb-4 text-lg font-semibold">Reservation Details</h2>
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-textSecondary">ID</dt>
                        <dd class="font-mono text-sm">{{ $reservation->id }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Status</dt>
                        <dd>
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium
                                {{ $reservation->status === 'reserved' ? 'bg-blue-500/10 text-blue-600' : '' }}
                                {{ $reservation->status === 'captured' ? 'bg-green-500/10 text-green-600' : '' }}
                                {{ $reservation->status === 'released' ? 'bg-gray-500/10 text-gray-600' : '' }}
                                {{ $reservation->status === 'expired' ? 'bg-amber-500/10 text-amber-600' : '' }}
                            ">
                                {{ $reservation->status }}
                            </span>
                            @if($reservation->isPastExpiry() && $reservation->isReserved())
                                <span class="ml-1 text-xs text-amber-600">(Past TTL)</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Amount</dt>
                        <dd class="text-lg font-bold">{{ $reservation->amount }} {{ $reservation->currency_unit }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Purpose</dt>
                        <dd class="text-sm">{{ $reservation->purpose }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Provider</dt>
                        <dd class="text-sm">{{ $reservation->provider ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Idempotency Key</dt>
                        <dd class="font-mono text-xs break-all">{{ $reservation->idempotency_key }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-lg border border-border bg-surface p-6">
                <h2 class="mb-4 text-lg font-semibold">Lifecycle Timestamps</h2>
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-textSecondary">Created At</dt>
                        <dd class="text-sm">{{ $reservation->created_at->format('Y-m-d H:i:s') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Reserved At</dt>
                        <dd class="text-sm">{{ $reservation->reserved_at?->format('Y-m-d H:i:s') ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Expires At</dt>
                        <dd class="text-sm {{ $reservation->isPastExpiry() ? 'text-amber-600 font-medium' : '' }}">
                            {{ $reservation->expires_at?->format('Y-m-d H:i:s') ?? '-' }}
                            @if($reservation->expires_at)
                                ({{ $reservation->expires_at->diffForHumans() }})
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Captured At</dt>
                        <dd class="text-sm">{{ $reservation->captured_at?->format('Y-m-d H:i:s') ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Released At</dt>
                        <dd class="text-sm">{{ $reservation->released_at?->format('Y-m-d H:i:s') ?? '-' }}</dd>
                    </div>
                    @if($reservation->reason)
                        <div class="sm:col-span-2">
                            <dt class="text-xs text-textSecondary">Reason</dt>
                            <dd class="text-sm">{{ $reservation->reason }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            <div class="rounded-lg border border-border bg-surface p-6">
                <h2 class="mb-4 text-lg font-semibold">Context & Scope</h2>
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-textSecondary">Organization</dt>
                        <dd class="text-sm">{{ $reservation->organization?->name ?? '-' }} (ID: {{ $reservation->organization_id ?? '-' }})</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Client Site</dt>
                        <dd class="text-sm">{{ $reservation->wallet?->clientSite?->name ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Wallet ID</dt>
                        <dd class="font-mono text-xs">{{ $reservation->credit_wallet_id }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">User</dt>
                        <dd class="text-sm">{{ $reservation->user?->name ?? '-' }} ({{ $reservation->user?->email ?? '-' }})</dd>
                    </div>
                    @if($reservation->admin_user_id)
                        <div>
                            <dt class="text-xs text-textSecondary">Admin Actor</dt>
                            <dd class="text-sm">{{ $reservation->adminUser?->name ?? '-' }} ({{ $reservation->adminUser?->email ?? '-' }})</dd>
                        </div>
                    @endif
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-textSecondary">Context</dt>
                        <dd class="font-mono text-xs">
                            {{ $reservation->context_type ?? '-' }}<br>
                            {{ $reservation->context_id ?? '-' }}
                        </dd>
                    </div>
                </dl>
            </div>

            @if($contextPreview)
                <div class="rounded-lg border border-border bg-surface p-6">
                    <h2 class="mb-4 text-lg font-semibold">Context Preview ({{ $contextPreview['type'] }})</h2>
                    <dl class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs text-textSecondary">ID</dt>
                            <dd class="font-mono text-xs">{{ $contextPreview['id'] }}</dd>
                        </div>
                        @if(isset($contextPreview['status']))
                            <div>
                                <dt class="text-xs text-textSecondary">Status</dt>
                                <dd class="text-sm">{{ $contextPreview['status'] }}</dd>
                            </div>
                        @endif
                        @if(isset($contextPreview['has_output']))
                            <div>
                                <dt class="text-xs text-textSecondary">Has Output</dt>
                                <dd class="text-sm">{{ $contextPreview['has_output'] ? 'Yes' : 'No' }}</dd>
                            </div>
                        @endif
                        @if(isset($contextPreview['title']))
                            <div class="sm:col-span-2">
                                <dt class="text-xs text-textSecondary">Title</dt>
                                <dd class="text-sm">{{ $contextPreview['title'] }}</dd>
                            </div>
                        @endif
                        @if(isset($contextPreview['output_preview']) && $contextPreview['output_preview'])
                            <div class="sm:col-span-2">
                                <dt class="text-xs text-textSecondary">Output Preview</dt>
                                <dd class="text-xs text-textSecondary bg-background rounded p-2 mt-1">{{ $contextPreview['output_preview'] }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>
            @endif

            <div class="rounded-lg border border-border bg-surface p-6">
                <h2 class="mb-4 text-lg font-semibold">Ledger Entries</h2>
                <dl class="grid gap-4">
                    <div>
                        <dt class="text-xs text-textSecondary">Reservation Entry</dt>
                        <dd class="font-mono text-xs">{{ $reservation->reservation_ledger_entry_id ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Capture Entry</dt>
                        <dd class="font-mono text-xs">{{ $reservation->capture_ledger_entry_id ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-textSecondary">Release Entry</dt>
                        <dd class="font-mono text-xs">{{ $reservation->release_ledger_entry_id ?? '-' }}</dd>
                    </div>
                </dl>
            </div>

            @if($reservation->failure_code || $reservation->failure_message)
                <div class="rounded-lg border border-red-500/20 bg-red-500/5 p-6">
                    <h2 class="mb-4 text-lg font-semibold text-red-600">Failure Information</h2>
                    <dl class="grid gap-4">
                        @if($reservation->failure_code)
                            <div>
                                <dt class="text-xs text-textSecondary">Failure Code</dt>
                                <dd class="text-sm font-medium text-red-600">{{ $reservation->failure_code }}</dd>
                            </div>
                        @endif
                        @if($reservation->failure_message)
                            <div>
                                <dt class="text-xs text-textSecondary">Failure Message</dt>
                                <dd class="text-sm text-red-600 break-all">{{ $reservation->failure_message }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>
            @endif

            @if($reservation->metadata)
                <div class="rounded-lg border border-border bg-surface p-6">
                    <h2 class="mb-4 text-lg font-semibold">Metadata</h2>
                    <pre class="text-xs bg-background rounded p-4 overflow-x-auto">{{ json_encode($reservation->metadata, JSON_PRETTY_PRINT) }}</pre>
                </div>
            @endif
        </div>

        <div class="space-y-6">
            @if($reservation->isReserved())
                <div class="rounded-lg border border-border bg-surface p-6">
                    <h2 class="mb-4 text-lg font-semibold">Actions</h2>

                    <form method="POST" action="{{ route('admin.credit-reservations.release', $reservation) }}" class="mb-4">
                        @csrf
                        <label class="block text-xs text-textSecondary mb-1">Release Reservation</label>
                        <input type="text" name="reason" required placeholder="Reason for release" class="w-full rounded border border-border bg-background px-3 py-2 text-sm mb-2">
                        <button type="submit" class="w-full rounded border border-amber-500/50 bg-amber-500/10 px-4 py-2 text-sm text-amber-600 hover:bg-amber-500/20">
                            Release (Refund {{ $reservation->amount }} credits)
                        </button>
                    </form>

                    @if($contextPreview && ($contextPreview['has_output'] ?? false))
                        <form method="POST" action="{{ route('admin.credit-reservations.capture', $reservation) }}">
                            @csrf
                            <label class="block text-xs text-textSecondary mb-1">Capture Reservation</label>
                            <input type="text" name="reason" required placeholder="Reason for capture" class="w-full rounded border border-border bg-background px-3 py-2 text-sm mb-2">
                            <button type="submit" class="w-full rounded border border-green-500/50 bg-green-500/10 px-4 py-2 text-sm text-green-600 hover:bg-green-500/20">
                                Capture (Deduct {{ $reservation->amount }} credits)
                            </button>
                        </form>
                    @else
                        <div class="text-xs text-textSecondary bg-background rounded p-3">
                            Capture is disabled because the context has no output.
                        </div>
                    @endif
                </div>
            @else
                <div class="rounded-lg border border-border bg-surface p-6">
                    <h2 class="mb-4 text-lg font-semibold">Status</h2>
                    <p class="text-sm text-textSecondary">
                        This reservation has been {{ $reservation->status }} and cannot be modified.
                    </p>
                </div>
            @endif
        </div>
    </div>
@endsection
