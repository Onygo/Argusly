<?php

use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('allows superadmin to update enterprise as a custom plan without fixed prices', function () {
    $this->seed(PlansSeeder::class);

    $admin = makeBillingSuperadmin();
    $enterprise = Plan::query()->where('slug', 'enterprise_custom')->firstOrFail();

    $this->actingAs($admin)
        ->get(route('admin.billing.index'))
        ->assertOk()
        ->assertSee('Plan catalog', false)
        ->assertSee('Enterprise', false);

    $this->actingAs($admin)
        ->post(route('admin.billing.plans.update', $enterprise), [
            'name' => 'Enterprise Plus',
            'slug' => 'enterprise_custom',
            'description' => 'Custom onboarding and SLA.',
            'price_monthly_cents' => '',
            'price_yearly_cents' => '',
            'badge' => 'Custom',
            'cta_label' => 'Contact sales',
            'cta_url' => '/contact?subject=enterprise-plus#contact-form',
            'is_active' => '1',
            'is_public' => '1',
            'billing_type' => 'custom',
            'sort_order' => 4,
            'included_credits' => 0,
            'seat_limit' => 0,
            'currency' => 'EUR',
        ])
        ->assertRedirect();

    $enterprise->refresh();

    expect($enterprise->name)->toBe('Enterprise Plus')
        ->and($enterprise->billing_type)->toBe('custom')
        ->and($enterprise->price_monthly_cents)->toBeNull()
        ->and($enterprise->price_yearly_cents)->toBeNull()
        ->and($enterprise->cta_label)->toBe('Contact sales');
});

it('keeps only one featured public fixed plan when admin changes the featured plan', function () {
    $this->seed(PlansSeeder::class);

    $admin = makeBillingSuperadmin();
    $scale = Plan::query()->where('slug', 'platform_1000')->firstOrFail();

    $this->actingAs($admin)
        ->post(route('admin.billing.plans.update', $scale), [
            'name' => 'Argusly Platform',
            'slug' => 'platform_1000',
            'description' => $scale->description_short,
            'price_monthly_cents' => 19900,
            'price_yearly_cents' => 199000,
            'badge' => 'Top tier',
            'cta_label' => '',
            'cta_url' => '',
            'is_active' => '1',
            'is_public' => '1',
            'is_featured' => '1',
            'billing_type' => 'fixed',
            'sort_order' => 3,
            'included_credits' => 800,
            'seat_limit' => 10,
            'currency' => 'EUR',
        ])
        ->assertRedirect();

    expect(Plan::query()->where('billing_type', 'fixed')->where('is_featured', true)->pluck('slug')->all())
        ->toBe(['platform_1000']);

    expect(Plan::query()->where('slug', 'platform_500')->value('is_featured'))->toBeFalse();
});

it('allows superadmin to add plan features through the billing catalog', function () {
    $this->seed(PlansSeeder::class);

    $admin = makeBillingSuperadmin();
    $plan = Plan::query()->where('slug', 'platform_250')->firstOrFail();

    $this->actingAs($admin)
        ->post(route('admin.billing.plans.features.store', $plan), [
            'feature_key' => 'custom_reporting',
            'label' => 'Custom reporting',
            'feature_group' => 'Support',
            'is_highlight' => '1',
            'sort_order' => 900,
            'value_type' => 'bool',
            'value_bool' => '1',
        ])
        ->assertRedirect();

    $feature = PlanFeature::query()
        ->where('plan_id', $plan->id)
        ->where('feature_key', 'custom_reporting')
        ->first();

    expect($feature)->not->toBeNull()
        ->and($feature?->label)->toBe('Custom reporting')
        ->and($feature?->is_highlight)->toBeTrue()
        ->and($feature?->value_bool)->toBeTrue();
});

it('stores onboarding settings through the admin plan catalog', function () {
    $this->seed(PlansSeeder::class);

    $admin = makeBillingSuperadmin();
    $starter = Plan::query()->where('slug', 'platform_250')->firstOrFail();

    $this->actingAs($admin)
        ->post(route('admin.billing.plans.update', $starter), [
            'name' => 'Argusly Platform',
            'slug' => 'platform_250',
            'description' => $starter->description_short,
            'price_monthly_cents' => 2900,
            'price_yearly_cents' => 29000,
            'is_active' => '1',
            'is_public' => '1',
            'billing_type' => 'fixed',
            'sort_order' => 1,
            'included_credits' => 100,
            'seat_limit' => 1,
            'currency' => 'EUR',
            'has_required_onboarding' => '1',
            'onboarding_label' => 'Launch Setup',
            'onboarding_checkout_label' => 'Launch Setup',
            'onboarding_receipt_label' => 'Launch Setup',
            'onboarding_description' => 'Shown on public pricing and checkout.',
            'onboarding_fee_cents' => 39000,
            'onboarding_fee_currency' => 'EUR',
            'onboarding_display_mode' => 'launch_setup',
            'onboarding_is_visible_public' => '1',
            'onboarding_sort_order' => 5,
        ])
        ->assertRedirect();

    $starter->refresh();

    expect($starter->has_required_onboarding)->toBeTrue()
        ->and($starter->onboarding_label)->toBe('Launch Setup')
        ->and($starter->onboarding_fee_cents)->toBe(39000)
        ->and($starter->onboarding_display_mode)->toBe('launch_setup')
        ->and($starter->onboarding_is_visible_public)->toBeTrue();
});

function makeBillingSuperadmin(): User
{
    $organization = Organization::query()->create([
        'name' => 'Billing Admin Org ' . Str::lower(Str::random(4)),
        'slug' => 'billing-admin-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => 'Billing Superadmin',
        'email' => 'billing-superadmin+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'superadmin',
    ]);
}
