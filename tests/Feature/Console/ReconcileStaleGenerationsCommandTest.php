<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentImage;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('reconciles stale draft and image locks and releases reservations', function () {
    $organization = Organization::query()->create([
        'name' => 'Reconcile Org',
        'slug' => 'reconcile-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Reconcile Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Reconcile Site',
        'site_url' => 'https://reconcile.example.com',
        'allowed_domains' => ['reconcile.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'growth-'.Str::lower(Str::random(4)),
        'slug' => 'growth-'.Str::lower(Str::random(4)),
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

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Reconcile Content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'api',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Reconcile Brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'processing',
        'title' => 'Reconcile Draft',
        'output_type' => 'kb_article',
        'credit_cost' => 10,
    ]);

    $image = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'featured',
        'prompt' => 'reconcile image',
        'status' => 'generating',
        'credit_cost' => 6,
    ]);

    $credits = app(CreditWalletService::class);
    $credits->addCredits(
        clientSiteId: (string) $site->id,
        amount: 50,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['reason' => 'reconcile_test_funding']
    );

    $credits->reserveForDraft($draft->fresh());
    $credits->reserveForContentImage($image->fresh());

    Draft::query()->whereKey($draft->id)->update(['updated_at' => Carbon::now()->subMinutes(30)]);
    ContentImage::query()->whereKey($image->id)->update(['updated_at' => Carbon::now()->subMinutes(30)]);

    $this->artisan('generations:reconcile-stale', ['--minutes' => 5, '--limit' => 50])->assertExitCode(0);

    $draft->refresh();
    $image->refresh();

    $summary = $credits->getSummary((string) $site->id);

    expect((string) $draft->status)->toBe('failed')
        ->and((string) $draft->credit_status)->toBe('released')
        ->and((string) $image->status)->toBe('failed')
        ->and((string) $image->credit_status)->toBe('released')
        ->and((int) $summary['reserved_cached'])->toBe(0)
        ->and((int) $summary['available'])->toBe(50);
});
