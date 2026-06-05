<?php

use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('scans workspaces and sends low-credit warnings from the scheduled command', function () {
    config()->set('credits.warnings.enabled', true);
    config()->set('credits.warnings.absolute_threshold', 10);

    [$workspace, $site] = makeLowCreditCommandContext();

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 7,
        type: CreditWalletService::TYPE_ADJUSTMENT,
        meta: ['source' => 'low-credit-command'],
    );

    Notification::fake();

    $this->artisan('credits:check-low-balance-warnings --limit=10')
        ->expectsOutputToContain('Processed 1 workspace(s);')
        ->assertExitCode(0);

    expect($workspace->fresh()->low_credit_warning_state)->not->toBeNull();
});

function makeLowCreditCommandContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Low Credit Command Org',
        'slug' => 'low-credit-command-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Low Credit Command BV',
        'billing_address_line1' => 'Commandstraat 9',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Low Credit Command Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Low Credit Command Site',
        'site_url' => 'https://low-credit-command.example.com',
        'base_url' => 'https://low-credit-command.example.com',
        'allowed_domains' => ['low-credit-command.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'low-credit-command-plan'],
        [
            'name' => 'Low Credit Command Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $owner = User::query()->create([
        'name' => 'Low Credit Command Owner',
        'email' => 'low-credit-command-owner+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    $organization->forceFill(['primary_user_id' => $owner->id])->save();

    return [$workspace, $site];
}
