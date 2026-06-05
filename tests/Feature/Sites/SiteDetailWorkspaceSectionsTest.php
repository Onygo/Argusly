<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublishTarget;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SiteToken;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('renders site connection, usage, and content as separate sections', function () {
    [$owner, , $site] = makeSiteDetailWorkspaceSectionsContext();

    $this->actingAs($owner)
        ->get(route('app.sites.show', $site))
        ->assertOk()
        ->assertSee('Site connection')
        ->assertSee('Usage')
        ->assertSee('Content')
        ->assertSee('View insights')
        ->assertDontSee('Insights Overview')
        ->assertDontSee('Credentials and usage');
});

it('does not render content or insights actions inside the site connection section', function () {
    [$owner, , $site] = makeSiteDetailWorkspaceSectionsContext();

    $html = $this->actingAs($owner)
        ->get(route('app.sites.show', $site))
        ->assertOk()
        ->getContent();

    $connectionText = sectionTextByHeading($html, 'Site connection');

    foreach (['Create content', 'Open content', 'View drafts', 'Push to WP'] as $action) {
        expect($connectionText)->not->toContain($action);
    }

    foreach (['LLM visibility tracking', 'Competitors', 'SEO audits', 'Analytics', 'Learnings'] as $action) {
        expect($connectionText)->not->toContain($action);
    }
});

it('does not render content or insights actions inside the usage section', function () {
    [$owner, , $site] = makeSiteDetailWorkspaceSectionsContext();

    $html = $this->actingAs($owner)
        ->get(route('app.sites.show', $site))
        ->assertOk()
        ->getContent();

    $usageText = sectionTextByHeading($html, 'Usage');

    foreach (['Create content', 'Open content', 'View drafts', 'Push to WP'] as $action) {
        expect($usageText)->not->toContain($action);
    }

    foreach (['LLM visibility tracking', 'Competitors', 'SEO audits', 'Analytics', 'Learnings'] as $action) {
        expect($usageText)->not->toContain($action);
    }
});

it('renders content actions in the content section', function () {
    [$owner, , $site] = makeSiteDetailWorkspaceSectionsContext();

    $html = $this->actingAs($owner)
        ->get(route('app.sites.show', $site))
        ->assertOk()
        ->getContent();

    $contentText = sectionTextByHeading($html, 'Content');

    expect($contentText)->toContain('Create content')
        ->toContain('Open content')
        ->toContain('View drafts')
        ->toContain('Push to WP');
});

it('does not render the old embedded insights module on the site detail page', function () {
    [$owner, , $site] = makeSiteDetailWorkspaceSectionsContext();

    $response = $this->actingAs($owner)
        ->get(route('app.sites.show', $site))
        ->assertOk();

    $response
        ->assertSee('View insights')
        ->assertSee(route('app.sites.insights.index', $site), false)
        ->assertDontSee('LLM visibility tracking')
        ->assertDontSee('SEO audits')
        ->assertDontSee('Learnings');
});

it('keeps connection and usage metadata visible after the refactor', function () {
    [$owner, , $site] = makeSiteDetailWorkspaceSectionsContext();

    $html = $this->actingAs($owner)
        ->get(route('app.sites.show', $site))
        ->assertOk()
        ->getContent();

    $connectionText = sectionTextByHeading($html, 'Site connection');
    $usageText = sectionTextByHeading($html, 'Usage');

    expect($connectionText)->toContain('Key status')
        ->toContain('Active')
        ->toContain('Key last used')
        ->toContain('briefs:write')
        ->toContain('drafts:write');

    expect($usageText)->toContain('Briefs used this month')
        ->toContain('Drafts used this month')
        ->toContain('Pushes to WordPress this month')
        ->toContain('1');
});

