<?php

namespace App\Services;

use App\Models\BillingSetting;

class BillingSettingsService
{
    private const ISSUER_KEY = 'invoice.issuer_profile';

    public function get(string $key, mixed $default = null): mixed
    {
        $row = BillingSetting::query()->where('key', $key)->first();

        return $row?->value ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        BillingSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public function getPlanChangeDefaults(): array
    {
        $defaults = [
            'upgrade_strategy' => (string) config('billing.plan_change.upgrade_strategy', 'next_period'),
            'downgrade_strategy' => (string) config('billing.plan_change.downgrade_strategy', 'next_period'),
            'allow_immediate_downgrade' => (bool) config('billing.plan_change.allow_immediate_downgrade', false),
            'immediate_upgrades_enabled' => (bool) config('billing.plan_change.immediate_upgrades_enabled', true),
            'prorated_upgrades_enabled' => (bool) config('billing.plan_change.prorated_upgrades_enabled', true),
            'upgrade_charge_mode' => (string) config('billing.plan_change.upgrade_charge_mode', 'prorated_difference'),
        ];

        $stored = (array) $this->get('plan_change.defaults', []);

        return array_merge($defaults, $stored);
    }

    public function getDunningDefaults(): array
    {
        return (array) $this->get('dunning.defaults', [
            'grace_days' => 7,
            'suspend_after_grace' => true,
        ]);
    }

    public function getCreditsDefaults(): array
    {
        return (array) $this->get('credits.defaults', [
            'included_rollover_enabled' => false,
            'consumption_order' => 'included_first_then_addon',
        ]);
    }

    public function getRecurringDefaults(): array
    {
        return (array) $this->get('mollie.recurring', [
            'mandate_retry_minutes' => 15,
            'mandate_retry_attempts' => 24,
            'recurring_method_allowlist' => ['creditcard', 'directdebit', 'paypal', 'bancontact'],
        ]);
    }

    public function getInvoiceIssuerProfile(): array
    {
        return (array) $this->get(self::ISSUER_KEY, [
            'company_name' => 'Argusly',
            'address_line1' => '',
            'address_line2' => '',
            'postal_code' => '',
            'city' => '',
            'country_code' => 'NL',
            'vat_number' => '',
            'kvk_number' => '',
            'email' => '',
            'website' => '',
            'logo_path' => 'images/argusly-logo.svg',
        ]);
    }

    public function putInvoiceIssuerProfile(array $profile): void
    {
        $this->put(self::ISSUER_KEY, $profile);
    }
}
