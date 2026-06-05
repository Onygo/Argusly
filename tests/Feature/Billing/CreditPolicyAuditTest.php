<?php

use App\Models\ClientSite;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPack;
use App\Models\CreditPackPurchase;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCreditTransaction;
use App\Services\Billing\CreditExpirationService;
use App\Services\CreditPackPurchaseService;
use App\Services\CreditReservationService;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('applies three-cycle expiry to subscription allowance grants', function () {
    Carbon::setTestNow('2026-05-12 10:15:00');

    $setup = makeCreditPolicySetup([
        'current_period_start' => Carbon::now()->subMonth()->startOfDay(),
        'current_period_end' => Carbon::now()->subDay()->startOfDay(),
    ]);

    $this->artisan('subscriptions:apply-allowances', [
        '--force' => 1,
        '--limit' => 50,
    ])->assertExitCode(0);

    $entry = CreditLedgerEntry::query()
        ->where('source_type', Subscription::class)
        ->where('source_id', (string) $setup['subscription']->id)
        ->where('type', CreditWalletService::TYPE_ALLOWANCE)
        ->latest('created_at')
        ->first();

    expect($entry)->not->toBeNull();
    expect($entry->expires_at?->toDateTimeString())->toBe('2026-08-12 00:00:00');

    Carbon::setTestNow();
});

it('expires remaining included-plan buckets after their rollover window ends', function () {
    $setup = makeCreditPolicySetup();

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $setup['site']->id,
        amount: 40,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: [
            'period_start' => now()->subMonths(4)->startOfMonth()->toIso8601String(),
            'period_end' => now()->subMonths(3)->startOfMonth()->toIso8601String(),
            'subscription_id' => (string) $setup['subscription']->id,
        ],
        sourceType: Subscription::class,
        sourceId: (string) $setup['subscription']->id,
        expiresAt: now()->subMinute(),
        idempotencyKey: 'expired-allowance-' . Str::lower(Str::random(6)),
    );

    $before = app(CreditWalletService::class)->getSummary((string) $setup['site']->id);
    expect((int) $before['included_remaining'])->toBe(40);

    $summary = app(CreditExpirationService::class)->expireCredits();

    $after = app(CreditWalletService::class)->getSummary((string) $setup['site']->id);
    expect((int) $summary['expired_credits'])->toBe(40);
    expect((int) $after['included_remaining'])->toBe(0);
    expect((int) $after['available'])->toBe(0);
});

it('repairs missing expiration timestamps for subscription and purchased credits', function () {
    $setup = makeCreditPolicySetup();
    $wallets = app(CreditWalletService::class);

    $allowanceEntry = $wallets->addCredits(
        clientSiteId: (string) $setup['site']->id,
        amount: 25,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: [
            'period_start' => $setup['subscription']->current_period_start?->toIso8601String(),
            'period_end' => $setup['subscription']->current_period_end?->toIso8601String(),
            'subscription_id' => (string) $setup['subscription']->id,
        ],
        sourceType: Subscription::class,
        sourceId: (string) $setup['subscription']->id,
        expiresAt: null,
        idempotencyKey: 'repair-allowance-' . Str::lower(Str::random(6)),
    );

    $allowanceWorkspaceTx = WorkspaceCreditTransaction::query()
        ->where('reference_type', Subscription::class)
        ->where('reference_id', (string) $setup['subscription']->id)
        ->latest('created_at')
        ->firstOrFail();

    $pack = CreditPack::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'repair-pack-' . Str::lower(Str::random(4)),
        'name' => 'Repair Pack',
        'credits_amount' => 80,
        'price_cents' => 2400,
        'currency' => 'EUR',
        'expires_in_months' => 12,
        'never_expires' => false,
        'is_active' => true,
    ]);

    $purchase = CreditPackPurchase::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $setup['site']->id,
        'credit_pack_id' => $pack->id,
        'status' => 'pending',
        'credits_amount' => 80,
        'price_cents' => 2400,
        'currency' => 'EUR',
        'meta' => ['pack_key' => $pack->key],
    ]);

    $purchase = app(CreditPackPurchaseService::class)->markPaid(
        $purchase,
        $wallets,
        'tr_repair_pack_' . Str::lower(Str::random(6))
    );

    $purchase->refresh();
    $packWorkspaceTx = WorkspaceCreditTransaction::query()->findOrFail($purchase->workspace_credit_transaction_id);

    $allowanceEntry->update(['expires_at' => null]);
    $allowanceWorkspaceTx->update(['expires_at' => null]);
    $packWorkspaceTx->update(['expires_at' => null]);
    $purchase->update(['purchased_credit_expires_at' => null]);

    $this->artisan('billing:repair-expiration', ['--limit' => 100])->assertExitCode(0);

    expect($allowanceWorkspaceTx->fresh()->expires_at)->not->toBeNull();
    expect($allowanceEntry->fresh()->expires_at)->not->toBeNull();
    expect($purchase->fresh()->purchased_credit_expires_at)->not->toBeNull();
    expect($packWorkspaceTx->fresh()->expires_at)->not->toBeNull();
});

