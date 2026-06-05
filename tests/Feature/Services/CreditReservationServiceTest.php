<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\CreditLedgerEntry;
use App\Models\CreditReservation;
use App\Models\CreditWallet;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditReservationService;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createTestSetup(): array
{
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

    return [
        'organization' => $organization,
        'workspace' => $workspace,
        'site' => $site,
    ];
}

it('reserves credits successfully', function () {
    $setup = createTestSetup();
    $credits = app(CreditWalletService::class);
    $reservations = app(CreditReservationService::class);

    $credits->addCredits(
        clientSiteId: (string) $setup['site']->id,
        amount: 50,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $reservation = $reservations->reserve(
        clientSiteId: (string) $setup['site']->id,
        amount: 10,
        idempotencyKey: 'test-reserve-1',
        purpose: 'draft_generate'
    );

    expect($reservation)->toBeInstanceOf(CreditReservation::class);
    expect($reservation->status)->toBe(CreditReservation::STATUS_RESERVED);
    expect($reservation->amount)->toBe(10);
    expect($reservation->purpose)->toBe('draft_generate');
    expect($reservation->expires_at)->not->toBeNull();

    $wallet = CreditWallet::query()->where('client_site_id', $setup['site']->id)->first();
    expect((int) $wallet->reserved_cached)->toBe(10);
});

it('reserve is idempotent with same idempotency key', function () {
    $setup = createTestSetup();
    $credits = app(CreditWalletService::class);
    $reservations = app(CreditReservationService::class);

    $credits->addCredits(
        clientSiteId: (string) $setup['site']->id,
        amount: 50,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $reservation1 = $reservations->reserve(
        clientSiteId: (string) $setup['site']->id,
        amount: 10,
        idempotencyKey: 'test-reserve-idempotent',
        purpose: 'draft_generate'
    );

    $reservation2 = $reservations->reserve(
        clientSiteId: (string) $setup['site']->id,
        amount: 10,
        idempotencyKey: 'test-reserve-idempotent',
        purpose: 'draft_generate'
    );

    expect($reservation1->id)->toBe($reservation2->id);
    expect(CreditReservation::query()->where('idempotency_key', 'test-reserve-idempotent')->count())->toBe(1);

    $wallet = CreditWallet::query()->where('client_site_id', $setup['site']->id)->first();
    expect((int) $wallet->reserved_cached)->toBe(10);
});

it('fails to reserve if insufficient credits', function () {
    $setup = createTestSetup();
    $credits = app(CreditWalletService::class);
    $reservations = app(CreditReservationService::class);

    $credits->addCredits(
        clientSiteId: (string) $setup['site']->id,
        amount: 5,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $reservations->reserve(
        clientSiteId: (string) $setup['site']->id,
        amount: 10,
        idempotencyKey: 'test-reserve-fail',
        purpose: 'draft_generate'
    );
})->throws(\App\Exceptions\InsufficientCreditsException::class);

it('captures reservation successfully', function () {
    $setup = createTestSetup();
    $credits = app(CreditWalletService::class);
    $reservations = app(CreditReservationService::class);

    $credits->addCredits(
        clientSiteId: (string) $setup['site']->id,
        amount: 50,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $reservation = $reservations->reserve(
        clientSiteId: (string) $setup['site']->id,
        amount: 10,
        idempotencyKey: 'test-capture-1',
        purpose: 'draft_generate'
    );

    $captured = $reservations->capture($reservation);

    expect($captured->status)->toBe(CreditReservation::STATUS_CAPTURED);
    expect($captured->captured_at)->not->toBeNull();
    expect($captured->capture_ledger_entry_id)->not->toBeNull();

    $wallet = CreditWallet::query()->where('client_site_id', $setup['site']->id)->first();
    expect((int) $wallet->reserved_cached)->toBe(0);
    expect((int) $wallet->balance_cached)->toBe(40);
});

it('capture is idempotent', function () {
    $setup = createTestSetup();
    $credits = app(CreditWalletService::class);
    $reservations = app(CreditReservationService::class);

    $credits->addCredits(
        clientSiteId: (string) $setup['site']->id,
        amount: 50,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $reservation = $reservations->reserve(
        clientSiteId: (string) $setup['site']->id,
        amount: 10,
        idempotencyKey: 'test-capture-idempotent',
        purpose: 'draft_generate'
    );

    $captured1 = $reservations->capture($reservation);
    $captured2 = $reservations->capture($captured1->fresh());

    expect($captured1->id)->toBe($captured2->id);
    expect($captured1->captured_at->timestamp)->toBe($captured2->captured_at->timestamp);

    $wallet = CreditWallet::query()->where('client_site_id', $setup['site']->id)->first();
    expect((int) $wallet->balance_cached)->toBe(40);
});

it('captures a reservation partially and releases unused reserved credits', function () {
    $setup = createTestSetup();
    $credits = app(CreditWalletService::class);
    $reservations = app(CreditReservationService::class);

    $credits->addCredits(
        clientSiteId: (string) $setup['site']->id,
        amount: 50,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $reservation = $reservations->reserve(
        clientSiteId: (string) $setup['site']->id,
        amount: 10,
        idempotencyKey: 'test-capture-partial',
        purpose: 'draft_generate'
    );

    $captured = $reservations->capture($reservation, [
        'captureAmount' => 4,
    ]);

    expect($captured->status)->toBe(CreditReservation::STATUS_CAPTURED);
    expect((int) data_get($captured->metadata, 'captured_amount'))->toBe(4);
    expect((int) data_get($captured->metadata, 'unused_amount_released'))->toBe(6);

    $wallet = CreditWallet::query()->where('client_site_id', $setup['site']->id)->first();
    expect((int) $wallet->reserved_cached)->toBe(0);
    expect((int) $wallet->balance_cached)->toBe(46);
});

it('releases reservation successfully', function () {
    $setup = createTestSetup();
    $credits = app(CreditWalletService::class);
    $reservations = app(CreditReservationService::class);

    $credits->addCredits(
        clientSiteId: (string) $setup['site']->id,
        amount: 50,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $reservation = $reservations->reserve(
        clientSiteId: (string) $setup['site']->id,
        amount: 10,
        idempotencyKey: 'test-release-1',
        purpose: 'draft_generate'
    );

    $released = $reservations->release($reservation, 'generation_failed');

    expect($released->status)->toBe(CreditReservation::STATUS_RELEASED);
    expect($released->released_at)->not->toBeNull();
    expect($released->reason)->toBe('generation_failed');

    $wallet = CreditWallet::query()->where('client_site_id', $setup['site']->id)->first();
    expect((int) $wallet->reserved_cached)->toBe(0);
    expect((int) $wallet->balance_cached)->toBe(50);
});

it('release is idempotent', function () {
    $setup = createTestSetup();
    $credits = app(CreditWalletService::class);
    $reservations = app(CreditReservationService::class);

    $credits->addCredits(
        clientSiteId: (string) $setup['site']->id,
        amount: 50,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $reservation = $reservations->reserve(
        clientSiteId: (string) $setup['site']->id,
        amount: 10,
        idempotencyKey: 'test-release-idempotent',
        purpose: 'draft_generate'
    );

    $released1 = $reservations->release($reservation, 'reason1');
    $released2 = $reservations->release($released1->fresh(), 'reason2');

    expect($released1->id)->toBe($released2->id);

    $wallet = CreditWallet::query()->where('client_site_id', $setup['site']->id)->first();
    expect((int) $wallet->balance_cached)->toBe(50);
});

it('cannot capture released reservation', function () {
    $setup = createTestSetup();
    $credits = app(CreditWalletService::class);
    $reservations = app(CreditReservationService::class);

    $credits->addCredits(
        clientSiteId: (string) $setup['site']->id,
        amount: 50,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $reservation = $reservations->reserve(
        clientSiteId: (string) $setup['site']->id,
        amount: 10,
        idempotencyKey: 'test-capture-released',
        purpose: 'draft_generate'
    );

    $released = $reservations->release($reservation, 'test');

    $reservations->capture($released);
})->throws(RuntimeException::class);

it('release on captured reservation is idempotent no-op', function () {
    $setup = createTestSetup();
    $credits = app(CreditWalletService::class);
    $reservations = app(CreditReservationService::class);

    $credits->addCredits(
        clientSiteId: (string) $setup['site']->id,
        amount: 50,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $reservation = $reservations->reserve(
        clientSiteId: (string) $setup['site']->id,
        amount: 10,
        idempotencyKey: 'test-release-captured',
        purpose: 'draft_generate'
    );

    $captured = $reservations->capture($reservation);

    // Releasing a captured reservation should NOT throw - it's a no-op
    $result = $reservations->release($captured, 'test');

    expect($result->id)->toBe($captured->id);
    expect($result->status)->toBe(CreditReservation::STATUS_CAPTURED);

    // Wallet balance should remain unchanged (credits were consumed)
    $wallet = CreditWallet::query()->where('client_site_id', $setup['site']->id)->first();
    expect((int) $wallet->balance_cached)->toBe(40);
});

it('handles stale model where memory shows reserved but DB is captured', function () {
    $setup = createTestSetup();
    $credits = app(CreditWalletService::class);
    $reservations = app(CreditReservationService::class);

    $credits->addCredits(
        clientSiteId: (string) $setup['site']->id,
        amount: 50,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $reservation = $reservations->reserve(
        clientSiteId: (string) $setup['site']->id,
        amount: 10,
        idempotencyKey: 'test-stale-model',
        purpose: 'draft_generate'
    );

    // Simulate stale model scenario:
    // 1. Keep reference to original reservation (status = reserved in memory)
    $staleReservation = $reservation;

    // 2. Capture it (DB status becomes captured)
    $reservations->capture($reservation);

    // 3. Verify stale model still thinks it's reserved
    expect($staleReservation->isReserved())->toBeTrue();

    // 4. Try to release the stale model - should NOT throw
    $result = $reservations->release($staleReservation, 'cleanup_after_failure');

    // 5. Result should be the captured reservation
    expect($result->status)->toBe(CreditReservation::STATUS_CAPTURED);
});

it('expires stale reservations', function () {
    $setup = createTestSetup();
    $credits = app(CreditWalletService::class);
    $reservations = app(CreditReservationService::class);

    $credits->addCredits(
        clientSiteId: (string) $setup['site']->id,
        amount: 50,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $reservation = $reservations->reserve(
        clientSiteId: (string) $setup['site']->id,
        amount: 10,
        idempotencyKey: 'test-expire-1',
        purpose: 'draft_generate',
        options: ['ttlMinutes' => 0]
    );

    $reservation->update(['expires_at' => now()->subMinute()]);

    $expiredCount = $reservations->expireStaleReservations();

    expect($expiredCount)->toBe(1);

    $reservation->refresh();
    expect($reservation->status)->toBe(CreditReservation::STATUS_EXPIRED);
    expect($reservation->released_at)->not->toBeNull();

    $wallet = CreditWallet::query()->where('client_site_id', $setup['site']->id)->first();
    expect((int) $wallet->reserved_cached)->toBe(0);
    expect((int) $wallet->balance_cached)->toBe(50);
});

it('admin can release reservation', function () {
    $setup = createTestSetup();
    $credits = app(CreditWalletService::class);
    $reservations = app(CreditReservationService::class);

    $adminUser = User::query()->create([
        'name' => 'Admin User',
        'email' => 'admin' . Str::random(6) . '@test.com',
        'password' => bcrypt('password'),
        'organization_id' => $setup['organization']->id,
    ]);

    $credits->addCredits(
        clientSiteId: (string) $setup['site']->id,
        amount: 50,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $reservation = $reservations->reserve(
        clientSiteId: (string) $setup['site']->id,
        amount: 10,
        idempotencyKey: 'test-admin-release',
        purpose: 'draft_generate'
    );

    $released = $reservations->adminRelease($reservation, $adminUser->id, 'Admin forced release');

    expect($released->status)->toBe(CreditReservation::STATUS_RELEASED);
    expect($released->reason)->toBe('Admin forced release');
});

it('prevents double spending with concurrent reservations', function () {
    $setup = createTestSetup();
    $credits = app(CreditWalletService::class);
    $reservations = app(CreditReservationService::class);

    $credits->addCredits(
        clientSiteId: (string) $setup['site']->id,
        amount: 15,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $reservation1 = $reservations->reserve(
        clientSiteId: (string) $setup['site']->id,
        amount: 10,
        idempotencyKey: 'test-concurrent-1',
        purpose: 'draft_generate'
    );

    expect(fn () => $reservations->reserve(
        clientSiteId: (string) $setup['site']->id,
        amount: 10,
        idempotencyKey: 'test-concurrent-2',
        purpose: 'draft_generate'
    ))->toThrow(\App\Exceptions\InsufficientCreditsException::class);

    $wallet = CreditWallet::query()->where('client_site_id', $setup['site']->id)->first();
    expect((int) $wallet->reserved_cached)->toBe(10);
    expect((int) $wallet->balance_cached)->toBe(15);
});

it('creates reservation record when wallet service reserves for draft', function () {
    $setup = createTestSetup();
    $credits = app(CreditWalletService::class);

    $credits->addCredits(
        clientSiteId: (string) $setup['site']->id,
        amount: 50,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $brief = Brief::query()->create([
        'client_site_id' => $setup['site']->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Test Brief',
        'language' => 'nl',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'brief_id' => $brief->id,
        'client_site_id' => $setup['site']->id,
        'status' => 'ready',
        'title' => 'Test Draft',
        'output_type' => 'kb_article',
    ]);

    $credits->reserveForDraft($draft);

    $reservation = CreditReservation::query()
        ->where('context_type', Draft::class)
        ->where('context_id', $draft->id)
        ->first();

    expect($reservation)->not->toBeNull();
    expect($reservation->status)->toBe(CreditReservation::STATUS_RESERVED);
    expect($reservation->purpose)->toBe('draft_generate');
});

it('marks reservation captured when wallet service commits for draft', function () {
    $setup = createTestSetup();
    $credits = app(CreditWalletService::class);

    $credits->addCredits(
        clientSiteId: (string) $setup['site']->id,
        amount: 50,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $brief = Brief::query()->create([
        'client_site_id' => $setup['site']->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Test Brief',
        'language' => 'nl',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'brief_id' => $brief->id,
        'client_site_id' => $setup['site']->id,
        'status' => 'ready',
        'title' => 'Test Draft',
        'output_type' => 'kb_article',
    ]);

    $credits->reserveForDraft($draft);
    $credits->commitUsageForDraft($draft->fresh());

    $reservation = CreditReservation::query()
        ->where('context_type', Draft::class)
        ->where('context_id', $draft->id)
        ->first();

    expect($reservation)->not->toBeNull();
    expect($reservation->status)->toBe(CreditReservation::STATUS_CAPTURED);
});

it('marks reservation released when wallet service releases for draft', function () {
    $setup = createTestSetup();
    $credits = app(CreditWalletService::class);

    $credits->addCredits(
        clientSiteId: (string) $setup['site']->id,
        amount: 50,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $brief = Brief::query()->create([
        'client_site_id' => $setup['site']->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Test Brief',
        'language' => 'nl',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'brief_id' => $brief->id,
        'client_site_id' => $setup['site']->id,
        'status' => 'ready',
        'title' => 'Test Draft',
        'output_type' => 'kb_article',
    ]);

    $credits->reserveForDraft($draft);
    $credits->releaseReservationForDraft($draft->fresh(), null, 'generation_failed');

    $reservation = CreditReservation::query()
        ->where('context_type', Draft::class)
        ->where('context_id', $draft->id)
        ->first();

    expect($reservation)->not->toBeNull();
    expect($reservation->status)->toBe(CreditReservation::STATUS_RELEASED);
    expect($reservation->reason)->toBe('generation_failed');
});
