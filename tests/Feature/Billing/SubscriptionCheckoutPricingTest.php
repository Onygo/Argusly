<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionCheckoutPricing;

it('builds first checkout with recurring and onboarding line items for paid plans', function () {
    $plan = new Plan([
        'name' => 'Growth',
        'price_cents' => 12900,
        'currency' => 'EUR',
        'has_required_onboarding' => true,
        'onboarding_label' => 'Guided Onboarding',
        'onboarding_checkout_label' => 'Guided Onboarding',
        'onboarding_description' => 'Includes guided onboarding for workspace setup, core structure and stronger publishing flow setup.',
        'onboarding_fee_cents' => 25000,
        'onboarding_fee_currency' => 'EUR',
        'onboarding_display_mode' => 'guided_onboarding',
        'onboarding_is_visible_public' => true,
    ]);

    $summary = app(SubscriptionCheckoutPricing::class)->forInitialSubscription($plan);

    expect($summary['recurring_amount_cents'])->toBe(12900)
        ->and($summary['onboarding_amount_cents'])->toBe(25000)
        ->and($summary['total_due_today_cents'])->toBe(37900)
        ->and($summary['onboarding_charged'])->toBeTrue()
        ->and($summary['line_items'])->toHaveCount(2)
        ->and($summary['line_items'][0]['type'])->toBe('recurring')
        ->and($summary['line_items'][1]['code'])->toBe('onboarding')
        ->and($summary['line_items'][1]['label'])->toBe('Guided Onboarding');
});

it('skips onboarding for existing subscribed customers', function () {
    $plan = new Plan([
        'name' => 'Scale',
        'price_cents' => 29900,
        'currency' => 'EUR',
        'has_required_onboarding' => true,
        'onboarding_label' => 'Implementation Onboarding',
        'onboarding_fee_cents' => 75000,
        'onboarding_fee_currency' => 'EUR',
        'onboarding_display_mode' => 'implementation_onboarding',
    ]);

    $subscription = new Subscription([
        'status' => 'active',
        'provider_subscription_id' => 'sub_existing_001',
        'meta' => [
            'onboarding_paid' => true,
            'onboarding_paid_at' => now()->toIso8601String(),
        ],
    ]);

    $summary = app(SubscriptionCheckoutPricing::class)->forInitialSubscription($plan, $subscription);

    expect($summary['onboarding_amount_cents'])->toBe(0)
        ->and($summary['total_due_today_cents'])->toBe(29900)
        ->and($summary['line_items'])->toHaveCount(1)
        ->and($summary['line_items'][0]['code'])->toBe('subscription');
});

it('reflects admin-managed onboarding changes in new checkout output', function () {
    $plan = new Plan([
        'name' => 'Starter',
        'price_cents' => 2900,
        'currency' => 'EUR',
        'has_required_onboarding' => true,
        'onboarding_label' => 'Guided Onboarding',
        'onboarding_fee_cents' => 25000,
        'onboarding_fee_currency' => 'EUR',
        'onboarding_display_mode' => 'guided_onboarding',
    ]);

    $plan->onboarding_label = 'Launch Setup';
    $plan->onboarding_fee_cents = 39000;
    $plan->onboarding_display_mode = 'launch_setup';

    $summary = app(SubscriptionCheckoutPricing::class)->forInitialSubscription($plan);

    expect($summary['onboarding_amount_cents'])->toBe(39000)
        ->and($summary['total_due_today_cents'])->toBe(41900)
        ->and($summary['line_items'][1]['label'])->toBe('Launch Setup');
});

it('does not add onboarding when the plan does not require it', function () {
    $plan = new Plan([
        'name' => 'Free',
        'price_cents' => 0,
        'currency' => 'EUR',
        'has_required_onboarding' => false,
        'onboarding_fee_cents' => null,
    ]);

    $summary = app(SubscriptionCheckoutPricing::class)->forInitialSubscription($plan);

    expect($summary['onboarding_amount_cents'])->toBe(0)
        ->and($summary['line_items'])->toHaveCount(1);
});

it('waives onboarding fee when global waiver is enabled', function () {
    config(['billing.onboarding_fee_waived' => true]);

    $plan = new Plan([
        'name' => 'Growth',
        'price_cents' => 12900,
        'currency' => 'EUR',
        'has_required_onboarding' => true,
        'onboarding_label' => 'Guided Onboarding',
        'onboarding_fee_cents' => 25000,
        'onboarding_fee_currency' => 'EUR',
        'onboarding_display_mode' => 'guided_onboarding',
    ]);

    $summary = app(SubscriptionCheckoutPricing::class)->forInitialSubscription($plan);

    expect($summary['onboarding_amount_cents'])->toBe(0)
        ->and($summary['onboarding_charged'])->toBeFalse()
        ->and($summary['total_due_today_cents'])->toBe(12900)
        ->and($summary['line_items'])->toHaveCount(1)
        ->and($summary['line_items'][0]['code'])->toBe('subscription');
});

it('charges onboarding fee when global waiver is disabled', function () {
    config(['billing.onboarding_fee_waived' => false]);

    $plan = new Plan([
        'name' => 'Growth',
        'price_cents' => 12900,
        'currency' => 'EUR',
        'has_required_onboarding' => true,
        'onboarding_label' => 'Guided Onboarding',
        'onboarding_fee_cents' => 25000,
        'onboarding_fee_currency' => 'EUR',
        'onboarding_display_mode' => 'guided_onboarding',
    ]);

    $summary = app(SubscriptionCheckoutPricing::class)->forInitialSubscription($plan);

    expect($summary['onboarding_amount_cents'])->toBe(25000)
        ->and($summary['onboarding_charged'])->toBeTrue()
        ->and($summary['total_due_today_cents'])->toBe(37900)
        ->and($summary['line_items'])->toHaveCount(2);
});
