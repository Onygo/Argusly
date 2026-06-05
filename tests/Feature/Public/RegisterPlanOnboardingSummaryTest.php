<?php

use Database\Seeders\PlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not show one time onboarding on the public registration plan flow for seeded pricing plans', function () {
    config(['billing.onboarding_fee_waived' => false]);
    $this->seed(PlansSeeder::class);

    $response = $this->get(route('register', ['plan' => 'scale']));

    $response->assertOk()
        ->assertDontSee('eenmalig', false)
        ->assertDontSee('EUR 750', false)
        ->assertSee(__('public.landing.pricing_register_credits_helper'));
});

it('does not surface waived onboarding messaging when seeded plans have no onboarding fee', function () {
    config(['billing.onboarding_fee_waived' => true]);
    $this->seed(PlansSeeder::class);

    $response = $this->get(route('register', ['plan' => 'scale']));

    $response->assertOk()
        ->assertDontSee('750.00 EUR eenmalig')
        ->assertDontSee(__('public.landing.pricing_register_onboarding_waived'));
});

it('keeps the seeded public registration flow focused on credits instead of onboarding fees', function () {
    config(['billing.onboarding_fee_waived' => false]);
    $this->seed(PlansSeeder::class);

    $response = $this->get(route('register', ['plan' => 'growth']));

    $response->assertOk()
        ->assertDontSee('250.00 EUR eenmalig')
        ->assertSee(__('public.landing.pricing_register_credits_helper'));
});
