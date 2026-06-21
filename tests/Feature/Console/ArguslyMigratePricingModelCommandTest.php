<?php

use App\Models\ClientSite;
use App\Models\CreditLedgerEntry;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('migrates the active pilot subscription to argusly platform without duplicating subscriptions or credits', function () {
    [$organization, $subscription, $growthPlan] = makePricingMigrationPilot(siteCount: 2, credits: 1432);

    $this->artisan('argusly:migrate-pricing-model', ['--org-id' => $organization->id])
        ->expectsOutput('Organization: Onygo Pilot')
        ->expectsOutput('Previous Plan: Growth')
        ->expectsOutput('New Plan: Argusly Platform')
        ->expectsOutput('Included Sites: 1')
        ->expectsOutput('Existing Sites: 2')
        ->expectsOutput('Extra Site Entitlements Created: 1')
        ->expectsOutput('Credits Preserved: 1432')
        ->expectsOutput('Status: Success')
        ->assertExitCode(0);

    $subscription->refresh();
    $platform = Plan::query()->where('key', 'argusly_platform')->firstOrFail();

    expect($subscription->plan_id)->toBe($platform->id)
        ->and($subscription->included_credits_per_interval)->toBe(250)
        ->and($subscription->seat_limit)->toBe(5)
        ->and(Subscription::query()->where('organization_id', $organization->id)->count())->toBe(1)
        ->and($growthPlan->fresh()->is_active)->toBeFalse()
        ->and($growthPlan->fresh()->is_public)->toBeFalse();

    $entitlement = WorkspaceEntitlement::query()
        ->where('organization_id', $organization->id)
        ->where('feature_key', 'wp_sites_limit')
        ->firstOrFail();

    expect($entitlement->value_int)->toBe(2)
        ->and($entitlement->source)->toBe('migration')
        ->and((int) DB::table('site_credit_allocations')->sum('allocated_credits'))->toBe(1432)
        ->and(CreditLedgerEntry::query()->count())->toBe(1);

    $this->artisan('argusly:migrate-pricing-model', ['--org-id' => $organization->id])
        ->assertExitCode(0);

    expect(Subscription::query()->where('organization_id', $organization->id)->count())->toBe(1)
        ->and(WorkspaceEntitlement::query()->where('organization_id', $organization->id)->where('feature_key', 'wp_sites_limit')->count())->toBe(1)
        ->and((int) DB::table('site_credit_allocations')->sum('allocated_credits'))->toBe(1432)
        ->and(CreditLedgerEntry::query()->count())->toBe(1);
});

function makePricingMigrationPilot(int $siteCount, int $credits): array
{
    $organization = Organization::query()->create([
        'name' => 'Onygo Pilot',
        'slug' => 'onygo-pilot-' . Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
        'billing_company_name' => 'Onygo Pilot',
        'billing_address_line1' => 'Pilotstraat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Onygo Workspace',
        'organization_id' => $organization->id,
    ]);

    $growthPlan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'growth',
        'slug' => 'growth',
        'internal_code' => 'growth',
        'name' => 'Growth',
        'interval' => 'month',
        'price_cents' => 19900,
        'monthly_price_cents' => 19900,
        'currency' => 'EUR',
        'included_credits' => 1000,
        'included_credits_per_interval' => 1000,
        'seat_limit' => 10,
        'limits' => ['sites' => 3, 'users' => 10, 'workspaces' => 1],
        'is_active' => true,
        'is_public' => true,
    ]);

    $sites = collect(range(1, $siteCount))->map(function (int $index) use ($workspace): ClientSite {
        return ClientSite::query()->create([
            'workspace_id' => $workspace->id,
            'type' => 'wordpress',
            'name' => 'Pilot Site ' . $index,
            'site_url' => 'https://pilot-' . $index . '.example.com',
            'base_url' => 'https://pilot-' . $index . '.example.com',
            'allowed_domains' => ['pilot-' . $index . '.example.com'],
            'is_active' => true,
            'status' => 'connected',
        ]);
    });

    $firstSite = $sites->first();

    $allocationId = (string) Str::uuid();
    DB::table('site_credit_allocations')->insert([
        'id' => $allocationId,
        'workspace_id' => $workspace->id,
        'client_site_id' => $firstSite->id,
        'allocated_credits' => $credits,
        'reserved_cached' => 0,
        'used_cached' => 0,
        'metadata' => json_encode(['test' => true]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    CreditLedgerEntry::query()->create([
        'id' => (string) Str::uuid(),
        'credit_wallet_id' => $allocationId,
        'workspace_id' => $workspace->id,
        'client_site_id' => $firstSite->id,
        'organization_id' => $organization->id,
        'type' => 'pack_purchase',
        'source' => 'addon_pack',
        'amount' => $credits,
        'remaining' => $credits,
        'meta' => ['test' => true],
    ]);

    $subscription = Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $firstSite->id,
        'plan_id' => $growthPlan->id,
        'interval' => 'month',
        'price_cents' => 19900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 1000,
        'seat_limit' => 10,
        'status' => 'active',
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->addMonth()->startOfMonth(),
    ]);

    $organization->forceFill([
        'active_subscription_id' => $subscription->id,
    ])->save();

    return [$organization, $subscription, $growthPlan];
}
