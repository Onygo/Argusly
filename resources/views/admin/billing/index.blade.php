@extends('layouts.admin', ['title' => 'Billing'])

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Billing Overview</h1>
        <p class="text-textSecondary mt-1">Credits status per company.</p>
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
            <input type="text" name="logo_path" value="{{ old('logo_path', $issuer['logo_path'] ?? 'images/publishlayer-logo.jpg') }}" placeholder="Logo path (public/)" class="rounded border border-border px-3 py-2 text-sm md:col-span-2">
            <div class="md:col-span-3">
                <button class="inline-flex items-center rounded border border-border px-3 py-1.5 text-xs">Save issuer profile</button>
            </div>
        </form>
    </div>

    <div class="rounded-lg border border-border bg-surface p-5 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-textSecondary">
                    <th class="pb-3 font-medium">Organization</th>
                    <th class="pb-3 font-medium">Sites</th>
                    <th class="pb-3 font-medium">Plan</th>
                    <th class="pb-3 font-medium">Status</th>
                    <th class="pb-3 font-medium">Next payment</th>
                    <th class="pb-3 font-medium">Monthly credits</th>
                    <th class="pb-3 font-medium">Remaining credits</th>
                    <th class="pb-3 font-medium">Mollie subscription id</th>
                    <th class="pb-3 font-medium">Payment health</th>
                    <th class="pb-3 font-medium">Seats</th>
                    <th class="pb-3 font-medium">Available</th>
                    <th class="pb-3 font-medium">Reserved</th>
                    <th class="pb-3 font-medium">Balance</th>
                    <th class="pb-3 font-medium">Invoices</th>
                    <th class="pb-3 font-medium">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($rows as $row)
                    <tr>
                        <td class="py-3">{{ $row['organization']->name }}</td>
                        <td class="py-3">{{ $row['sites_count'] }}</td>
                        <td class="py-3">{{ $row['plan_name'] }}</td>
                        <td class="py-3">{{ $row['subscription_status'] }}</td>
                        <td class="py-3">{{ optional($row['next_payment_at'])->format('Y-m-d') ?? 'n/a' }}</td>
                        <td class="py-3">{{ number_format((int) ($row['monthly_credits'] ?? 0)) }}</td>
                        <td class="py-3">{{ number_format((int) ($row['remaining_credits'] ?? 0)) }}</td>
                        <td class="py-3">
                            <span class="font-mono text-xs">{{ $row['mollie_subscription_id'] !== '' ? $row['mollie_subscription_id'] : '-' }}</span>
                        </td>
                        <td class="py-3">{{ $row['payment_health'] }}</td>
                        <td class="py-3">{{ $row['seat_usage'] }}@if($row['seat_limit'] > 0)/{{ $row['seat_limit'] }}@endif</td>
                        <td class="py-3">{{ $row['available'] }}</td>
                        <td class="py-3">{{ $row['reserved_cached'] }}</td>
                        <td class="py-3">{{ $row['balance_cached'] }}</td>
                        <td class="py-3">{{ $row['invoices_count'] }}</td>
                        <td class="py-3">
                            <a class="inline-flex items-center rounded border border-border px-3 py-1 text-xs" href="{{ route('admin.organizations.billing', $row['organization']) }}">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="15" class="py-4 text-textSecondary">No organizations found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @include('admin.billing.partials.plan-catalog')
@endsection
