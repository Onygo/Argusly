<?php

use App\Jobs\ContentNetwork\AnalyzeContentNetworkJob;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentCluster;
use App\Models\LinkOpportunity;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SeoAudit;
use App\Models\SeoAuditPage;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Services\ContentNetwork\LinkGraphAnalyzer;
use App\Services\ContentNetwork\TopicClusterService;
use App\Services\Entitlements\FeatureGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('runs content network analysis job and persists summarized outcomes', function () {
    [, $workspace, $site, $user] = makeContentNetworkContext('content-network-job');
    setContentNetworkEntitlement($workspace, true);

    $contentA = makePublishedContent($workspace, $site, 'AI governance fundamentals', 'ai governance', '/ai-governance');
    $contentB = makePublishedContent($workspace, $site, 'AI governance checklist', 'ai governance checklist', '/ai-governance-checklist');
    $contentC = makePublishedContent($workspace, $site, 'Editorial workflow basics', 'editorial workflow', '/editorial-workflow');

    makeSeoAuditWithPages($workspace, $site, [
        [(string) $contentA->id, 5, 1400],
        [(string) $contentB->id, 2, 1100],
        [(string) $contentC->id, 0, 900],
    ]);

    $job = new AnalyzeContentNetworkJob((string) $workspace->id, false, (int) $user->id, 'run-content-network-job');
    $job->handle(
        app(TopicClusterService::class),
        app(LinkGraphAnalyzer::class),
        app(\App\Services\ContentNetwork\ContentGapDetector::class),
        app(\App\Services\ContentChain\ChainedContentOpportunityService::class),
        app(FeatureGate::class),
    );

    $workspace->refresh();

    expect(ContentCluster::query()->where('workspace_id', $workspace->id)->count())->toBeGreaterThan(0)
        ->and(LinkOpportunity::query()->where('workspace_id', $workspace->id)->count())->toBeGreaterThan(0)
        ->and((string) data_get($workspace->visual_settings, 'content_network.status'))->toBe('completed')
        ->and((array) data_get($workspace->visual_settings, 'content_network.gaps.missing_support_articles', []))->not->toBeEmpty();
});

it('creates practical topic clusters with pillar candidates', function () {
    [, $workspace, $site] = makeContentNetworkContext('content-network-clusters');
    setContentNetworkEntitlement($workspace, true);

    $contentA = makePublishedContent($workspace, $site, 'B2B content strategy guide', 'content strategy', '/content-strategy-guide');
    $contentB = makePublishedContent($workspace, $site, 'Content strategy examples', 'content strategy examples', '/content-strategy-examples');
    $contentC = makePublishedContent($workspace, $site, 'SEO migration checklist', 'seo migration', '/seo-migration-checklist');

    makeSeoAuditWithPages($workspace, $site, [
        [(string) $contentA->id, 6, 1800],
        [(string) $contentB->id, 2, 900],
        [(string) $contentC->id, 1, 1000],
    ]);

    $result = app(TopicClusterService::class)->buildAndPersist($workspace);
    $clusters = $result['clusters'];

    expect($clusters->count())->toBeGreaterThan(0)
        ->and($clusters->first()->pillar_content_id)->not->toBeNull()
        ->and((float) ($clusters->first()->cluster_score ?? 0.0))->toBeGreaterThan(0);
});

it('generates link opportunities and detects orphan content', function () {
    [, $workspace, $site] = makeContentNetworkContext('content-network-graph');
    setContentNetworkEntitlement($workspace, true);

    $contentA = makePublishedContent($workspace, $site, 'Laravel content workflow', 'content workflow', '/laravel-content-workflow');
    $contentB = makePublishedContent($workspace, $site, 'Workflow approval guide', 'content workflow', '/workflow-approval-guide');
    $contentC = makePublishedContent($workspace, $site, 'Isolated article', 'isolated article', '/isolated-article');

    makeSeoAuditWithPages($workspace, $site, [
        [(string) $contentA->id, 3, 1200],
        [(string) $contentB->id, 2, 1100],
        [(string) $contentC->id, 0, 800],
    ]);

    $clusterResult = app(TopicClusterService::class)->buildAndPersist($workspace);
    $graphResult = app(LinkGraphAnalyzer::class)->analyzeAndPersist($workspace, (array) ($clusterResult['content_signals'] ?? []));

    expect((array) ($graphResult['orphan_content_ids'] ?? []))->toContain((string) $contentC->id)
        ->and((int) ($graphResult['opportunities_count'] ?? 0))->toBeGreaterThan(0);
});

