<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AppCompanyOnboardingController extends Controller
{
    public function show(Request $request): View
    {
        $organization = $request->user()?->organization;

        return view('app.onboarding.company', [
            'organization' => $organization,
            'canManageOrganization' => Gate::forUser($request->user())->allows('manage-organization'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('manage-organization');

        $organization = $request->user()?->organization;
        if (! $organization) {
            return back()->withErrors(['billing' => 'No organization found.']);
        }

        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:64'],
            'city' => ['nullable', 'string', 'max:128'],
            'country_code' => ['required', 'string', 'size:2'],
            'vat_number' => ['nullable', 'string', 'max:64'],
            'kvk_number' => ['nullable', 'string', 'max:64'],
        ]);

        $organization->billing_company_name = (string) $data['company_name'];
        $organization->billing_address_line1 = (string) $data['address_line1'];
        $organization->billing_address_line2 = $data['address_line2'] ?: null;
        $organization->billing_postal_code = $data['postal_code'] ?: null;
        $organization->billing_city = $data['city'] ?: null;
        $organization->billing_country_code = strtoupper((string) $data['country_code']);
        $organization->billing_vat_number = $data['vat_number'] ?: null;
        $organization->billing_kvk_number = $data['kvk_number'] ?: null;
        $organization->billing_address = [
            'line1' => $data['address_line1'],
            'line2' => $data['address_line2'] ?: null,
            'postal_code' => $data['postal_code'] ?: null,
            'city' => $data['city'] ?: null,
            'country_code' => strtoupper((string) $data['country_code']),
        ];
        $organization->save();

        return redirect()
            ->intended(route('app.dashboard'))
            ->with('status', 'Bedrijfsgegevens opgeslagen.');
    }
}
