<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\CreditWallet;
use App\Models\CreditLedgerEntry;
use App\Models\Organization;
use App\Models\Draft;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('reserves and commits draft credits idempotently', function () {
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

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Credits Site',
        'site_url' => 'https://credits.example.com',
        'allowed_domains' => ['credits.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Idempotency brief',
        'language' => 'nl',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
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

    $draft = Draft::query()->create([
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'Idempotent draft',
        'output_type' => 'kb_article',
        'meta' => ['language' => 'nl'],
    ]);

    $credits = app(CreditWalletService::class);
    $credits->addCredits(
        clientSiteId: (string) $site->id,
        amount: 50,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $reservationA = $credits->reserveForDraft($draft);
    $reservationB = $credits->reserveForDraft($draft->fresh());
    expect((string) $reservationB->id)->toBe((string) $reservationA->id);

    $usageA = $credits->commitUsageForDraft($draft->fresh());
    $usageB = $credits->commitUsageForDraft($draft->fresh());
    expect((string) $usageB->id)->toBe((string) $usageA->id);

    $draft->refresh();
    $wallet = CreditWallet::query()->where('client_site_id', $site->id)->firstOrFail();

    expect((string) $draft->credit_status)->toBe('committed');
    expect((int) $wallet->reserved_cached)->toBe(0);
    expect((int) $wallet->balance_cached)->toBe(50 - (int) $draft->credit_cost);

    expect(CreditLedgerEntry::query()
        ->where('source_type', Draft::class)
        ->where('source_id', $draft->id)
        ->where('type', CreditWalletService::TYPE_RESERVATION)
        ->count())->toBe(1);

    expect(CreditLedgerEntry::query()
        ->where('source_type', Draft::class)
        ->where('source_id', $draft->id)
        ->where('type', CreditWalletService::TYPE_USAGE)
        ->count())->toBe(1);
});
