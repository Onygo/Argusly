<?php

use App\Enums\Billing\SubscriptionPlanChangeStatus;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPlanChange;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('exposes the supported plan change statuses', function () {
    expect(SubscriptionPlanChangeStatus::values())->toBe([
        'pending',
        'pending_payment',
        'applied',
        'failed',
        'blocked',
    ]);
});

it('enforces transition rules', function () {
    expect(SubscriptionPlanChangeStatus::PENDING->canTransitionTo(SubscriptionPlanChangeStatus::PENDING_PAYMENT))->toBeTrue()
        ->and(SubscriptionPlanChangeStatus::PENDING->canTransitionTo(SubscriptionPlanChangeStatus::BLOCKED))->toBeTrue()
        ->and(SubscriptionPlanChangeStatus::PENDING_PAYMENT->canTransitionTo(SubscriptionPlanChangeStatus::APPLIED))->toBeTrue()
        ->and(SubscriptionPlanChangeStatus::PENDING_PAYMENT->canTransitionTo(SubscriptionPlanChangeStatus::FAILED))->toBeTrue()
        ->and(SubscriptionPlanChangeStatus::APPLIED->canTransitionTo(SubscriptionPlanChangeStatus::FAILED))->toBeFalse();
});

it('rejects unknown status values at model level', function () {
    [$organization, $workspace, $subscription, $starter, $scale] = makeStatusGuardContext();

    expect(fn () => SubscriptionPlanChange::query()->create([
        'id' => (string) Str::uuid(),
        'subscription_id' => $subscription->id,
        'organization_id' => $organization->id,
        'from_plan_id' => $starter->id,
        'to_plan_id' => $scale->id,
        'strategy' => 'immediate_proration',
        'status' => 'unknown_status',
        'currency' => 'EUR',
        'effective_at' => now(),
    ]))->toThrow(\ValueError::class);
});

it('rejects invalid status transitions', function () {
    [$organization, $workspace, $subscription, $starter, $scale] = makeStatusGuardContext('status-guard-transition');

    $change = SubscriptionPlanChange::query()->create([
        'id' => (string) Str::uuid(),
        'subscription_id' => $subscription->id,
        'organization_id' => $organization->id,
        'from_plan_id' => $starter->id,
        'to_plan_id' => $scale->id,
        'strategy' => 'immediate_proration',
        'status' => SubscriptionPlanChangeStatus::PENDING_PAYMENT,
        'currency' => 'EUR',
        'effective_at' => now(),
    ]);

    expect(fn () => $change->transitionTo(SubscriptionPlanChangeStatus::PENDING))
        ->toThrow(\DomainException::class);
});

function makeStatusGuardContext(string $prefix = 'status-guard'): array
{
    $organization = Organization::query()->create([
        'name' => 'Status Guard Org',
        'slug' => $prefix . '-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Status Guard BV',
        'billing_address_line1' => 'Teststraat 1',
        'billing_postal_code' => '1011AA',
        'billing_city' => 'Amsterdam',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Status Guard Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Status Guard Site',
        'site_url' => 'https://status-guard.example.com',
        'allowed_domains' => ['status-guard.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $starter = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'slug' => 'starter-' . $prefix,
        'key' => 'starter-' . $prefix,
        'name' => 'Starter',
        'interval' => 'month',
        'monthly_price_cents' => 4900,
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits' => 100,
        'included_credits_per_interval' => 100,
        'seat_limit' => 3,
        'is_active' => true,
    ]);

    $scale = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'slug' => 'scale-' . $prefix,
        'key' => 'scale-' . $prefix,
        'name' => 'Scale',
        'interval' => 'month',
        'monthly_price_cents' => 15900,
        'price_cents' => 15900,
        'currency' => 'EUR',
        'included_credits' => 600,
        'included_credits_per_interval' => 600,
        'seat_limit' => 10,
        'is_active' => true,
    ]);

    $subscription = Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $starter->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'seat_limit' => 3,
        'current_period_start' => now()->subDay(),
        'current_period_end' => now()->addDays(29),
    ]);

    return [$organization, $workspace, $subscription, $starter, $scale];
}
