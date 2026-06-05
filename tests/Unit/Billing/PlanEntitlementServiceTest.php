<?php

use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Services\Billing\PlanEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('ignores stale plan-sourced workspace entitlement rows from old plans', function () {
    $organization = Organization::query()->create([
        'name' => 'Entitlement Resolver Org',
        'slug' => 'entitlement-resolver-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Resolver Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Resolver Site',
        'site_url' => 'https://resolver.example.com',
        'allowed_domains' => ['resolver.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $starter = makeResolverPlan('resolver-starter-' . Str::random(6), 'Starter', 4900, 100);
    $scale = makeResolverPlan('resolver-scale-' . Str::random(6), 'Scale', 14900, 600);

    makeDraftComparePlanFeatures($starter, 1, false);
    makeDraftComparePlanFeatures($scale, 4, true);

    $subscription = Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $scale->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 14900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 600,
        'seat_limit' => 10,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $organization->active_subscription_id = $subscription->id;
    $organization->save();

    WorkspaceEntitlement::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'organization_id' => $organization->id,
        'subscription_id' => $subscription->id,
        'plan_id' => $starter->id,
        'feature_key' => 'draft_compare_max_models',
        'value_type' => 'int',
        'value_int' => 1,
        'source' => 'plan',
        'effective_at' => now()->subHour(),
        'expires_at' => now()->addDay(),
        'refreshed_at' => now()->subHour(),
    ]);

    WorkspaceEntitlement::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'organization_id' => $organization->id,
        'subscription_id' => $subscription->id,
        'plan_id' => $starter->id,
        'feature_key' => 'draft_compare_hybrid_enabled',
        'value_type' => 'bool',
        'value_bool' => false,
        'source' => 'plan',
        'effective_at' => now()->subHour(),
        'expires_at' => now()->addDay(),
        'refreshed_at' => now()->subHour(),
    ]);

    $entitlements = app(PlanEntitlementService::class)->getWorkspaceEntitlements($workspace);

    expect($entitlements['plan_key'])->toBe((string) $scale->key)
        ->and($entitlements['compare_max_models'])->toBe(4)
        ->and($entitlements['hybrid_drafts_enabled'])->toBeTrue();
});

function makeResolverPlan(string $key, string $name, int $priceCents, int $credits): Plan
{
    return Plan::query()->create([
        'id' => (string) Str::uuid(),
        'slug' => $key,
        'key' => $key,
        'name' => $name,
        'interval' => 'month',
        'monthly_price_cents' => $priceCents,
        'price_cents' => $priceCents,
        'currency' => 'EUR',
        'included_credits' => $credits,
        'included_credits_per_interval' => $credits,
        'seat_limit' => 10,
        'is_active' => true,
    ]);
}

function makeDraftComparePlanFeatures(Plan $plan, int $maxModels, bool $hybridEnabled): void
{
    PlanFeature::query()->create([
        'id' => (string) Str::uuid(),
        'plan_id' => $plan->id,
        'feature_key' => 'draft_compare_enabled',
        'value_type' => 'bool',
        'value_bool' => true,
    ]);

    PlanFeature::query()->create([
        'id' => (string) Str::uuid(),
        'plan_id' => $plan->id,
        'feature_key' => 'draft_compare_max_models',
        'value_type' => 'int',
        'value_int' => $maxModels,
    ]);

    PlanFeature::query()->create([
        'id' => (string) Str::uuid(),
        'plan_id' => $plan->id,
        'feature_key' => 'draft_compare_hybrid_enabled',
        'value_type' => 'bool',
        'value_bool' => $hybridEnabled,
    ]);

    PlanFeature::query()->create([
        'id' => (string) Str::uuid(),
        'plan_id' => $plan->id,
        'feature_key' => 'draft_compare_scoring_enabled',
        'value_type' => 'bool',
        'value_bool' => true,
    ]);

    PlanFeature::query()->create([
        'id' => (string) Str::uuid(),
        'plan_id' => $plan->id,
        'feature_key' => 'draft_compare_premium_models_enabled',
        'value_type' => 'bool',
        'value_bool' => true,
    ]);
}
