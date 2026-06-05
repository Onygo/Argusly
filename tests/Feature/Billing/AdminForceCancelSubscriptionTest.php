<?php

use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('allows superadmin to force-cancel an organizations active subscription and pending intents', function () {
    $admin = User::create([
        'name' => 'Superadmin',
        'email' => 'superadmin+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
        'admin_role' => 'superadmin',
        'approved_at' => now(),
    ]);

    $organization = Organization::create([
        'name' => 'Cancelable Org',
        'slug' => 'cancelable-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Cancelable WS',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'cancel-plan',
        'slug' => 'growth',
        'name' => 'Cancel Plan',
        'interval' => 'month',
        'monthly_price_cents' => 9900,
        'price_cents' => 9900,
        'currency' => 'EUR',
        'included_credits' => 100,
        'included_credits_per_interval' => 100,
        'seat_limit' => 3,
        'is_active' => true,
    ]);

    $subscription = Subscription::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => null,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => 9900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'seat_limit' => 3,
        'status' => 'pending_mandate',
    ]);

    $organization->active_subscription_id = $subscription->id;
    $organization->save();

    PaymentIntent::create([
        'id' => (string) Str::uuid(),
        'billable_type' => Subscription::class,
        'billable_id' => $subscription->id,
        'provider' => 'mollie',
        'status' => 'open',
        'amount_cents' => 9900,
        'currency' => 'EUR',
        'provider_payment_id' => 'tr_force_cancel_001',
        'idempotency_key' => 'subscription_signup:' . $subscription->id . ':cancel',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.organizations.billing.subscription.force-cancel', $organization))
        ->assertRedirect()
        ->assertSessionHas('status', 'Subscription was force-canceled and pending checkout intents were canceled.');

    $subscription->refresh();
    $organization->refresh();

    expect($subscription->status)->toBe('canceled');
    expect((string) $subscription->status_reason)->toBe('admin_forced_cancel');
    expect($organization->active_subscription_id)->toBeNull();

    $intent = PaymentIntent::query()->where('billable_id', $subscription->id)->firstOrFail();
    expect($intent->status)->toBe('canceled');
});
