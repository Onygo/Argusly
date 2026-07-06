<?php

use App\Models\Plan;
use Database\Seeders\PlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the canonical public pricing plans idempotently', function () {
    $this->seed(PlansSeeder::class);
    $this->seed(PlansSeeder::class);

    $plans = Plan::query()
        ->whereIn('slug', ['platform_250', 'platform_500', 'platform_1000', 'platform_2000', 'enterprise_custom'])
        ->orderBy('sort_order')
        ->get()
        ->keyBy('slug');

    expect($plans)->toHaveCount(5)
        ->and($plans->keys()->all())->toBe(['platform_250', 'platform_500', 'platform_1000', 'platform_2000', 'enterprise_custom'])
        ->and($plans['platform_250']->billing_type)->toBe('fixed')
        ->and($plans['platform_500']->billing_type)->toBe('fixed')
        ->and($plans['platform_1000']->billing_type)->toBe('fixed')
        ->and($plans['platform_2000']->billing_type)->toBe('fixed')
        ->and($plans['enterprise_custom']->billing_type)->toBe('custom')
        ->and($plans['platform_500']->is_featured)->toBeTrue()
        ->and($plans['platform_500']->sort_order)->toBe(2)
        ->and($plans['enterprise_custom']->price_monthly_cents)->toBeNull()
        ->and($plans['enterprise_custom']->price_yearly_cents)->toBeNull()
        ->and($plans['platform_250']->included_credits_per_interval)->toBe(250)
        ->and($plans['platform_500']->included_credits_per_interval)->toBe(500)
        ->and($plans['platform_2000']->included_credits_per_interval)->toBe(2000)
        ->and($plans['platform_250']->article_estimate_min)->toBeNull()
        ->and($plans['platform_2000']->article_estimate_max)->toBeNull()
        ->and($plans['platform_250']->has_required_onboarding)->toBeFalse()
        ->and($plans['platform_500']->credit_expiry_days)->toBe(90);

    expect(Plan::query()
        ->where('billing_type', 'fixed')
        ->where('is_featured', true)
        ->count())->toBe(1);
});
