@extends('layouts.app', ['title' => 'Bedrijfsgegevens', 'pageWidth' => 'constrained'])

@section('content')
    <div class="mx-auto max-w-3xl space-y-6">
        <div class="rounded-lg border border-border bg-surface p-5">
            <h1 class="text-xl font-semibold text-textPrimary">Bedrijfsgegevens</h1>
        </div>

        <div class="rounded-lg border border-border bg-surface p-5">
            @if ($canManageOrganization)
                <form method="POST" action="{{ route('app.onboarding.company.update') }}" class="grid gap-3 md:grid-cols-2">
                    @csrf
                    <x-alert class="mb-1 md:col-span-2" :icon="true">
                        Vul je bedrijfsgegevens in om te kunnen starten met PublishLayer.
                    </x-alert>
                    <input type="text" name="company_name" value="{{ old('company_name', $organization->billing_company_name ?? $organization->name) }}" placeholder="Bedrijfsnaam" class="rounded border border-border bg-background px-3 py-2 text-sm md:col-span-2" required>
                    <input type="text" name="address_line1" value="{{ old('address_line1', $organization->billing_address_line1) }}" placeholder="Adresregel 1" class="rounded border border-border bg-background px-3 py-2 text-sm md:col-span-2" required>
                    <input type="text" name="address_line2" value="{{ old('address_line2', $organization->billing_address_line2) }}" placeholder="Adresregel 2 (optioneel)" class="rounded border border-border bg-background px-3 py-2 text-sm md:col-span-2">
                    <input type="text" name="postal_code" value="{{ old('postal_code', $organization->billing_postal_code) }}" placeholder="Postcode" class="rounded border border-border bg-background px-3 py-2 text-sm">
                    <input type="text" name="city" value="{{ old('city', $organization->billing_city) }}" placeholder="Plaats" class="rounded border border-border bg-background px-3 py-2 text-sm">
                    <input type="text" name="country_code" value="{{ old('country_code', $organization->billing_country_code ?? 'NL') }}" placeholder="Landcode" class="rounded border border-border bg-background px-3 py-2 text-sm" required>
                    <input type="text" name="vat_number" value="{{ old('vat_number', $organization->billing_vat_number) }}" placeholder="BTW nummer (optioneel)" class="rounded border border-border bg-background px-3 py-2 text-sm">
                    <input type="text" name="kvk_number" value="{{ old('kvk_number', $organization->billing_kvk_number) }}" placeholder="KvK nummer (optioneel)" class="rounded border border-border bg-background px-3 py-2 text-sm">
                    <div class="md:col-span-2">
                        <button type="submit" class="pl-btn-primary">Opslaan en doorgaan</button>
                    </div>
                </form>
            @else
                <p class="text-sm text-textSecondary">
                    Alleen een eigenaar of admin van je organisatie kan deze gegevens aanpassen.
                </p>
            @endif
        </div>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        @if($errors->any())
            <div class="rounded-md border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-danger">
                {{ $errors->first() }}
            </div>
        @endif
    </div>
@endsection
