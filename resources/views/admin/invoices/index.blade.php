@extends('layouts.admin', ['title' => 'Invoices'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Invoices</x-slot:title>
        <x-slot:description>All subscription and credit-pack invoices.</x-slot:description>
    </x-page-header>
@endsection

@section('content')

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

    <x-data-table label="Invoices" description="Subscription and credit-pack invoices with organization, type, status, totals, issue date, and actions." density="compact">
            <x-data-table.header>
                <x-data-table.row>
                    <x-data-table.cell heading>Number</x-data-table.cell>
                    <x-data-table.cell heading>Organization</x-data-table.cell>
                    <x-data-table.cell heading>Type</x-data-table.cell>
                    <x-data-table.cell heading>Status</x-data-table.cell>
                    <x-data-table.cell heading>Total</x-data-table.cell>
                    <x-data-table.cell heading>Issued</x-data-table.cell>
                    <x-data-table.cell heading>Actions</x-data-table.cell>
                </x-data-table.row>
            </x-data-table.header>
            <tbody>
                @forelse($invoices as $invoice)
                    <x-data-table.row>
                        <x-data-table.cell label="Number">{{ $invoice->number }}</x-data-table.cell>
                        <x-data-table.cell label="Organization">{{ $invoice->organization?->name }}</x-data-table.cell>
                        <x-data-table.cell label="Type" class="text-textSecondary">{{ $invoice->type }}</x-data-table.cell>
                        <x-data-table.cell label="Status">
                            <x-data-table.badge :tone="$invoice->status === 'refunded' ? 'warning' : 'success'" :label="$invoice->status" />
                        </x-data-table.cell>
                        <x-data-table.cell label="Total">{{ number_format((float) ($invoice->total_gross ?? ($invoice->total_cents / 100)), 2) }} {{ $invoice->currency }}</x-data-table.cell>
                        <x-data-table.cell label="Issued">{{ optional($invoice->issued_at)->format('Y-m-d') }}</x-data-table.cell>
                        <x-data-table.cell label="Actions">
                            <x-data-table.actions align="start">
                                <a href="{{ route('admin.invoices.download', $invoice) }}" class="inline-flex items-center rounded border border-border px-2 py-1 text-xs">Download</a>
                                @if($invoice->status !== 'refunded')
                                    <form method="POST" action="{{ route('admin.invoices.refund', $invoice) }}" class="inline-flex items-center gap-2">
                                        @csrf
                                        <input name="refund_reference" required maxlength="128" placeholder="Refund ref" class="rounded border border-border bg-background px-2 py-1 text-xs">
                                        <button class="inline-flex items-center rounded border border-border px-2 py-1 text-xs">Mark refunded</button>
                                    </form>
                                @endif
                            </x-data-table.actions>
                        </x-data-table.cell>
                    </x-data-table.row>
                @empty
                    <x-data-table.empty colspan="7" title="No invoices found" />
                @endforelse
            </tbody>
        <x-slot:pagination>{{ $invoices->links() }}</x-slot:pagination>
    </x-data-table>
@endsection
