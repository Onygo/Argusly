<?php

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\SiteSettingsService;
use Database\Seeders\PlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('Admin pricing and plans access control', function () {
    it('allows platform admin with billing permission to access billing index', function () {
        $admin = createPricingTestBillingAdmin();

        $this->actingAs($admin)
            ->get(route('admin.billing.index'))
            ->assertOk()
            ->assertSee('Billing Overview');
    });

    it('allows platform admin with billing permission to access pricing page content', function () {
        $admin = createPricingTestBillingAdmin();

        $this->actingAs($admin)
            ->get(route('admin.billing.pricing-page.index'))
            ->assertOk()
            ->assertSee('Pricing Page Content');
    });

    it('allows platform admin to update pricing page content', function () {
        $admin = createPricingTestBillingAdmin();

        $this->actingAs($admin)
            ->post(route('admin.billing.pricing-page.update'), [
                'hero_title' => 'Custom Pricing Title',
                'hero_subline' => 'Custom subline text',
                'why_title' => 'Why choose us?',
                'why_points' => "Point one\nPoint two\nPoint three",
            ])
            ->assertRedirect();

        $settings = app(SiteSettingsService::class);
        $content = $settings->get('pricing_page_content', []);

        expect($content['hero_title'])->toBe('Custom Pricing Title')
            ->and($content['hero_subline'])->toBe('Custom subline text')
            ->and($content['why_title'])->toBe('Why choose us?')
            ->and($content['why_points'])->toBe(['Point one', 'Point two', 'Point three']);
    });

    it('denies non-admin users access to billing index', function () {
        $user = createPricingTestRegularUser();

        $this->actingAs($user)
            ->get(route('admin.billing.index'))
            ->assertForbidden();
    });

    it('denies non-admin users access to pricing page content', function () {
        $user = createPricingTestRegularUser();

        $this->actingAs($user)
            ->get(route('admin.billing.pricing-page.index'))
            ->assertForbidden();
    });

    it('denies non-admin users from updating pricing page content', function () {
        $user = createPricingTestRegularUser();

        $this->actingAs($user)
            ->post(route('admin.billing.pricing-page.update'), [
                'hero_title' => 'Malicious Title',
            ])
            ->assertForbidden();
    });

    it('denies guests access to billing routes', function () {
        $this->get(route('admin.billing.index'))
            ->assertRedirect();

        $this->get(route('admin.billing.pricing-page.index'))
            ->assertRedirect();
    });

    it('allows admin to create new plans', function () {
        $admin = createPricingTestBillingAdmin();

        $this->actingAs($admin)
            ->post(route('admin.billing.plans.store'), [
                'name' => 'Test Plan',
                'slug' => 'test-plan-' . Str::random(8),
                'billing_type' => 'fixed',
                'sort_order' => 99,
                'price_monthly_cents' => 4900,
                'included_credits' => 100,
                'is_active' => '1',
                'is_public' => '1',
            ])
            ->assertRedirect();

        expect(Plan::query()->where('name', 'Test Plan')->exists())->toBeTrue();
    });

    it('denies non-admin from creating plans', function () {
        $user = createPricingTestRegularUser();

        $this->actingAs($user)
            ->post(route('admin.billing.plans.store'), [
                'name' => 'Malicious Plan',
                'slug' => 'malicious-plan',
                'billing_type' => 'fixed',
                'sort_order' => 99,
            ])
            ->assertForbidden();

        expect(Plan::query()->where('name', 'Malicious Plan')->exists())->toBeFalse();
    });

    it('allows admin to update existing plans', function () {
        $this->seed(PlansSeeder::class);
        $admin = createPricingTestBillingAdmin();
        $plan = Plan::query()->where('slug', 'platform_250')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.billing.plans.update', $plan), [
                'name' => 'Updated Platform 250',
                'slug' => 'platform_250',
                'billing_type' => 'fixed',
                'sort_order' => 1,
                'price_monthly_cents' => $plan->price_monthly_cents,
                'included_credits' => $plan->included_credits,
                'is_active' => '1',
                'is_public' => '1',
            ])
            ->assertRedirect();

        $plan->refresh();
        expect($plan->name)->toBe('Updated Platform 250');
    });

    it('validates onboarding fields when onboarding is required', function () {
        $this->seed(PlansSeeder::class);
        $admin = createPricingTestBillingAdmin();
        $plan = Plan::query()->where('slug', 'platform_250')->firstOrFail();

        $this->actingAs($admin)
            ->from(route('admin.billing.index'))
            ->post(route('admin.billing.plans.update', $plan), [
                'name' => 'Argusly Platform',
                'slug' => 'platform_250',
                'billing_type' => 'fixed',
                'sort_order' => 1,
                'price_monthly_cents' => $plan->price_monthly_cents,
                'included_credits' => $plan->included_credits,
                'is_active' => '1',
                'is_public' => '1',
                'has_required_onboarding' => '1',
                'onboarding_label' => '',
                'onboarding_fee_cents' => '',
            ])
            ->assertRedirect(route('admin.billing.index'))
            ->assertSessionHasErrors(['onboarding_label', 'onboarding_fee_cents']);
    });
});

function createPricingTestBillingAdmin(): User
{
    $organization = Organization::query()->create([
        'name' => 'Admin Org ' . Str::random(4),
        'slug' => 'admin-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => 'Billing Admin',
        'email' => 'billing-admin+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'superadmin',
    ]);
}

function createPricingTestRegularUser(): User
{
    $organization = Organization::query()->create([
        'name' => 'User Org ' . Str::random(4),
        'slug' => 'user-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => 'Regular User',
        'email' => 'user+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'editor',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
    ]);
}