it('denies content network overview when entitlement is disabled', function () {
    config(['features.content_network_analysis' => true]);
    [, $workspace, , $user] = makeContentNetworkContext('content-network-denied');
    setContentNetworkEntitlement($workspace, false);

    $this->actingAs($user)
        ->get(route('app.content-network.index', ['workspace_id' => (string) $workspace->id]))
        ->assertStatus(403);
});

it('enforces workspace isolation for content network overview', function () {
    config(['features.content_network_analysis' => true]);

    [, , , $userA] = makeContentNetworkContext('content-network-org-a');
    [, $workspaceB] = makeContentNetworkContext('content-network-org-b');

    $this->actingAs($userA)
        ->get(route('app.content-network.index', ['workspace_id' => (string) $workspaceB->id]))
        ->assertStatus(404);
});

it('is safe to rerun analysis without duplicating clusters or opportunities', function () {
    [, $workspace, $site, $user] = makeContentNetworkContext('content-network-rerun');
    setContentNetworkEntitlement($workspace, true);

    $contentA = makePublishedContent($workspace, $site, 'Technical SEO basics', 'technical seo', '/technical-seo-basics');
    $contentB = makePublishedContent($workspace, $site, 'Technical SEO implementation', 'technical seo implementation', '/technical-seo-implementation');

    makeSeoAuditWithPages($workspace, $site, [
        [(string) $contentA->id, 2, 1000],
        [(string) $contentB->id, 1, 900],
    ]);

    $job = new AnalyzeContentNetworkJob((string) $workspace->id, false, (int) $user->id, 'run-content-network-rerun');

    $job->handle(
        app(TopicClusterService::class),
        app(LinkGraphAnalyzer::class),
        app(\App\Services\ContentNetwork\ContentGapDetector::class),
        app(\App\Services\ContentChain\ChainedContentOpportunityService::class),
        app(FeatureGate::class),
    );
    $clustersFirst = ContentCluster::query()->where('workspace_id', $workspace->id)->count();
    $opportunitiesFirst = LinkOpportunity::query()->where('workspace_id', $workspace->id)->count();

    $job->handle(
        app(TopicClusterService::class),
        app(LinkGraphAnalyzer::class),
        app(\App\Services\ContentNetwork\ContentGapDetector::class),
        app(\App\Services\ContentChain\ChainedContentOpportunityService::class),
        app(FeatureGate::class),
    );
    $clustersSecond = ContentCluster::query()->where('workspace_id', $workspace->id)->count();
    $opportunitiesSecond = LinkOpportunity::query()->where('workspace_id', $workspace->id)->count();

    expect($clustersSecond)->toBe($clustersFirst)
        ->and($opportunitiesSecond)->toBe($opportunitiesFirst);
});

it('renders content network overview ui for authorized users', function () {
    config(['features.content_network_analysis' => true]);
    [, $workspace, $site, $user] = makeContentNetworkContext('content-network-ui');
    setContentNetworkEntitlement($workspace, true);

    makePublishedContent($workspace, $site, 'Content operations', 'content operations', '/content-operations');
    app(TopicClusterService::class)->buildAndPersist($workspace);

    $this->actingAs($user)
        ->get(route('app.content-network.index', ['workspace_id' => (string) $workspace->id]))
        ->assertOk()
        ->assertSee('Content Network Intelligence')
        ->assertSee('Topic clusters')
        ->assertSee('Link opportunities')
        ->assertSee('Content gaps');
});

