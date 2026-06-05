<?php

use App\Exceptions\InsufficientCreditsException;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditReservationService;
use App\Services\CreditWalletService;
use App\Services\Credits\SiteCreditAllocationService;
use App\Services\Credits\WorkspaceCreditLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createWorkspaceCreditSetup(int $siteCount = 1): array
{
    $organization = Organization::query()->create([
        'name' => 'Credits Org',
        'slug' => 'credits-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Credits Workspace',
        'organization_id' => $organization->id,
    ]);

    $sites = collect();
    for ($i = 1; $i <= $siteCount; $i++) {
        $sites->push(ClientSite::query()->create([
            'workspace_id' => $workspace->id,
            'type' => 'wordpress',
            'name' => 'Site ' . $i,
            'site_url' => 'https://site' . $i . '.example.com',
            'allowed_domains' => ['site' . $i . '.example.com'],
            'is_active' => true,
            'status' => 'connected',
        ]));
    }

    $user = User::query()->create([
        'name' => 'Owner',
        'email' => 'owner+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    return compact('organization', 'workspace', 'sites', 'user');
}

it('grants workspace credits and auto allocates them for a single site workspace', function () {
    $setup = createWorkspaceCreditSetup();

    app(CreditWalletService::class)->addWorkspaceCredits(
        workspaceId: (string) $setup['workspace']->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['trigger' => 'test'],
        sourceType: Organization::class,
        sourceId: (string) $setup['organization']->id,
        idempotencyKey: 'test-workspace-grant-single'
    );

    $workspaceSummary = app(WorkspaceCreditLedgerService::class)->summary((string) $setup['workspace']->id);
    $siteSummary = app(CreditWalletService::class)->getSummary((string) $setup['sites']->first()->id);

    expect((int) $workspaceSummary['balance_cached'])->toBe(100)
        ->and((int) $workspaceSummary['allocated_credits'])->toBe(100)
        ->and((int) $workspaceSummary['unallocated_credits'])->toBe(0)
        ->and((int) $siteSummary['available'])->toBe(100);
});

it('keeps credits in the workspace pool until admins allocate them for multi site workspaces', function () {
    $setup = createWorkspaceCreditSetup(2);

    app(CreditWalletService::class)->addWorkspaceCredits(
        workspaceId: (string) $setup['workspace']->id,
        amount: 120,
        type: CreditWalletService::TYPE_PACK_PURCHASE,
        meta: ['trigger' => 'test'],
        sourceType: Organization::class,
        sourceId: (string) $setup['organization']->id,
        idempotencyKey: 'test-workspace-grant-multi'
    );

    $workspaceBefore = app(WorkspaceCreditLedgerService::class)->summary((string) $setup['workspace']->id);
    expect((int) $workspaceBefore['unallocated_credits'])->toBe(120);
    expect((int) app(CreditWalletService::class)->getSummary((string) $setup['sites'][0]->id)['available'])->toBe(0);

    app(SiteCreditAllocationService::class)->allocateToSite((string) $setup['sites'][0]->id, 70, $setup['user']->id);

    $workspaceAfter = app(WorkspaceCreditLedgerService::class)->summary((string) $setup['workspace']->id);
    $siteAfter = app(CreditWalletService::class)->getSummary((string) $setup['sites'][0]->id);

    expect((int) $workspaceAfter['allocated_credits'])->toBe(70)
        ->and((int) $workspaceAfter['unallocated_credits'])->toBe(50)
        ->and((int) $siteAfter['available'])->toBe(70);
});

it('auto allocates workspace credits to a site when generation needs them', function () {
    $setup = createWorkspaceCreditSetup(2);

    app(CreditWalletService::class)->addWorkspaceCredits(
        workspaceId: (string) $setup['workspace']->id,
        amount: 120,
        type: CreditWalletService::TYPE_PACK_PURCHASE,
        meta: ['trigger' => 'test'],
        sourceType: Organization::class,
        sourceId: (string) $setup['organization']->id,
        idempotencyKey: 'test-workspace-auto-allocate'
    );

    $credits = app(CreditWalletService::class);
    $siteId = (string) $setup['sites'][0]->id;

    expect($credits->getAvailableForClientSite($siteId))->toBe(0)
        ->and($credits->getAvailableForClientSiteIncludingWorkspacePool($siteId))->toBe(120);

    $available = $credits->ensureAvailableForClientSite($siteId, 10, $setup['user']->id, [
        'feature' => 'test_generation',
    ]);

    $workspaceAfter = app(WorkspaceCreditLedgerService::class)->summary((string) $setup['workspace']->id);
    $siteAfter = $credits->getSummary($siteId);

    expect($available)->toBe(10)
        ->and((int) $siteAfter['available'])->toBe(10)
        ->and((int) $workspaceAfter['allocated_credits'])->toBe(10)
        ->and((int) $workspaceAfter['unallocated_credits'])->toBe(110);
});

it('does not count credits already allocated to another site as workspace pool credits', function () {
    $setup = createWorkspaceCreditSetup(2);

    app(CreditWalletService::class)->addWorkspaceCredits(
        workspaceId: (string) $setup['workspace']->id,
        amount: 120,
        type: CreditWalletService::TYPE_PACK_PURCHASE,
        meta: ['trigger' => 'test'],
        sourceType: Organization::class,
        sourceId: (string) $setup['organization']->id,
        idempotencyKey: 'test-workspace-allocated-pool'
    );

    app(SiteCreditAllocationService::class)->allocateToSite((string) $setup['sites'][0]->id, 120, $setup['user']->id);

    $credits = app(CreditWalletService::class);

    expect($credits->getAvailableForClientSiteIncludingWorkspacePool((string) $setup['sites'][1]->id))->toBe(0);
});

it('prevents reservations that exceed the site allocation', function () {
    $setup = createWorkspaceCreditSetup();

    app(CreditWalletService::class)->addWorkspaceCredits(
        workspaceId: (string) $setup['workspace']->id,
        amount: 50,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['trigger' => 'test'],
        sourceType: Organization::class,
        sourceId: (string) $setup['organization']->id,
        idempotencyKey: 'test-reservation-guard'
    );

    $reservations = app(CreditReservationService::class);
    $reservations->reserve((string) $setup['sites']->first()->id, 40, 'reservation-1', 'draft_generate');

    expect(fn () => $reservations->reserve((string) $setup['sites']->first()->id, 20, 'reservation-2', 'draft_generate'))
        ->toThrow(InsufficientCreditsException::class);
});

it('captures reserved credits against both the site allocation and workspace wallet', function () {
    $setup = createWorkspaceCreditSetup();

    app(CreditWalletService::class)->addWorkspaceCredits(
        workspaceId: (string) $setup['workspace']->id,
        amount: 50,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['trigger' => 'test'],
        sourceType: Organization::class,
        sourceId: (string) $setup['organization']->id,
        idempotencyKey: 'test-reservation-capture'
    );

    $reservations = app(CreditReservationService::class);
    $reservation = $reservations->reserve((string) $setup['sites']->first()->id, 25, 'reservation-capture-1', 'draft_generate');
    $reservations->capture($reservation, ['captureAmount' => 20]);

    $workspaceSummary = app(WorkspaceCreditLedgerService::class)->summary((string) $setup['workspace']->id);
    $siteSummary = app(CreditWalletService::class)->getSummary((string) $setup['sites']->first()->id);

    expect((int) $workspaceSummary['balance_cached'])->toBe(30)
        ->and((int) $workspaceSummary['reserved_cached'])->toBe(0)
        ->and((int) $siteSummary['balance_cached'])->toBe(30)
        ->and((int) $siteSummary['reserved_cached'])->toBe(0)
        ->and((int) $siteSummary['used_cached'])->toBe(20)
        ->and((int) $siteSummary['available'])->toBe(30);
});
