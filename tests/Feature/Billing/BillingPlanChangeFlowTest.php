<?php

use App\Billing\Providers\PaymentProvider;
use App\Billing\Providers\PaymentProviderRegistry;
use App\Enums\Billing\SubscriptionPlanChangeStatus;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPlanChange;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('schedules plan changes for next period through a single timing input', function () {
    [$organization, $owner, $workspace, $site] = createBillingPlanContext();

    $starter = createPlan('starter-scheduled-' . Str::random(6), 4900, 'Starter');
    $growth = createPlan('growth-scheduled-' . Str::random(6), 12900, 'Growth');

    $subscription = createActiveSubscription($organization, $workspace, $site, $starter, 4900);

    $this->actingAs($owner)
        ->from(route('app.billing.index'))
        ->post(route('app.billing.subscription.change-plan'), [
            'to_plan_id' => (string) $growth->id,
            'timing' => 'next_period',
        ])
        ->assertRedirect(route('app.billing.index'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Plan change scheduled for next billing period.');

    $subscription->refresh();

    expect((string) $subscription->pending_plan_id)->toBe((string) $growth->id)
        ->and(SubscriptionPlanChange::query()
            ->where('subscription_id', $subscription->id)
            ->where('to_plan_id', $growth->id)
            ->where('strategy', 'next_period')
            ->where('status', SubscriptionPlanChangeStatus::PENDING->value)
            ->exists())->toBeTrue();
});

it('replaces an existing scheduled plan change with the latest one', function () {
    [$organization, $owner, $workspace, $site] = createBillingPlanContext();

    $starter = createPlan('starter-replace-' . Str::random(6), 4900, 'Starter');
    $growth = createPlan('growth-replace-' . Str::random(6), 12900, 'Growth');
    $scale = createPlan('scale-replace-' . Str::random(6), 18900, 'Scale');

    $subscription = createActiveSubscription($organization, $workspace, $site, $starter, 4900);

    $this->actingAs($owner)
        ->from(route('app.billing.index'))
        ->post(route('app.billing.subscription.change-plan'), [
            'to_plan_id' => (string) $growth->id,
            'timing' => 'next_period',
        ])
        ->assertRedirect(route('app.billing.index'));

    $firstChange = SubscriptionPlanChange::query()
        ->where('subscription_id', $subscription->id)
        ->where('to_plan_id', $growth->id)
        ->latest('created_at')
        ->firstOrFail();

    $this->actingAs($owner)
        ->from(route('app.billing.index'))
        ->post(route('app.billing.subscription.change-plan'), [
            'to_plan_id' => (string) $scale->id,
            'timing' => 'next_period',
        ])
        ->assertRedirect(route('app.billing.index'))
        ->assertSessionHasNoErrors();

    $subscription->refresh();
    $firstChange->refresh();

    expect((string) $subscription->pending_plan_id)->toBe((string) $scale->id)
        ->and($firstChange->status)->toBe(SubscriptionPlanChangeStatus::BLOCKED)
        ->and((string) $firstChange->blocked_reason)->toBe('replaced_by_new_request')
        ->and(SubscriptionPlanChange::query()
            ->where('subscription_id', $subscription->id)
            ->where('strategy', 'next_period')
            ->where('status', SubscriptionPlanChangeStatus::PENDING->value)
            ->count())->toBe(1);
});

it('applies immediate prorated changes immediately when no proration charge is required', function () {
    [$organization, $owner, $workspace, $site] = createBillingPlanContext();

    $starter = createPlan('starter-immediate-' . Str::random(6), 4900, 'Starter');
    $growth = createPlan('growth-immediate-' . Str::random(6), 5900, 'Growth');

    $subscription = createActiveSubscription(
        $organization,
        $workspace,
        $site,
        $starter,
        4900,
        now()->subDays(30),
        now()->addMinute(),
    );

    $this->actingAs($owner)
        ->from(route('app.billing.index'))
        ->post(route('app.billing.subscription.change-plan'), [
            'to_plan_id' => (string) $growth->id,
            'timing' => 'immediate_prorated',
        ])
        ->assertRedirect(route('app.billing.index'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Plan changed successfully.');

    $subscription->refresh();

    expect((string) $subscription->plan_id)->toBe((string) $growth->id)
        ->and($subscription->pending_plan_id)->toBeNull()
        ->and(SubscriptionPlanChange::query()
            ->where('subscription_id', $subscription->id)
            ->where('to_plan_id', $growth->id)
            ->where('strategy', 'immediate_proration')
            ->where('status', SubscriptionPlanChangeStatus::APPLIED->value)
            ->exists())->toBeTrue();
});

it('does not show success when immediate provider creation fails', function () {
    [$organization, $owner, $workspace, $site] = createBillingPlanContext();

    $starter = createPlan('starter-fail-' . Str::random(6), 4900, 'Starter');
    $growth = createPlan('growth-fail-' . Str::random(6), 14900, 'Growth');

    $subscription = createActiveSubscription($organization, $workspace, $site, $starter, 4900, now()->subDays(2), now()->addDays(20));

    $failingProvider = new class implements PaymentProvider
    {
        public function name(): string { return 'mollie'; }
        public function createPackPaymentIntent(\App\Models\CreditPackPurchase $purchase, PaymentIntent $intent): array { return []; }
        public function createSubscriptionPaymentIntent(Subscription $subscription, PaymentIntent $intent): array
        {
            throw new \RuntimeException('Payment provider unavailable.');
        }
        public function fetchActiveMandateId(string $customerId): ?string { return 'mdt_fail'; }
        public function createRecurringSubscription(Subscription $subscription): array { return ['provider_subscription_id' => 'sub_fail', 'status' => 'active']; }
        public function fetchPayment(string $providerPaymentId): array { return []; }
        public function parseWebhook(string $rawBody): array { return []; }
    };

    app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([$failingProvider]));

    $this->actingAs($owner)
        ->from(route('app.billing.index'))
        ->post(route('app.billing.subscription.change-plan'), [
            'to_plan_id' => (string) $growth->id,
            'timing' => 'immediate_prorated',
        ])
        ->assertRedirect(route('app.billing.index'))
        ->assertSessionHasErrors(['billing'])
        ->assertSessionMissing('status');

    $subscription->refresh();

    expect((string) $subscription->plan_id)->toBe((string) $starter->id)
        ->and(SubscriptionPlanChange::query()->where('subscription_id', $subscription->id)->count())->toBe(0);
});

it('renders current and scheduled plan details on billing page', function () {
    [$organization, $owner, $workspace, $site] = createBillingPlanContext();

    $starter = createPlan('starter-ui-' . Str::random(6), 4900, 'Starter');
    $growth = createPlan('growth-ui-' . Str::random(6), 12900, 'Growth');

    $effectiveAt = now()->addDays(12)->startOfDay();

    $subscription = createActiveSubscription($organization, $workspace, $site, $starter, 4900);
    $subscription->pending_plan_id = $growth->id;
    $subscription->save();

    SubscriptionPlanChange::create([
        'id' => (string) Str::uuid(),
        'subscription_id' => $subscription->id,
        'organization_id' => $organization->id,
        'from_plan_id' => $starter->id,
        'to_plan_id' => $growth->id,
        'strategy' => 'next_period',
        'status' => SubscriptionPlanChangeStatus::PENDING,
        'currency' => 'EUR',
        'effective_at' => $effectiveAt,
        'meta' => ['timing' => 'next_period', 'is_upgrade' => true],
    ]);

    $this->actingAs($owner)
        ->get(route('app.billing.index'))
        ->assertOk()
        ->assertSee('Current plan:')
        ->assertSee('Starter')
        ->assertSee('Scheduled next plan:')
        ->assertSee('Growth')
        ->assertSee($effectiveAt->format('Y-m-d'));
});

function createBillingPlanContext(): array
{
    $organization = Organization::create([
        'name' => 'Plan Change Org',
        'slug' => 'plan-change-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Plan Change Org BV',
        'billing_address_line1' => 'Damrak 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::create([
        'name' => 'Plan Change Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Plan Change Site',
        'site_url' => 'https://plan-change.example.com',
        'allowed_domains' => ['plan-change.example.com'],
        'is_active' => true,
    ]);

    $owner = User::create([
        'name' => 'Plan Change Owner',
        'email' => 'plan-change-owner+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    return [$organization, $owner, $workspace, $site];
}

function createPlan(string $key, int $priceCents, string $name): Plan
{
    return Plan::create([
        'id' => (string) Str::uuid(),
        'key' => $key,
        'name' => $name,
        'interval' => 'month',
        'monthly_price_cents' => $priceCents,
        'price_cents' => $priceCents,
        'currency' => 'EUR',
        'included_credits' => 100,
        'included_credits_per_interval' => 100,
        'seat_limit' => 5,
        'is_active' => true,
    ]);
}

function createActiveSubscription(
    Organization $organization,
    Workspace $workspace,
    ClientSite $site,
    Plan $plan,
    int $priceCents,
    ?\Illuminate\Support\Carbon $periodStart = null,
    ?\Illuminate\Support\Carbon $periodEnd = null,
): Subscription {
    $subscription = Subscription::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => $priceCents,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'seat_limit' => 5,
        'status' => 'active',
        'current_period_start' => $periodStart ?: now()->subDay(),
        'current_period_end' => $periodEnd ?: now()->addDays(29),
    ]);

    $organization->active_subscription_id = $subscription->id;
    $organization->save();

    return $subscription;
}
