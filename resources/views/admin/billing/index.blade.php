@extends('layouts.admin', ['title' => 'Billing'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Billing Overview</x-slot:title>
        <x-slot:description>Credits status per company.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    <div class="mb-6">
        <a href="{{ route('admin.invoices.index') }}" class="mt-2 inline-flex items-center rounded border border-border px-3 py-1.5 text-xs">Open invoice registry</a>
    </div>

    <div class="mb-6 rounded-lg border border-border bg-surface p-5">
        <h2 class="mb-3 text-sm font-semibold text-textPrimary">Invoice Issuer (Argusly)</h2>
        <form method="POST" action="{{ route('admin.billing.invoice-issuer.update') }}" class="grid gap-2 md:grid-cols-3">
            @csrf
            <input type="text" name="company_name" value="{{ old('company_name', $issuer['company_name'] ?? '') }}" placeholder="Company name" class="rounded border border-border px-3 py-2 text-sm" required>
            <input type="text" name="country_code" value="{{ old('country_code', $issuer['country_code'] ?? 'NL') }}" placeholder="Country code" class="rounded border border-border px-3 py-2 text-sm">
            <input type="text" name="kvk_number" value="{{ old('kvk_number', $issuer['kvk_number'] ?? '') }}" placeholder="KvK number" class="rounded border border-border px-3 py-2 text-sm">
            <input type="text" name="address_line1" value="{{ old('address_line1', $issuer['address_line1'] ?? '') }}" placeholder="Address line 1" class="rounded border border-border px-3 py-2 text-sm md:col-span-2">
            <input type="text" name="address_line2" value="{{ old('address_line2', $issuer['address_line2'] ?? '') }}" placeholder="Address line 2" class="rounded border border-border px-3 py-2 text-sm">
            <input type="text" name="postal_code" value="{{ old('postal_code', $issuer['postal_code'] ?? '') }}" placeholder="Postal code" class="rounded border border-border px-3 py-2 text-sm">
            <input type="text" name="city" value="{{ old('city', $issuer['city'] ?? '') }}" placeholder="City" class="rounded border border-border px-3 py-2 text-sm">
            <input type="text" name="vat_number" value="{{ old('vat_number', $issuer['vat_number'] ?? '') }}" placeholder="VAT number" class="rounded border border-border px-3 py-2 text-sm">
            <input type="text" name="email" value="{{ old('email', $issuer['email'] ?? '') }}" placeholder="Email" class="rounded border border-border px-3 py-2 text-sm">
            <input type="text" name="website" value="{{ old('website', $issuer['website'] ?? '') }}" placeholder="Website" class="rounded border border-border px-3 py-2 text-sm">
            <input type="text" name="logo_path" value="{{ old('logo_path', $issuer['logo_path'] ?? 'images/argusly-logo.svg') }}" placeholder="Logo path (public/)" class="rounded border border-border px-3 py-2 text-sm md:col-span-2">
            <div class="md:col-span-3">
                <button class="inline-flex items-center rounded border border-border px-3 py-1.5 text-xs">Save issuer profile</button>
            </div>
        </form>
    </div>

    <x-data-table label="Billing organizations" description="Credit, subscription, payment health, usage, and invoice counts per organization." density="compact" table-class="min-w-[1200px]">
        <x-data-table.header>
            <x-data-table.row>
                <x-data-table.cell heading>Organization</x-data-table.cell>
                <x-data-table.cell heading>Sites</x-data-table.cell>
                <x-data-table.cell heading>Plan</x-data-table.cell>
                <x-data-table.cell heading>Status</x-data-table.cell>
                <x-data-table.cell heading>Next payment</x-data-table.cell>
                <x-data-table.cell heading>Monthly credits</x-data-table.cell>
                <x-data-table.cell heading>Remaining credits</x-data-table.cell>
                <x-data-table.cell heading>Mollie subscription id</x-data-table.cell>
                <x-data-table.cell heading>Payment health</x-data-table.cell>
                <x-data-table.cell heading>Seats</x-data-table.cell>
                <x-data-table.cell heading>Available</x-data-table.cell>
                <x-data-table.cell heading>Reserved</x-data-table.cell>
                <x-data-table.cell heading>Balance</x-data-table.cell>
                <x-data-table.cell heading>Invoices</x-data-table.cell>
                <x-data-table.cell heading>Action</x-data-table.cell>
            </x-data-table.row>
        </x-data-table.header>
        <tbody class="divide-y divide-border">
            @forelse ($rows as $row)
                <x-data-table.row>
                    <x-data-table.cell label="Organization">{{ $row['organization']->name }}</x-data-table.cell>
                    <x-data-table.cell label="Sites">{{ $row['sites_count'] }}</x-data-table.cell>
                    <x-data-table.cell label="Plan">{{ $row['plan_name'] }}</x-data-table.cell>
                    <x-data-table.cell label="Status">
                        <x-data-table.badge :label="$row['subscription_status']" />
                    </x-data-table.cell>
                    <x-data-table.cell label="Next payment">{{ optional($row['next_payment_at'])->format('Y-m-d') ?? 'n/a' }}</x-data-table.cell>
                    <x-data-table.cell label="Monthly credits">{{ number_format((int) ($row['monthly_credits'] ?? 0)) }}</x-data-table.cell>
                    <x-data-table.cell label="Remaining credits">{{ number_format((int) ($row['remaining_credits'] ?? 0)) }}</x-data-table.cell>
                    <x-data-table.cell label="Mollie subscription id">
                        <span class="font-mono text-xs">{{ $row['mollie_subscription_id'] !== '' ? $row['mollie_subscription_id'] : '-' }}</span>
                    </x-data-table.cell>
                    <x-data-table.cell label="Payment health">
                        <x-data-table.badge :tone="$row['payment_health'] === 'healthy' ? 'success' : ($row['payment_health'] === 'attention' ? 'warning' : 'neutral')" :label="$row['payment_health']" />
                    </x-data-table.cell>
                    <x-data-table.cell label="Seats">{{ $row['seat_usage'] }}@if($row['seat_limit'] > 0)/{{ $row['seat_limit'] }}@endif</x-data-table.cell>
                    <x-data-table.cell label="Available">{{ $row['available'] }}</x-data-table.cell>
                    <x-data-table.cell label="Reserved">{{ $row['reserved_cached'] }}</x-data-table.cell>
                    <x-data-table.cell label="Balance">{{ $row['balance_cached'] }}</x-data-table.cell>
                    <x-data-table.cell label="Invoices">{{ $row['invoices_count'] }}</x-data-table.cell>
                    <x-data-table.cell label="Action">
                        <x-data-table.actions align="start">
                            <a class="inline-flex items-center rounded border border-border px-3 py-1 text-xs" href="{{ route('admin.organizations.billing', $row['organization']) }}">View</a>
                        </x-data-table.actions>
                    </x-data-table.cell>
                </x-data-table.row>
            @empty
                <x-data-table.empty colspan="15" title="No organizations found" />
            @endforelse
        </tbody>
    </x-data-table>

    @include('admin.billing.partials.plan-catalog')
@endsection
