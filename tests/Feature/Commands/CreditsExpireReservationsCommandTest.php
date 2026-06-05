<?php

use App\Models\ClientSite;
use App\Models\CreditReservation;
use App\Models\CreditWallet;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\CreditReservationService;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $organization = Organization::query()->create([
        'name' => 'Test Org',
        'slug' => 'test-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Test Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Test Site',
        'site_url' => 'https://test.example.com',
        'allowed_domains' => ['test.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'growth-' . Str::lower(Str::random(4)),
        'slug' => 'growth-' . Str::lower(Str::random(4)),
        'name' => 'Growth',
        'monthly_price_cents' => 7900,
        'price_monthly_cents' => 7900,
        'currency' => 'EUR',
        'included_credits' => 100,
        'is_active' => true,
    ]);

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => 7900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'seat_limit' => 5,
        'status' => 'active',
        'provider' => 'mollie',
        'current_period_start' => now()->subDay(),
        'current_period_end' => now()->addMonth(),
        'next_payment_at' => now()->addMonth(),
    ]);

    $this->site = $site;
});

it('expires stale reservations via command', function () {
    $credits = app(CreditWalletService::class);
    $reservations = app(CreditReservationService::class);

    $credits->addCredits(
        clientSiteId: (string) $this->site->id,
        amount: 50,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $reservation = $reservations->reserve(
        clientSiteId: (string) $this->site->id,
        amount: 10,
        idempotencyKey: 'test-command-expire',
        purpose: 'draft_generate'
    );

    $reservation->update(['expires_at' => now()->subMinute()]);

    $this->artisan('credits:expire-reservations')
        ->expectsOutput('Expired 1 stale credit reservations.')
        ->assertExitCode(0);

    $reservation->refresh();
    expect($reservation->status)->toBe(CreditReservation::STATUS_EXPIRED);
});

it('shows no stale reservations message when none exist', function () {
    $this->artisan('credits:expire-reservations')
        ->expectsOutput('No stale reservations to expire.')
        ->assertExitCode(0);
});

it('respects limit option', function () {
    $credits = app(CreditWalletService::class);
    $reservations = app(CreditReservationService::class);

    $credits->addCredits(
        clientSiteId: (string) $this->site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    for ($i = 1; $i <= 5; $i++) {
        $r = $reservations->reserve(
            clientSiteId: (string) $this->site->id,
            amount: 5,
            idempotencyKey: "test-limit-{$i}",
            purpose: 'draft_generate'
        );
        $r->update(['expires_at' => now()->subMinute()]);
    }

    $this->artisan('credits:expire-reservations --limit=2')
        ->expectsOutput('Expired 2 stale credit reservations.')
        ->assertExitCode(0);

    expect(CreditReservation::query()->where('status', CreditReservation::STATUS_EXPIRED)->count())->toBe(2);
    expect(CreditReservation::query()->where('status', CreditReservation::STATUS_RESERVED)->count())->toBe(3);
});

it('supports dry run mode', function () {
    $credits = app(CreditWalletService::class);
    $reservations = app(CreditReservationService::class);

    $credits->addCredits(
        clientSiteId: (string) $this->site->id,
        amount: 50,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $reservation = $reservations->reserve(
        clientSiteId: (string) $this->site->id,
        amount: 10,
        idempotencyKey: 'test-dry-run',
        purpose: 'draft_generate'
    );

    $reservation->update(['expires_at' => now()->subMinute()]);

    $this->artisan('credits:expire-reservations --dry-run')
        ->expectsOutputToContain('Would expire 1 reservations')
        ->assertExitCode(0);

    $reservation->refresh();
    expect($reservation->status)->toBe(CreditReservation::STATUS_RESERVED);
});