it('queues content network analysis from controller run action', function () {
    config(['features.content_network_analysis' => true]);
    Queue::fake();

    [, $workspace, , $user] = makeContentNetworkContext('content-network-run-endpoint');
    setContentNetworkEntitlement($workspace, true);

    $response = $this->actingAs($user)
        ->post(route('app.content-network.run', $workspace), ['force' => true]);

    $response->assertRedirect(route('app.content-network.index', ['workspace_id' => (string) $workspace->id]));
    Queue::assertPushed(AnalyzeContentNetworkJob::class);
});

it('blocks run action for viewer role', function () {
    config(['features.content_network_analysis' => true]);
    [, $workspace, , $user] = makeContentNetworkContext('content-network-viewer', role: 'viewer');
    setContentNetworkEntitlement($workspace, true);

    $this->actingAs($user)
        ->post(route('app.content-network.run', $workspace))
        ->assertStatus(403);
});

function makeContentNetworkContext(string $prefix = 'content-network', string $role = 'owner'): array
{
    $organization = Organization::query()->create([
        'name' => 'Content Network Org',
        'slug' => $prefix . '-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Content Network BV',
        'billing_address_line1' => 'Clusterstraat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Content Network Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Content Network Site',
        'site_url' => 'https://content-network.example.com',
        'allowed_domains' => ['content-network.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => $prefix . '-plan'],
        [
            'name' => 'Content Network Plan',
            'slug' => $prefix . '-plan',
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

    $user = User::query()->create([
        'name' => 'Content Network User',
        'email' => $prefix . '+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => $role,
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$organization, $workspace, $site, $user];
}

function makePublishedContent(
    Workspace $workspace,
    ClientSite $site,
    string $title,
    string $primaryKeyword,
    string $path
): Content {
    return Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => $title,
        'primary_keyword' => $primaryKeyword,
        'type' => 'article',
        'status' => 'published',
        'source' => 'manual',
        'publish_status' => 'published',
        'published_url' => 'https://content-network.example.com' . $path,
        'external_key' => (string) Str::uuid(),
        'generation_mode' => 'balanced',
        'preferred_length' => 'medium',
    ]);
}

/**
 * @param array<int,array{0:string,1:int,2:int}> $rows
 */
function makeSeoAuditWithPages(Workspace $workspace, ClientSite $site, array $rows): SeoAudit
{
    $audit = SeoAudit::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'started_at' => now()->subMinutes(10),
        'finished_at' => now()->subMinutes(1),
        'status' => 'completed',
        'pages_crawled' => count($rows),
        'issue_counts' => ['error' => 0, 'warning' => 0, 'info' => 0],
    ]);

    foreach ($rows as [$contentId, $internalLinks, $wordCount]) {
        SeoAuditPage::query()->create([
            'seo_audit_id' => $audit->id,
            'url' => 'https://content-network.example.com/' . Str::slug((string) $contentId),
            'title' => 'Audit page',
            'status_code' => 200,
            'word_count' => $wordCount,
            'internal_links_count' => $internalLinks,
            'broken_links_count' => 0,
            'page_type' => SeoAuditPage::PAGE_TYPE_PUBLISHLAYER_ARTICLE,
            'publishlayer_article_id' => $contentId,
        ]);
    }

    return $audit;
}

function setContentNetworkEntitlement(Workspace $workspace, bool $enabled): void
{
    WorkspaceEntitlement::query()->updateOrCreate(
        [
            'workspace_id' => $workspace->id,
            'feature_key' => 'content_network_analysis_enabled',
        ],
        [
            'id' => (string) Str::uuid(),
            'organization_id' => $workspace->organization_id,
            'value_type' => 'bool',
            'value_bool' => $enabled,
            'value_int' => null,
            'value_string' => null,
            'value_json' => null,
            'source' => 'manual',
            'effective_at' => now()->subMinute(),
            'expires_at' => null,
            'refreshed_at' => now(),
        ]
    );
}
