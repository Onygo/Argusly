<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\CreditLedgerEntry;
use App\Models\CreditReservation;
use App\Models\CreditWallet;
use App\Models\DraftComparison;
use App\Models\DraftComparisonItem;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use App\Services\DraftComparison\DraftComparisonCreditManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeDraftComparisonCreditContext(string $prefix = 'draft-compare-credit'): array
{
    $organization = Organization::query()->create([
        'name' => 'Draft Compare Credit Org',
        'slug' => $prefix . '-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Draft Compare Credit Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Draft Compare Credit Site',
        'site_url' => 'https://draft-compare-credit.example.com',
        'allowed_domains' => ['draft-compare-credit.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::query()->create([
        'name' => 'Draft Compare Credit User',
        'email' => $prefix . '+' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Draft compare billing brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    return [$organization, $workspace, $site, $user, $brief];
}

it('reserves comparison credits idempotently', function () {
    [, , $site, $user, $brief] = makeDraftComparisonCreditContext();

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $site->workspace_id,
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'mode' => 'compare_two',
        'status' => 'queued',
        'estimated_credit_cost' => 40,
    ]);

    $manager = app(DraftComparisonCreditManager::class);

    $first = $manager->reserveForComparison($comparison, 40, $user->id);
    $second = $manager->reserveForComparison($comparison->fresh(), 40, $user->id);

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull()
        ->and((string) $first->id)->toBe((string) $second->id);

    $wallet = CreditWallet::query()->where('client_site_id', $site->id)->firstOrFail();
    expect((int) $wallet->reserved_cached)->toBe(40)
        ->and((int) $wallet->balance_cached)->toBe(100);

    $comparison->refresh();
    expect((int) $comparison->reserved_credit_amount)->toBe(40)
        ->and((string) data_get($comparison->comparison_summary_json, 'billing.state'))->toBe('reserved');
});

it('settles a partially failed comparison by charging only successful variants and refunding unused reserve', function () {
    [, , $site, $user, $brief] = makeDraftComparisonCreditContext('draft-compare-credit-partial');

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $site->workspace_id,
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'mode' => 'compare_two',
        'status' => 'partially_completed',
        'estimated_credit_cost' => 24,
    ]);

    DraftComparisonItem::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'sort_order' => 1,
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'status' => 'generated',
        'credit_cost' => 12,
        'charged_credits' => 12,
    ]);

    DraftComparisonItem::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'sort_order' => 2,
        'provider' => 'anthropic',
        'model' => 'claude-3-5-sonnet-latest',
        'status' => 'failed',
        'credit_cost' => 12,
        'charged_credits' => 0,
        'error_message' => 'Provider error',
    ]);

    $manager = app(DraftComparisonCreditManager::class);
    $manager->reserveForComparison($comparison, 24, $user->id);

    $settled = $manager->settleComparison($comparison->fresh(), $user->id);
    $settledAgain = $manager->settleComparison($comparison->fresh(), $user->id);

    $reservation = CreditReservation::query()
        ->where('context_type', DraftComparison::class)
        ->where('context_id', $comparison->id)
        ->firstOrFail();

    $wallet = CreditWallet::query()->where('client_site_id', $site->id)->firstOrFail();

    expect((string) $reservation->status)->toBe(CreditReservation::STATUS_CAPTURED)
        ->and((int) data_get($reservation->metadata, 'captured_amount'))->toBe(12)
        ->and((int) data_get($reservation->metadata, 'unused_amount_released'))->toBe(12)
        ->and((int) $wallet->reserved_cached)->toBe(0)
        ->and((int) $wallet->balance_cached)->toBe(88)
        ->and((int) $settled->fresh()->final_credit_cost)->toBe(12)
        ->and((int) $settled->fresh()->credits_used)->toBe(12)
        ->and((int) $settledAgain->fresh()->final_credit_cost)->toBe(12);

    $usageLedgerCount = CreditLedgerEntry::query()
        ->where('idempotency_key', 'capture-usage:' . (string) $reservation->id)
        ->count();

    expect($usageLedgerCount)->toBe(1);
});

it('releases all reserved credits when a comparison fully fails', function () {
    [, , $site, $user, $brief] = makeDraftComparisonCreditContext('draft-compare-credit-failed');

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $site->workspace_id,
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'mode' => 'compare_two',
        'status' => 'failed',
        'estimated_credit_cost' => 24,
    ]);

    DraftComparisonItem::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'sort_order' => 1,
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'status' => 'failed',
        'credit_cost' => 12,
        'charged_credits' => 0,
    ]);

    DraftComparisonItem::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'sort_order' => 2,
        'provider' => 'anthropic',
        'model' => 'claude-3-5-sonnet-latest',
        'status' => 'failed',
        'credit_cost' => 12,
        'charged_credits' => 0,
    ]);

    $manager = app(DraftComparisonCreditManager::class);
    $manager->reserveForComparison($comparison, 24, $user->id);
    $manager->settleComparison($comparison->fresh(), $user->id);

    $reservation = CreditReservation::query()
        ->where('context_type', DraftComparison::class)
        ->where('context_id', $comparison->id)
        ->firstOrFail();

    $wallet = CreditWallet::query()->where('client_site_id', $site->id)->firstOrFail();

    expect((string) $reservation->status)->toBe(CreditReservation::STATUS_RELEASED)
        ->and((int) $wallet->reserved_cached)->toBe(0)
        ->and((int) $wallet->balance_cached)->toBe(100)
        ->and((int) $comparison->fresh()->final_credit_cost)->toBe(0)
        ->and((int) $comparison->fresh()->credits_used)->toBe(0);
});
