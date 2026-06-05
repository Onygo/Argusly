<?php

use App\Jobs\BillingBackfillMonthlyCreditsJob;
use App\Models\ClientSite;
use App\Models\CreditLedgerEntry;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use App\Services\SubscriptionMonthlyCreditRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('grants recovery credits once per period and does not double credit on rerun', function () {
    $setup = makeSubscriptionRecoverySetup([
        'plan_credits' => 140,
        'subscription_credits' => 10,
    ]);

    $period = $setup['subscription']->current_period_start?->format('Y-m') ?? now()->format('Y-m');

    $this->artisan('billing:grant-monthly-credits', [
        'org_id' => (string) $setup['organization']->id,
        '--period' => $period,
        '--force' => true,
        '--admin-user-id' => (string) $setup['admin']->id,
    ])->assertExitCode(0);

    $summaryAfterFirst = app(CreditWalletService::class)->getSummary((string) $setup['site']->id);
    expect((int) $summaryAfterFirst['available'])->toBe(140);

    $firstEntries = CreditLedgerEntry::query()
        ->where('source_type', Subscription::class)
        ->where('source_id', (string) $setup['subscription']->id)
        ->where('type', CreditWalletService::TYPE_ALLOWANCE)
        ->get()
        ->filter(fn (CreditLedgerEntry $entry): bool => (string) data_get($entry->meta, 'ledger_type') === 'subscription_recovery_grant');

    expect($firstEntries->count())->toBe(1);

    $this->artisan('billing:grant-monthly-credits', [
        'org_id' => (string) $setup['organization']->id,
        '--period' => $period,
        '--force' => true,
        '--admin-user-id' => (string) $setup['admin']->id,
    ])->assertExitCode(0);

    $summaryAfterSecond = app(CreditWalletService::class)->getSummary((string) $setup['site']->id);
    expect((int) $summaryAfterSecond['available'])->toBe(140);

    $secondEntries = CreditLedgerEntry::query()
        ->where('source_type', Subscription::class)
        ->where('source_id', (string) $setup['subscription']->id)
        ->where('type', CreditWalletService::TYPE_ALLOWANCE)
        ->get()
        ->filter(fn (CreditLedgerEntry $entry): bool => (string) data_get($entry->meta, 'ledger_type') === 'subscription_recovery_grant');

    expect($secondEntries->count())->toBe(1);
});

it('uses active plan monthly credits as recovery amount', function () {
    $setup = makeSubscriptionRecoverySetup([
        'plan_credits' => 275,
        'subscription_credits' => 5,
    ]);

    $this->artisan('billing:grant-monthly-credits', [
        'org_id' => (string) $setup['organization']->id,
        '--period' => $setup['subscription']->current_period_start?->format('Y-m') ?? now()->format('Y-m'),
        '--force' => true,
        '--admin-user-id' => (string) $setup['admin']->id,
    ])->assertExitCode(0);

    $entry = CreditLedgerEntry::query()
        ->where('source_type', Subscription::class)
        ->where('source_id', (string) $setup['subscription']->id)
        ->where('type', CreditWalletService::TYPE_ALLOWANCE)
        ->latest('created_at')
        ->first();

    expect($entry)->not->toBeNull();
    expect((int) $entry->amount)->toBe(275);
    expect((string) data_get($entry->meta, 'plan_id'))->toBe((string) $setup['plan']->id);
});

it('dry run recovery from admin ui makes no database changes', function () {
    $setup = makeSubscriptionRecoverySetup();

    $this->actingAs($setup['admin'])
        ->post(route('admin.organizations.billing.subscription.grant-monthly-credits', $setup['organization']), [
            'period' => $setup['subscription']->current_period_start?->format('Y-m') ?? now()->format('Y-m'),
            'dry_run' => 1,
        ])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(CreditLedgerEntry::query()
        ->where('source_type', Subscription::class)
        ->where('source_id', (string) $setup['subscription']->id)
        ->where('type', CreditWalletService::TYPE_ALLOWANCE)
        ->count())->toBe(0);

    $summary = app(CreditWalletService::class)->getSummary((string) $setup['site']->id);
    expect((int) $summary['available'])->toBe(0);
});

it('forbids non admin users from triggering monthly credit recovery ui action', function () {
    $setup = makeSubscriptionRecoverySetup();

    $this->actingAs($setup['client_user'])
        ->post(route('admin.organizations.billing.subscription.grant-monthly-credits', $setup['organization']), [
            'confirm_recovery' => 1,
            'period' => $setup['subscription']->current_period_start?->format('Y-m') ?? now()->format('Y-m'),
        ])
        ->assertStatus(403);
});

