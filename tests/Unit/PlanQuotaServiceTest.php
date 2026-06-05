<?php

use App\Models\ClientSite;
use App\Models\CrossLinkPermission;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\PlanQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeQuotaWorkspace(array $featureInts = []): array
{
    $organization = Organization::query()->create([
        'name' => 'Quota Org',
        'slug' => 'quota-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Quota Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Quota Site',
        'site_url' => 'https://quota.example.com',
        'allowed_domains' => ['quota.example.com'],
        'is_active' => true,
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'quota-plan-' . Str::random(4),
        'slug' => 'quota-plan-' . Str::random(4),
        'name' => 'Quota Plan',
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

    foreach ($featureInts as $featureKey => $valueInt) {
        PlanFeature::query()->create([
            'id' => (string) Str::uuid(),
            'plan_id' => $plan->id,
            'feature_key' => $featureKey,
            'value_type' => 'int',
            'value_int' => (int) $valueInt,
        ]);
    }

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

    return [$organization, $workspace, $site, $plan];
}

it('treats article generation usage as report-only even when a legacy article limit exists', function () {
    [, $workspace, $site] = makeQuotaWorkspace([
        'articles_per_month_limit' => 2,
    ]);

    $service = app(PlanQuotaService::class);

    expect($service->canGenerateArticle($workspace, $site))->toBeTrue();
    $service->incrementUsage($workspace, $site, PlanQuotaService::METRIC_ARTICLES_GENERATED, 1, now()->format('Ym'));
    expect($service->canGenerateArticle($workspace, $site))->toBeTrue();
    $service->incrementUsage($workspace, $site, PlanQuotaService::METRIC_ARTICLES_GENERATED, 1, now()->format('Ym'));
    expect($service->canGenerateArticle($workspace, $site))->toBeTrue();
    expect($service->limitForMetric($workspace, PlanQuotaService::METRIC_ARTICLES_GENERATED, -1))->toBe(-1);

    $nextMonth = now()->addMonth()->startOfMonth();
    $this->travelTo($nextMonth);
    expect($service->canGenerateArticle($workspace, $site))->toBeTrue();
});

it('enforces llm and audit quotas with requested amount', function () {
    [, $workspace, $site] = makeQuotaWorkspace([
        'llm_tracking_queries_per_month_limit' => 3,
        'seo_audit_crawl_pages_per_month_limit' => 10,
    ]);

    $service = app(PlanQuotaService::class);

    $service->incrementUsage($workspace, $site, PlanQuotaService::METRIC_LLM_QUERIES_RUN, 3, now()->format('Ym'));
    expect($service->canRunLlmQuery($workspace, $site))->toBeFalse();

    $service->incrementUsage($workspace, $site, PlanQuotaService::METRIC_AUDIT_PAGES_CRAWLED, 7, now()->format('Ym'));
    expect($service->canCrawlAuditPages($workspace, $site, 3))->toBeTrue();
    expect($service->canCrawlAuditPages($workspace, $site, 4))->toBeFalse();
});

it('enforces competitor slots against active and pending competitors', function () {
    [, $workspace] = makeQuotaWorkspace([
        'competitor_slots_limit' => 1,
    ]);

    $targetWorkspace = Workspace::query()->create([
        'name' => 'Competitor Target',
        'organization_id' => $workspace->organization_id,
    ]);

    CrossLinkPermission::query()->create([
        'from_workspace_id' => $workspace->id,
        'to_workspace_id' => $targetWorkspace->id,
        'status' => 'pending',
        'relationship_type' => 'partner',
        'rel_attribute' => 'follow',
    ]);

    $service = app(PlanQuotaService::class);
    expect($service->canAddCompetitor($workspace))->toBeFalse();
});
