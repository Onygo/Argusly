@extends('layouts.admin', ['title' => 'Invoices'])

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Invoices</h1>
        <p class="mt-1 text-textSecondary">All subscription and credit-pack invoices.</p>
    </div>

    <form method="GET" class="mb-4 grid gap-2 lg:grid-cols-5">
        <select name="organization_id" class="rounded border border-border bg-background px-2 py-2 text-xs">
            <option value="">All organizations</option>
            @foreach ($organizations as $organization)
                <option value="{{ $organization->id }}" @selected($filters['organization_id'] == $organization->id)>{{ $organization->name }}</option>
            @endforeach
        </select>
        <select name="type" class="rounded border border-border bg-background px-2 py-2 text-xs">
            <option value="">All types</option>
            <option value="subscription" @selected($filters['type'] === 'subscription')>subscription</option>
            <option value="credit_pack" @selected($filters['type'] === 'credit_pack')>credit_pack</option>
        </select>
        <select name="status" class="rounded border border-border bg-background px-2 py-2 text-xs">
            <option value="">All statuses</option>
            <option value="issued" @selected($filters['status'] === 'issued')>issued</option>
            <option value="refunded" @selected($filters['status'] === 'refunded')>refunded</option>
        </select>
        <input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Search number/company/refund ref" class="rounded border border-border bg-background px-2 py-2 text-xs lg:col-span-2">
        <div class="lg:col-span-5 flex gap-2">
            <button class="rounded border border-border px-3 py-1.5 text-xs">Apply filters</button>
            <a href="{{ route('admin.invoices.index') }}" class="rounded border border-border px-3 py-1.5 text-xs text-textSecondary">Reset</a>
        </div>
    </form>

    <div class="rounded-lg border border-border bg-surface p-4 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-textSecondary">
                    <th class="pb-3 font-medium">Number</th>
                    <th class="pb-3 font-medium">Organization</th>
                    <th class="pb-3 font-medium">Type</th>
                    <th class="pb-3 font-medium">Status</th>
                    <th class="pb-3 font-medium">Total</th>
                    <th class="pb-3 font-medium">Issued</th>
                    <th class="pb-3 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse($invoices as $invoice)
                    <tr>
                        <td class="py-3">{{ $invoice->number }}</td>
                        <td class="py-3">{{ $invoice->organization?->name }}</td>
                        <td class="py-3">{{ $invoice->type }}</td>
                        <td class="py-3">{{ $invoice->status }}</td>
                        <td class="py-3">{{ number_format((float) ($invoice->total_gross ?? ($invoice->total_cents / 100)), 2) }} {{ $invoice->currency }}</td>
                        <td class="py-3">{{ optional($invoice->issued_at)->format('Y-m-d') }}</td>
                        <td class="py-3">
                            <div class="flex items-center gap-2">
                                <a href="{{ route('admin.invoices.download', $invoice) }}" class="inline-flex items-center rounded border border-border px-2 py-1 text-xs">Download</a>
                                @if($invoice->status !== 'refunded')
                                    <form method="POST" action="{{ route('admin.invoices.refund', $invoice) }}" class="inline-flex items-center gap-2">
                                        @csrf
                                        <input name="refund_reference" required maxlength="128" placeholder="Refund ref" class="rounded border border-border bg-background px-2 py-1 text-xs">
                                        <button class="inline-flex items-center rounded border border-border px-2 py-1 text-xs">Mark refunded</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-4 text-textSecondary">No invoices found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $invoices->links() }}</div>
@endsection
