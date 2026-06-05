<?php

use App\Billing\Providers\PaymentProvider;
use App\Billing\Providers\PaymentProviderRegistry;
use App\Enums\Billing\PlanChangeTiming;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DraftComparison\DraftComparisonModelCatalog;
use App\Services\Entitlements\EntitlementRefreshService;
use App\Services\PlanChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('updates compare validation and setup ui immediately after paid upgrade', function () {
    config()->set('billing.entitlements.cache_ttl_seconds', 3600);

    [$organization, $workspace, $site, $user, $brief, $subscription, $starter, $scale] = makeCompareUpgradeContext();

    app(EntitlementRefreshService::class)->refreshForSubscription(
        $subscription->fresh(['plan', 'workspace', 'organization.workspaces']) ?? $subscription
    );

    $modelOptions = app(DraftComparisonModelCatalog::class)->options();
    expect(count($modelOptions))->toBeGreaterThanOrEqual(2);

    $modelKeys = [
        (string) data_get($modelOptions, '0.key'),
        (string) data_get($modelOptions, '1.key'),
    ];

    $this->actingAs($user)
        ->postJson(route('app.briefs.compare.estimate', $brief), [
            'mode' => 'compare_two',
            'model_keys' => $modelKeys,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['model_keys']);

    $setupBefore = $this->actingAs($user)->get(route('app.content.workspace.compare.setup', $brief));
    $setupBefore->assertOk();

    app()->instance(PaymentProviderRegistry::class, new PaymentProviderRegistry([new class implements PaymentProvider
    {
        public function name(): string { return 'mollie'; }
        public function createPackPaymentIntent(\App\Models\CreditPackPurchase $purchase, PaymentIntent $intent): array { return []; }
        public function createSubscriptionPaymentIntent(Subscription $subscription, PaymentIntent $intent): array
        {
            return [
                'provider_payment_id' => 'tr_compare_upgrade_1',
                'checkout_url' => 'https://pay.example.test/compare-upgrade',
                'status' => 'open',
            ];
        }
        public function fetchActiveMandateId(string $customerId): ?string { return 'mdt_compare_1'; }
        public function createRecurringSubscription(Subscription $subscription): array { return ['provider_subscription_id' => 'sub_compare_1', 'status' => 'active']; }
        public function fetchPayment(string $providerPaymentId): array { return []; }
        public function parseWebhook(string $rawBody): array { return []; }
    }]));

    $result = app(PlanChangeService::class)->requestChange($subscription, $scale, PlanChangeTiming::IMMEDIATE_PRORATED);

    /** @var PaymentIntent $intent */
    $intent = $result['payment_intent'];
    $intent->status = 'paid';
    $intent->paid_at = now();
    $intent->save();

    app(PlanChangeService::class)->applyAfterPayment(
        $result['change']->fresh(['paymentIntent', 'subscription.organization', 'toPlan', 'fromPlan'])
    );

    $this->actingAs($user)
        ->postJson(route('app.briefs.compare.estimate', $brief), [
            'mode' => 'compare_two',
            'model_keys' => $modelKeys,
        ])
        ->assertOk()
        ->assertJsonPath('data.requested_model_count', 2);

    $setupAfter = $this->actingAs($user)->get(route('app.content.workspace.compare.setup', $brief));
    $setupAfter->assertOk();
    $setupAfter->assertSee('Hybrid draft available');
    $setupAfter->assertDontSee('Premium models locked');
});

function makeCompareUpgradeContext(string $prefix = 'compare-upgrade'): array
{
    $organization = Organization::query()->create([
        'name' => 'Compare Upgrade Org',
        'slug' => $prefix . '-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Compare Upgrade BV',
        'billing_address_line1' => 'Teststraat 1',
        'billing_postal_code' => '1011AA',
        'billing_city' => 'Amsterdam',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Compare Upgrade Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Compare Upgrade Site',
        'site_url' => 'https://compare-upgrade.example.com',
        'allowed_domains' => ['compare-upgrade.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::query()->create([
        'name' => 'Compare Upgrade Owner',
        'email' => $prefix . '+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Compare entitlement refresh brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'primary_keyword' => 'compare entitlement refresh',
    ]);

    $starter = makeCompareUpgradePlan('starter-' . $prefix . '-' . Str::random(5), 'Starter', 4900, 100);
    $scale = makeCompareUpgradePlan('scale-' . $prefix . '-' . Str::random(5), 'Scale', 15900, 600);

    makeCompareUpgradeFeatures($starter, 1, false);
    makeCompareUpgradeFeatures($scale, 4, true);

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
        'current_period_start' => now()->subDays(2),
        'current_period_end' => now()->addDays(28),
        'provider_customer_id' => 'cst_compare_' . Str::random(6),
    ]);

    $organization->active_subscription_id = $subscription->id;
    $organization->save();

    return [$organization, $workspace, $site, $user, $brief, $subscription, $starter, $scale];
}

function makeCompareUpgradePlan(string $key, string $name, int $priceCents, int $credits): Plan
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

function makeCompareUpgradeFeatures(Plan $plan, int $maxModels, bool $hybridEnabled): void
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