it('backfill job grants missing monthly credits for paid subscriptions and remains idempotent', function () {
    $setup = makeSubscriptionRecoverySetup([
        'plan_credits' => 180,
        'subscription_credits' => 20,
    ]);

    PaymentIntent::query()->create([
        'id' => (string) Str::uuid(),
        'billable_type' => Subscription::class,
        'billable_id' => $setup['subscription']->id,
        'provider' => 'mollie',
        'status' => 'paid',
        'amount_cents' => (int) $setup['subscription']->price_cents,
        'currency' => (string) $setup['subscription']->currency,
        'provider_payment_id' => (string) $setup['subscription']->provider_payment_id,
        'paid_at' => now(),
        'meta' => ['purpose' => 'subscription_renewal'],
    ]);

    (new BillingBackfillMonthlyCreditsJob(limit: 50))
        ->handle(app(SubscriptionMonthlyCreditRecoveryService::class));

    $summaryAfterFirst = app(CreditWalletService::class)->getSummary((string) $setup['site']->id);
    expect((int) $summaryAfterFirst['available'])->toBe(180);

    (new BillingBackfillMonthlyCreditsJob(limit: 50))
        ->handle(app(SubscriptionMonthlyCreditRecoveryService::class));

    $summaryAfterSecond = app(CreditWalletService::class)->getSummary((string) $setup['site']->id);
    expect((int) $summaryAfterSecond['available'])->toBe(180);

    $entries = CreditLedgerEntry::query()
        ->where('source_type', Subscription::class)
        ->where('source_id', (string) $setup['subscription']->id)
        ->where('type', CreditWalletService::TYPE_ALLOWANCE)
        ->get()
        ->filter(fn (CreditLedgerEntry $entry): bool => (string) data_get($entry->meta, 'ledger_type') === 'subscription_recovery_grant');

    expect($entries->count())->toBe(1);
});

/**
 * @param  array<string,mixed>  $overrides
 * @return array{
 *   organization: Organization,
 *   workspace: Workspace,
 *   site: ClientSite,
 *   plan: Plan,
 *   subscription: Subscription,
 *   admin: User,
 *   client_user: User
 * }
 */
function makeSubscriptionRecoverySetup(array $overrides = []): array
{
    $organization = Organization::query()->create([
        'name' => 'Recovery Org',
        'slug' => 'recovery-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Recovery Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Recovery Site',
        'site_url' => 'https://recovery.example.com',
        'allowed_domains' => ['recovery.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $planCredits = (int) ($overrides['plan_credits'] ?? 120);
    $subscriptionCredits = (int) ($overrides['subscription_credits'] ?? 12);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'recovery-plan-' . Str::lower(Str::random(5)),
        'name' => 'Recovery Plan',
        'interval' => 'month',
        'monthly_price_cents' => 7900,
        'price_cents' => 7900,
        'currency' => 'EUR',
        'included_credits' => $planCredits,
        'included_credits_per_interval' => $planCredits,
        'seat_limit' => 3,
        'is_active' => true,
    ]);

    $subscription = Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => 7900,
        'currency' => 'EUR',
        'included_credits_per_interval' => $subscriptionCredits,
        'seat_limit' => 3,
        'status' => 'active',
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->addMonth()->startOfMonth(),
        'next_payment_at' => now()->addMonth()->startOfMonth(),
        'provider' => 'mollie',
        'provider_payment_id' => 'tr_recovery_' . Str::lower(Str::random(8)),
    ]);

    $organization->active_subscription_id = $subscription->id;
    $organization->save();

    $admin = User::query()->create([
        'name' => 'Recovery Admin',
        'email' => 'recovery-admin+' . Str::lower(Str::random(5)) . '@example.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
        'approved_at' => now(),
        'active' => true,
    ]);

    $clientUser = User::query()->create([
        'name' => 'Recovery Client',
        'email' => 'recovery-client+' . Str::lower(Str::random(5)) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'is_admin' => false,
        'approved_at' => now(),
        'active' => true,
    ]);

    return [
        'organization' => $organization,
        'workspace' => $workspace,
        'site' => $site,
        'plan' => $plan,
        'subscription' => $subscription,
        'admin' => $admin,
        'client_user' => $clientUser,
    ];
}
