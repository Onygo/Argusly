<?php

use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Services\Entitlements\EntitlementRefreshService;
use App\Services\Entitlements\FeatureGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('resolves feature access from plan features and workspace entitlements', function () {
    $organization = Organization::create([
        'name' => 'Gate Org',
        'slug' => 'gate-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Gate WS',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Gate Site',
        'site_url' => 'https://gate.example.com',
        'allowed_domains' => ['gate.example.com'],
        'is_active' => true,
    ]);

    $plan = Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'gate-pro',
        'name' => 'Gate Pro',
        'interval' => 'month',
        'monthly_price_cents' => 9900,
        'price_cents' => 9900,
        'currency' => 'EUR',
        'vat_included' => true,
        'included_credits' => 300,
        'included_credits_per_interval' => 300,
        'seat_limit' => 3,
        'limits' => ['sites' => 3, 'users' => 3],
        'is_active' => true,
    ]);

    PlanFeature::create([
        'id' => (string) Str::uuid(),
        'plan_id' => $plan->id,
        'feature_key' => 'link_intelligence',
        'value_type' => 'bool',
        'value_bool' => true,
    ]);

    PlanFeature::create([
        'id' => (string) Str::uuid(),
        'plan_id' => $plan->id,
        'feature_key' => 'users_limit',
        'value_type' => 'int',
        'value_int' => 3,
    ]);

    $subscription = Subscription::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => 9900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 300,
        'seat_limit' => 3,
        'status' => 'active',
        'current_period_start' => now()->startOfDay(),
        'current_period_end' => now()->addMonth()->startOfDay(),
    ]);

    app(EntitlementRefreshService::class)->refreshForSubscription($subscription);

    $gate = app(FeatureGate::class);

    expect($gate->can($workspace, 'link_intelligence'))->toBeTrue();
    expect($gate->value($workspace, 'users_limit'))->toBe(3);

    WorkspaceEntitlement::query()->updateOrCreate(
        ['workspace_id' => $workspace->id, 'feature_key' => 'link_intelligence'],
        [
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'value_type' => 'bool',
            'value_bool' => false,
            'source' => 'manual',
            'effective_at' => now()->subMinute(),
            'expires_at' => now()->addDay(),
            'refreshed_at' => now(),
        ]
    );

    expect($gate->can($workspace, 'link_intelligence'))->toBeFalse();
});

it('throws authorization exception when asserting unavailable feature', function () {
    $organization = Organization::create([
        'name' => 'Gate Blocked Org',
        'slug' => 'gate-blocked-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Blocked WS',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Blocked Site',
        'site_url' => 'https://blocked.example.com',
        'allowed_domains' => ['blocked.example.com'],
        'is_active' => true,
    ]);

    $plan = Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'gate-starter',
        'name' => 'Gate Starter',
        'interval' => 'month',
        'monthly_price_cents' => 4900,
        'price_cents' => 4900,
        'currency' => 'EUR',
        'vat_included' => true,
        'included_credits' => 100,
        'included_credits_per_interval' => 100,
        'seat_limit' => 1,
        'limits' => ['sites' => 1, 'users' => 1],
        'is_active' => true,
    ]);

    PlanFeature::create([
        'id' => (string) Str::uuid(),
        'plan_id' => $plan->id,
        'feature_key' => 'content_intelligence',
        'value_type' => 'bool',
        'value_bool' => false,
    ]);

    Subscription::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'seat_limit' => 1,
        'status' => 'active',
        'current_period_start' => now()->startOfDay(),
        'current_period_end' => now()->addMonth()->startOfDay(),
    ]);

    $gate = app(FeatureGate::class);

    expect(fn () => $gate->assert($workspace, 'content_intelligence'))
        ->toThrow(AuthorizationException::class);
});
