<?php

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('splits product overview and pricing pages with credits-based pricing row', function () {
    Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'growth',
        'slug' => 'growth',
        'name' => 'Growth',
        'description_short' => 'Growth plan',
        'interval' => 'month',
        'price_monthly_cents' => 7900,
        'price_cents' => 7900,
        'currency' => 'EUR',
        'included_credits' => 20,
        'included_credits_per_interval' => 20,
        'limits' => [
            'sort_order' => 2,
            'has_required_onboarding' => true,
            'onboarding_label' => 'Guided onboarding',
            'onboarding_fee_cents' => 25000,
            'onboarding_description' => 'Includes guided onboarding for workspace setup, core structure and stronger publishing flow setup.',
        ],
        'seat_limit' => 2,
        'is_active' => true,
        'is_popular' => true,
        'sort_order' => 2,
    ]);

    Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'starter',
        'slug' => 'starter',
        'name' => 'Starter',
        'description_short' => 'Starter plan',
        'interval' => 'month',
        'price_monthly_cents' => 2900,
        'price_cents' => 2900,
        'currency' => 'EUR',
        'included_credits' => 10,
        'included_credits_per_interval' => 10,
        'limits' => [
            'sort_order' => 1,
            'has_required_onboarding' => true,
            'onboarding_label' => 'Guided onboarding',
            'onboarding_fee_cents' => 25000,
            'onboarding_description' => 'Includes guided onboarding for workspace setup, core structure and your first workflow.',
        ],
        'seat_limit' => 1,
        'is_active' => true,
        'is_popular' => false,
        'sort_order' => 1,
    ]);

    Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'scale',
        'slug' => 'scale',
        'name' => 'Scale',
        'description_short' => 'Scale plan',
        'interval' => 'month',
        'price_monthly_cents' => 19900,
        'price_cents' => 19900,
        'currency' => 'EUR',
        'included_credits' => 100,
        'included_credits_per_interval' => 100,
        'limits' => [
            'sort_order' => 3,
            'has_required_onboarding' => true,
            'onboarding_label' => 'Implementation onboarding',
            'onboarding_fee_cents' => 75000,
            'onboarding_description' => 'Includes implementation onboarding for structure, brand voice, team alignment and rollout support.',
        ],
        'seat_limit' => 10,
        'is_active' => true,
        'is_popular' => false,
        'sort_order' => 3,
    ]);

    $this->get('/nl')
        ->assertOk()
        ->assertDontSee('Schaalbare licenties', false)
        ->assertDontSee('Waarom Argusly vs traditionele SEO AI suites?', false);

    $productOverview = $this->get('/nl/product/overzicht');
    $productOverview->assertOk();
    $productOverview->assertDontSee('Schaalbare licenties', false);
    $productOverview->assertDontSee('Waarom Argusly vs traditionele SEO AI suites?', false);
    $productOverview->assertDontSee('AI credits per maand', false);

    $pricing = $this->get('/nl/prijzen');
    $pricing->assertOk();
    $pricing->assertSee(__('public.landing.pricing_title', [], 'nl'), false);
    $pricing->assertSee(__('public.landing.pricing_subline', [], 'nl'), false);
    $pricing->assertSee('credits / month', false);
    $pricing->assertDontSee('AI artikelen per maand', false);
    $pricing->assertSee('10', false);
    $pricing->assertSee('20', false);
    $pricing->assertSee('100', false);

    $this->get('/nl/prijzen')->assertOk()->assertSee('credits / month', false);
});
