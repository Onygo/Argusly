<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\SiteToken;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use App\Services\Entitlements\WorkspaceEntitlementsService;
use App\Services\PlanQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeQuotaApiContext(int $articlesLimit): array
{
    $organization = Organization::query()->create([
        'name' => 'API Quota Org',
        'slug' => 'api-quota-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'API Quota Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'API Quota Site',
        'site_url' => 'https://api-quota.example.com',
        'allowed_domains' => ['api-quota.example.com'],
        'is_active' => true,
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'api-quota-plan-' . Str::random(4),
        'slug' => 'api-quota-plan-' . Str::random(4),
        'name' => 'API Quota Plan',
        'interval' => 'month',
        'monthly_price_cents' => 4900,
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits' => 100,
        'included_credits_per_interval' => 100,
        'seat_limit' => 3,
        'limits' => ['users' => 3, 'sites' => 3, 'workspaces' => 1],
        'is_active' => true,
    ]);

    PlanFeature::query()->create([
        'id' => (string) Str::uuid(),
        'plan_id' => $plan->id,
        'feature_key' => 'articles_per_month_limit',
        'value_type' => 'int',
        'value_int' => $articlesLimit,
    ]);

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'seat_limit' => 3,
        'status' => 'active',
        'current_period_start' => now()->startOfDay(),
        'current_period_end' => now()->addMonth()->startOfDay(),
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Quota brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = \App\Models\Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'Quota draft',
        'output_type' => 'kb_article',
    ]);

    $plainToken = 'pl_site_' . Str::random(48);
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plainToken),
        'scopes' => ['drafts:write', 'drafts:read'],
        'revoked' => false,
    ]);

    return [$workspace, $site, $draft, $plainToken];
}

it('allows draft generation api above the legacy article quota when credits are available', function () {
    [, $site, $draft, $token] = makeQuotaApiContext(0);
    app(CreditWalletService::class)->addCredits((string) $site->id, 100, CreditWalletService::TYPE_ALLOWANCE);

    $quota = app(PlanQuotaService::class);
    $quota->incrementUsage($site->workspace, $site, PlanQuotaService::METRIC_ARTICLES_GENERATED, 25, now()->format('Ym'));

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token,
        'X-Argusly-Site' => 'api-quota.example.com',
    ])->postJson('/api/v1/drafts/' . $draft->id . '/generate', []);

    $response->assertStatus(202);
    $response->assertJsonPath('ok', true);
});

it('returns a structured insufficient credits error when draft generation has no credits left', function () {
    [, $site, $draft, $token] = makeQuotaApiContext(20);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token,
        'X-Argusly-Site' => 'api-quota.example.com',
    ])->postJson('/api/v1/drafts/' . $draft->id . '/generate', []);

    $response->assertStatus(422);
    $response->assertJsonPath('code', 'INSUFFICIENT_CREDITS');
    $response->assertJsonPath('public_error_code', 'CREDIT_BALANCE_LOW');
    $response->assertJsonPath('action', 'draft_generate');
});

it('allows draft generation again immediately after extra credits are purchased', function () {
    [, $site, $draft, $token] = makeQuotaApiContext(0);

    $firstResponse = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token,
        'X-Argusly-Site' => 'api-quota.example.com',
    ])->postJson('/api/v1/drafts/' . $draft->id . '/generate', []);

    $firstResponse->assertStatus(422);
    $firstResponse->assertJsonPath('code', 'INSUFFICIENT_CREDITS');

    app(CreditWalletService::class)->addCredits((string) $site->id, 24, CreditWalletService::TYPE_PACK_PURCHASE);

    $secondResponse = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token,
        'X-Argusly-Site' => 'api-quota.example.com',
    ])->postJson('/api/v1/drafts/' . $draft->id . '/generate', []);

    $secondResponse->assertStatus(202);
    $secondResponse->assertJsonPath('ok', true);
});

it('keeps site plan limits enforced while article generation moved to credits', function () {
    [$workspace] = makeQuotaApiContext(20);

    PlanFeature::query()->create([
        'id' => (string) Str::uuid(),
        'plan_id' => Subscription::query()->where('workspace_id', $workspace->id)->value('plan_id'),
        'feature_key' => 'wp_sites_limit',
        'value_type' => 'int',
        'value_int' => 1,
    ]);

    app(\App\Services\Entitlements\EntitlementRefreshService::class)->refreshForWorkspace($workspace);

    expect(fn () => app(WorkspaceEntitlementsService::class)->assertCanAddSite($workspace))
        ->toThrow(\RuntimeException::class, 'Site limit reached');
});
