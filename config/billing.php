<?php

return [
    'default_provider' => env('BILLING_PROVIDER', 'mollie'),
    'pricing_mode' => env('BILLING_PRICING_MODE', 'vat_inclusive'),
    'allow_in_place_invoice_correction' => env('BILLING_ALLOW_IN_PLACE_INVOICE_CORRECTION', false),

    // Temporarily waive onboarding fees during promotional periods.
    // When true, all onboarding fees are set to 0 in calculations and hidden in UI.
    'onboarding_fee_waived' => env('ONBOARDING_FEE_WAIVED', false),

    'mollie' => [
        'key' => env('MOLLIE_KEY'),
        'profile_id' => env('MOLLIE_PROFILE_ID', 'me'),
    ],

    'urls' => [
        'pack_return' => env('BILLING_PACK_RETURN_URL'),
        'pack_webhook' => env('BILLING_PACK_WEBHOOK_URL'),
    ],

    'plan_change' => [
        'upgrade_strategy' => env('BILLING_UPGRADE_STRATEGY', 'next_period'),
        'downgrade_strategy' => env('BILLING_DOWNGRADE_STRATEGY', 'next_period'),
        'allow_immediate_downgrade' => env('BILLING_ALLOW_IMMEDIATE_DOWNGRADE', false),
        'immediate_upgrades_enabled' => env('BILLING_IMMEDIATE_UPGRADES_ENABLED', true),
        'prorated_upgrades_enabled' => env('BILLING_PRORATED_UPGRADES_ENABLED', true),
        // prorated_difference | full_difference | full_target | remaining_full_target
        'upgrade_charge_mode' => env('BILLING_UPGRADE_CHARGE_MODE', 'prorated_difference'),
    ],

    'entitlements' => [
        'cache_ttl_seconds' => (int) env('BILLING_ENTITLEMENTS_CACHE_TTL_SECONDS', 0),
    ],

    'dunning' => [
        'grace_days' => (int) env('BILLING_GRACE_DAYS', 7),
        'suspend_after_grace' => env('BILLING_SUSPEND_AFTER_GRACE', true),
    ],

    'credits' => [
        'included_rollover_enabled' => env('BILLING_INCLUDED_ROLLOVER', false),
        'consumption_order' => env('BILLING_CREDIT_CONSUMPTION_ORDER', 'included_first_then_addon'),
    ],
];
