<?php

use App\Jobs\CreditResetJob;
use App\Models\ClientSite;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPack;
use App\Models\CreditPackPurchase;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\CreditPackPurchaseService;
use App\Services\CreditWalletService;
use App\Services\SubscriptionLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('resets included credits on renewal boundary and keeps add-on credits non-expiring', function () {
    $organization = Organization::create([
        'name' => 'Credits Org',
        'slug' => 'credits-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Credits Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Credits Site',
        'site_url' => 'https://credits.example.com',
        'allowed_domains' => ['credits.example.com'],
        'is_active' => true,
    ]);

    $plan = Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'credits-plan',
        'name' => 'Credits Plan',
        'interval' => 'month',
        'monthly_price_cents' => 4900,
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits' => 25,
        'included_credits_per_interval' => 25,
        'seat_limit' => 3,
        'limits' => ['users' => 3],
        'is_active' => true,
    ]);

    $subscription = Subscription::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 25,
        'seat_limit' => 3,
        'status' => 'active',
        'current_period_start' => now()->subMonth()->startOfDay(),
        'current_period_end' => now()->subDay(),
    ]);

    $organization->active_subscription_id = $subscription->id;
    $organization->save();

    app(CreditResetJob::class)->handle(app(SubscriptionLifecycleService::class));

    $walletSummary = app(CreditWalletService::class)->getSummary((string) $site->id);

    expect((int) $walletSummary['included_remaining'])->toBe(0);
    expect($subscription->fresh()->status)->toBe('past_due');

    $pack = CreditPack::create([
        'id' => (string) Str::uuid(),
        'key' => 'exp-pack',
        'name' => 'Exp pack',
        'credits_amount' => 50,
        'price_cents' => 1500,
        'currency' => 'EUR',
        'expires_in_months' => null,
        'never_expires' => true,
        'is_active' => true,
    ]);

    $purchase = CreditPackPurchase::create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'credit_pack_id' => $pack->id,
        'status' => 'pending',
        'credits_amount' => 50,
        'price_cents' => 1500,
        'currency' => 'EUR',
        'meta' => ['pack_key' => 'exp-pack'],
    ]);

    $paidPurchase = app(CreditPackPurchaseService::class)->markPaid($purchase, app(CreditWalletService::class), 'tr_paid_001');

    $packEntry = CreditLedgerEntry::query()->find($paidPurchase->credit_ledger_entry_id);

    expect($packEntry)->not->toBeNull();
    expect($packEntry->expires_at)->toBeNull();

    $packEntry->remaining = 20;
    $packEntry->save();

    $beforeBalance = app(CreditWalletService::class)->getSummary((string) $site->id)['balance_cached'];

    $expiredCount = app(CreditWalletService::class)->expireAddonCredits();

    $afterEntry = CreditLedgerEntry::query()->find($packEntry->id);
    $afterBalance = app(CreditWalletService::class)->getSummary((string) $site->id)['balance_cached'];

    expect($expiredCount)->toBe(0);
    expect((int) $afterEntry->remaining)->toBe(20);
    expect((int) $afterBalance)->toBe((int) $beforeBalance);
});