it('keeps site detail scoped to the owning organization', function () {
    [$owner, $editor, $site] = makeSiteDetailWorkspaceSectionsContext();

    $this->actingAs($owner)
        ->get(route('app.sites.show', $site))
        ->assertOk();

    $this->actingAs($editor)
        ->get(route('app.sites.show', $site))
        ->assertOk();

    $otherOrg = Organization::query()->create([
        'name' => 'Other Org',
        'slug' => 'other-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Other Org BV',
        'billing_address_line1' => 'Otherstraat 1',
        'billing_country_code' => 'NL',
    ]);

    $otherWorkspace = Workspace::query()->create([
        'name' => 'Other Workspace',
        'organization_id' => $otherOrg->id,
    ]);

    $otherUser = User::query()->create([
        'name' => 'Other Owner',
        'email' => 'other-owner+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $otherOrg->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $otherOrg->id,
        'workspace_id' => $otherWorkspace->id,
        'plan_id' => Plan::query()->firstOrCreate(
            ['key' => 'site-detail-sections-other-plan'],
            [
                'name' => 'Other Plan',
                'is_active' => true,
                'price_cents' => 0,
                'currency' => 'EUR',
                'interval' => 'month',
                'included_credits_per_interval' => 100,
            ]
        )->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $this->actingAs($otherUser)
        ->get(route('app.sites.show', $site))
        ->assertNotFound();
});

function makeSiteDetailWorkspaceSectionsContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Site Detail Org',
        'slug' => 'site-detail-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Site Detail Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Site Detail Workspace',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'site-detail-sections-plan'],
        [
            'name' => 'Site Detail Plan',
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
        'name' => 'Site Detail Owner',
        'email' => 'site-detail-owner+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $editor = User::query()->create([
        'name' => 'Site Detail Editor',
        'email' => 'site-detail-editor+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'editor',
        'active' => true,
        'approved_at' => now(),
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Detail Site',
        'site_url' => 'https://details.example.com',
        'base_url' => 'https://details.example.com',
        'allowed_domains' => ['details.example.com'],
        'is_active' => true,
        'status' => 'connected',
        'last_seen_at' => now()->subMinutes(2),
        'last_heartbeat_at' => now()->subMinutes(1),
        'last_healthcheck_at' => now()->subMinutes(5),
    ]);

    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Primary token',
        'token_hash' => hash('sha256', 'token-' . Str::random(16)),
        'key_prefix' => 'pl_site_test',
        'abilities' => ['briefs:write', 'drafts:write', 'content:push'],
        'scopes' => ['briefs:write', 'drafts:write', 'content:push'],
        'revoked' => false,
        'last_used_at' => now()->subMinutes(10),
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'created_by_user_id' => $owner->id,
        'status' => 'ready',
        'title' => 'Site brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    \App\Models\Draft::query()->create([
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Site draft',
        'output_type' => 'kb_article',
        'content_html' => '<p>Draft body</p>',
    ]);

    $content = Content::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Published content',
        'type' => 'article',
        'status' => 'published',
        'source' => 'manual',
        'delivery_status' => 'delivered',
        'generation_mode' => 'balanced',
    ]);

    ContentPublishTarget::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'target_type' => 'wp',
        'target_identifier' => 'post',
        'sync_status' => 'synced',
        'wp_post_id' => '123',
        'last_synced_at' => now()->subMinutes(3),
    ]);

    return [$owner, $editor, $site];
}

function sectionTextByHeading(string $html, string $heading): string
{
    $startMarker = '<h2 class="text-sm font-semibold text-textPrimary">' . $heading . '</h2>';
    $startPos = strpos($html, $startMarker);
    expect($startPos)->not->toBeFalse();

    $nextHeadingPos = strpos($html, '<h2 class="text-sm font-semibold text-textPrimary">', $startPos + strlen($startMarker));
    if ($nextHeadingPos === false) {
        $nextHeadingPos = strlen($html);
    }

    $slice = substr($html, $startPos, $nextHeadingPos - $startPos);

    return preg_replace('/\s+/', ' ', trim(strip_tags($slice))) ?: '';
}