it('uses a shared workspace credit pool across multiple users', function () {
    $setup = makeCreditPolicySetup();
    $wallets = app(CreditWalletService::class);
    $reservations = app(CreditReservationService::class);

    $userA = User::query()->create([
        'name' => 'Alice Team',
        'email' => 'alice+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => 'password',
        'organization_id' => $setup['organization']->id,
        'role' => 'member',
        'active' => true,
        'approved_at' => now(),
    ]);

    $userB = User::query()->create([
        'name' => 'Bob Team',
        'email' => 'bob+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => 'password',
        'organization_id' => $setup['organization']->id,
        'role' => 'member',
        'active' => true,
        'approved_at' => now(),
    ]);

    $wallets->addCredits(
        clientSiteId: (string) $setup['site']->id,
        amount: 20,
        type: CreditWalletService::TYPE_ALLOWANCE,
        sourceType: Subscription::class,
        sourceId: (string) $setup['subscription']->id,
        expiresAt: now()->addMonths(3)->startOfDay(),
        idempotencyKey: 'shared-pool-' . Str::lower(Str::random(6)),
    );

    $reservations->reserve(
        clientSiteId: (string) $setup['site']->id,
        amount: 12,
        idempotencyKey: 'shared-user-a',
        purpose: 'draft_generate',
        options: ['userId' => (string) $userA->id]
    );

    $summaryAfterA = $wallets->getSummary((string) $setup['site']->id);
    expect((int) $summaryAfterA['reserved_cached'])->toBe(12);
    expect((int) $summaryAfterA['available'])->toBe(8);

    $reservations->reserve(
        clientSiteId: (string) $setup['site']->id,
        amount: 8,
        idempotencyKey: 'shared-user-b',
        purpose: 'draft_generate',
        options: ['userId' => (string) $userB->id]
    );

    $summaryAfterB = $wallets->getSummary((string) $setup['site']->id);
    expect((int) $summaryAfterB['reserved_cached'])->toBe(20);
    expect((int) $summaryAfterB['available'])->toBe(0);
});

/**
 * @param  array<string,mixed>  $overrides
 * @return array{organization:Organization,workspace:Workspace,site:ClientSite,plan:Plan,subscription:Subscription}
 */
function makeCreditPolicySetup(array $overrides = []): array
{
    $organization = Organization::query()->create([
        'name' => 'Billing Audit Org',
        'slug' => 'billing-audit-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Billing Audit Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Billing Audit Site',
        'site_url' => 'https://billing-audit.example.com',
        'allowed_domains' => ['billing-audit.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'audit-plan-' . Str::lower(Str::random(5)),
        'slug' => 'audit-plan-' . Str::lower(Str::random(5)),
        'name' => 'Audit Plan',
        'interval' => 'month',
        'monthly_price_cents' => 9900,
        'price_cents' => 9900,
        'currency' => 'EUR',
        'included_credits' => 100,
        'included_credits_per_interval' => 100,
        'credit_rollover_policy' => 'limited',
        'credit_expiry_days' => 90,
        'credit_rollover_monthly_cycles' => 3,
        'seat_limit' => 5,
        'is_active' => true,
    ]);

    $subscription = Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => 9900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'seat_limit' => 5,
        'status' => 'active',
        'provider' => 'mollie',
        'current_period_start' => $overrides['current_period_start'] ?? now()->startOfMonth(),
        'current_period_end' => $overrides['current_period_end'] ?? now()->addMonth()->startOfMonth(),
        'next_payment_at' => $overrides['next_payment_at'] ?? now()->addMonth()->startOfMonth(),
    ]);

    $organization->active_subscription_id = $subscription->id;
    $organization->save();

    return compact('organization', 'workspace', 'site', 'plan', 'subscription');
}
