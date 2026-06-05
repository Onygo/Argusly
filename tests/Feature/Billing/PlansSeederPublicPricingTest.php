<?php

use App\Models\Plan;
use Database\Seeders\PlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the canonical public pricing plans idempotently', function () {
    $this->seed(PlansSeeder::class);
    $this->seed(PlansSeeder::class);

    $plans = Plan::query()
        ->whereIn('slug', ['creator', 'growth', 'scale', 'enterprise'])
        ->orderBy('sort_order')
        ->get()
        ->keyBy('slug');

    expect($plans)->toHaveCount(4)
        ->and($plans->keys()->all())->toBe(['creator', 'growth', 'scale', 'enterprise'])
        ->and($plans['creator']->billing_type)->toBe('fixed')
        ->and($plans['growth']->billing_type)->toBe('fixed')
        ->and($plans['scale']->billing_type)->toBe('fixed')
        ->and($plans['enterprise']->billing_type)->toBe('custom')
        ->and($plans['growth']->is_featured)->toBeTrue()
        ->and($plans['growth']->sort_order)->toBe(2)
        ->and($plans['enterprise']->price_monthly_cents)->toBeNull()
        ->and($plans['enterprise']->price_yearly_cents)->toBeNull()
        ->and($plans['creator']->included_credits_per_interval)->toBe(100)
        ->and($plans['growth']->included_credits_per_interval)->toBe(500)
        ->and($plans['scale']->included_credits_per_interval)->toBe(2000)
        ->and($plans['creator']->article_estimate_min)->toBe(7)
        ->and($plans['scale']->article_estimate_max)->toBe(200)
        ->and($plans['creator']->has_required_onboarding)->toBeFalse()
        ->and($plans['growth']->credit_expiry_days)->toBe(90);

    expect(Plan::query()
        ->where('billing_type', 'fixed')
        ->where('is_featured', true)
        ->count())->toBe(1);
});
