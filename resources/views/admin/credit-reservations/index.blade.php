@extends('layouts.admin', ['title' => 'Credit Reservations'])

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Credit Reservations</h1>
            <p class="mt-1 text-textSecondary">Manage credit reservations for AI operations.</p>
        </div>
        <form method="POST" action="{{ route('admin.credit-reservations.expire-stale') }}" class="inline">
            @csrf
            <button type="submit" class="rounded border border-border bg-background px-3 py-2 text-sm hover:bg-surface">
                Expire Stale Reservations
            </button>
        </form>
    </div>

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

    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-6">
        <div class="rounded-lg border border-border bg-surface p-4">
            <div class="text-2xl font-bold text-textPrimary">{{ $stats['total_reserved'] }}</div>
            <div class="text-xs text-textSecondary">Active Reservations</div>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <div class="text-2xl font-bold text-textPrimary">{{ number_format($stats['total_reserved_credits']) }}</div>
            <div class="text-xs text-textSecondary">Reserved Credits</div>
        </div>
        <div class="rounded-lg border border-amber-500/20 bg-amber-500/10 p-4">
            <div class="text-2xl font-bold text-amber-600">{{ $stats['stale_count'] }}</div>
            <div class="text-xs text-textSecondary">Stale (Expired TTL)</div>
        </div>
        <div class="rounded-lg border border-amber-500/20 bg-amber-500/10 p-4">
            <div class="text-2xl font-bold text-amber-600">{{ number_format($stats['stale_credits']) }}</div>
            <div class="text-xs text-textSecondary">Stale Credits</div>
        </div>
        <div class="rounded-lg border border-green-500/20 bg-green-500/10 p-4">
            <div class="text-2xl font-bold text-green-600">{{ $stats['captured_today'] }}</div>
            <div class="text-xs text-textSecondary">Captured Today</div>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <div class="text-2xl font-bold text-textPrimary">{{ $stats['released_today'] }}</div>
            <div class="text-xs text-textSecondary">Released Today</div>
        </div>
    </div>

    <form method="GET" class="mb-4 grid gap-2 lg:grid-cols-6">
        <select name="status" class="rounded border border-border bg-background px-2 py-2 text-xs">
            @foreach ($statuses as $value => $label)
                <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <select name="purpose" class="rounded border border-border bg-background px-2 py-2 text-xs">
            @foreach ($purposes as $value => $label)
                <option value="{{ $value }}" @selected($filters['purpose'] === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <select name="organization_id" class="rounded border border-border bg-background px-2 py-2 text-xs">
            <option value="">All Organizations</option>
            @foreach ($organizations as $organization)
                <option value="{{ $organization->id }}" @selected($filters['organization_id'] == $organization->id)>{{ $organization->name }}</option>
            @endforeach
        </select>
        <input type="text" name="context_id" value="{{ $filters['context_id'] }}" placeholder="Context ID" class="rounded border border-border bg-background px-2 py-2 text-xs">
        <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Search ID/key/message" class="rounded border border-border bg-background px-2 py-2 text-xs">
        <div class="flex items-center gap-2">
            <label class="flex items-center gap-1 text-xs">
                <input type="checkbox" name="stale_only" value="1" @checked($filters['stale_only'])>
                Stale only
            </label>
        </div>
        <div class="lg:col-span-6 flex gap-2">
            <button class="rounded border border-border px-3 py-1.5 text-xs">Apply filters</button>
            <a href="{{ route('admin.credit-reservations.index') }}" class="rounded border border-border px-3 py-1.5 text-xs text-textSecondary">Reset</a>
        </div>
    </form>

    <form method="POST" action="{{ route('admin.credit-reservations.bulk-release') }}" id="bulkReleaseForm">
        @csrf
        <div class="mb-2 hidden items-center gap-2" id="bulkActions">
            <span class="text-xs text-textSecondary"><span id="selectedCount">0</span> selected</span>
            <input type="text" name="reason" placeholder="Release reason (required)" required class="rounded border border-border bg-background px-2 py-1 text-xs">
            <button type="submit" class="rounded border border-red-500/50 bg-red-500/10 px-3 py-1.5 text-xs text-red-600">Release Selected</button>
        </div>

        <div class="rounded-lg border border-border bg-surface p-4 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-textSecondary">
                        <th class="pb-3 font-medium">
                            <input type="checkbox" id="selectAll" class="rounded">
                        </th>
                        <th class="pb-3 font-medium">ID</th>
                        <th class="pb-3 font-medium">Status</th>
                        <th class="pb-3 font-medium">Amount</th>
                        <th class="pb-3 font-medium">Purpose</th>
                        <th class="pb-3 font-medium">Organization</th>
                        <th class="pb-3 font-medium">User</th>
                        <th class="pb-3 font-medium">Context</th>
                        <th class="pb-3 font-medium">Expires</th>
                        <th class="pb-3 font-medium">Created</th>
                        <th class="pb-3 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($reservations as $reservation)
                        <tr class="{{ $reservation->isPastExpiry() && $reservation->isReserved() ? 'bg-amber-500/5' : '' }}">
                            <td class="py-3">
                                @if($reservation->isReserved())
                                    <input type="checkbox" name="reservation_ids[]" value="{{ $reservation->id }}" class="reservation-checkbox rounded">
                                @endif
                            </td>
                            <td class="py-3">
                                <a href="{{ route('admin.credit-reservations.show', $reservation) }}" class="font-mono text-xs text-primary hover:underline">
                                    {{ substr($reservation->id, 0, 8) }}...
                                </a>
                            </td>
                            <td class="py-3">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium
                                    {{ $reservation->status === 'reserved' ? 'bg-blue-500/10 text-blue-600' : '' }}
                                    {{ $reservation->status === 'captured' ? 'bg-green-500/10 text-green-600' : '' }}
                                    {{ $reservation->status === 'released' ? 'bg-gray-500/10 text-gray-600' : '' }}
                                    {{ $reservation->status === 'expired' ? 'bg-amber-500/10 text-amber-600' : '' }}
                                ">
                                    {{ $reservation->status }}
                                </span>
                            </td>
                            <td class="py-3 font-medium">{{ $reservation->amount }}</td>
                            <td class="py-3">{{ $reservation->purpose }}</td>
                            <td class="py-3">{{ $reservation->organization?->name ?? '-' }}</td>
                            <td class="py-3">{{ $reservation->user?->name ?? '-' }}</td>
                            <td class="py-3 font-mono text-xs">
                                @if($reservation->context_id)
                                    {{ class_basename($reservation->context_type) }}: {{ substr($reservation->context_id, 0, 8) }}...
                                @else
                                    -
                                @endif
                            </td>
                            <td class="py-3 text-xs {{ $reservation->isPastExpiry() ? 'text-amber-600 font-medium' : '' }}">
                                {{ $reservation->expires_at?->diffForHumans() ?? '-' }}
                            </td>
                            <td class="py-3 text-xs">{{ $reservation->created_at->format('Y-m-d H:i') }}</td>
                            <td class="py-3">
                                <a href="{{ route('admin.credit-reservations.show', $reservation) }}" class="rounded border border-border px-2 py-1 text-xs hover:bg-background">
                                    View
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="11" class="py-4 text-textSecondary">No reservations found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </form>

    <div class="mt-4">{{ $reservations->links() }}</div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.reservation-checkbox');
            const bulkActions = document.getElementById('bulkActions');
            const selectedCount = document.getElementById('selectedCount');

            function updateBulkActions() {
                const checked = document.querySelectorAll('.reservation-checkbox:checked').length;
                selectedCount.textContent = checked;
                bulkActions.classList.toggle('hidden', checked === 0);
                bulkActions.classList.toggle('flex', checked > 0);
            }

            selectAll.addEventListener('change', function() {
                checkboxes.forEach(cb => cb.checked = this.checked);
                updateBulkActions();
            });

            checkboxes.forEach(cb => cb.addEventListener('change', updateBulkActions));
        });
    </script>
@endsection
