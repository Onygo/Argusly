<?php

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('removes content quality from platform admin navigation', function () {
    [$admin] = makeContentIntelligenceContext(role: 'owner', isAdmin: true);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertDontSee('Content Quality')
        ->assertSee('System Health')
        ->assertSee('Queues');
});

it('adds content intelligence to the customer navigation', function () {
    [$user] = makeContentIntelligenceContext(role: 'owner');

    $this->actingAs($user)
        ->get(route('app.dashboard'))
        ->assertOk()
        ->assertSee('Content Intelligence');
});

it('scopes content intelligence audits to the selected workspace', function () {
    [$user, $workspace, $site] = makeContentIntelligenceContext(role: 'owner');
    [, $otherWorkspace, $otherSite] = makeContentIntelligenceContext(role: 'owner');

    makeAuditContent($workspace, $site, 'From SEO to AI Visibility: A Practical Guide');
    makeAuditContent($workspace, $site, 'From SEO to AI Visibility: A Practical GEO Guide');
    makeAuditContent($otherWorkspace, $otherSite, 'Other Customer Duplicate Title');
    makeAuditContent($otherWorkspace, $otherSite, 'Other Customer Duplicate Title');

    $this->actingAs($user)
        ->post(route('app.workspaces.content-quality.run', $workspace), [
            'published_only' => '1',
            'limit' => 50,
            'content_type' => 'article',
        ])
        ->assertOk()
        ->assertSee('Content Intelligence')
        ->assertSee('Very similar title detected')
        ->assertSee('From SEO to AI Visibility: A Practical GEO Guide')
        ->assertDontSee('Other Customer Duplicate Title');
});

it('prevents users from viewing another customer workspace audit', function () {
    [$user] = makeContentIntelligenceContext(role: 'owner');
    [, $otherWorkspace] = makeContentIntelligenceContext(role: 'owner');

    $this->actingAs($user)
        ->get(route('app.workspaces.content-quality.index', $otherWorkspace))
        ->assertNotFound();
});

it('lets a platform admin access a scoped customer content intelligence audit', function () {
    [$admin] = makeContentIntelligenceContext(role: 'owner', isAdmin: true);
    [$targetUser, $workspace, $site, $organization] = makeContentIntelligenceContext(role: 'owner');

    makeAuditContent($workspace, $site, 'Scoped Platform Admin Content');

    $this->actingAs($admin)
        ->withSession([
            'support_mode_enabled' => true,
            'support_target_company_id' => $organization->id,
            'support_target_user_id' => $targetUser->id,
            'support_started_by_admin_id' => $admin->id,
            'support_started_at' => now()->toIso8601String(),
            'support_reason' => 'Content intelligence scoped support check',
        ])
        ->get(route('app.workspaces.content-quality.index', $workspace))
        ->assertOk()
        ->assertSee('Content Intelligence')
        ->assertSee($workspace->display_name ?: $workspace->name);
});

it('does not serve the old global admin content quality audit', function () {
    [$admin] = makeContentIntelligenceContext(role: 'owner', isAdmin: true);

    $this->actingAs($admin)
        ->get(route('admin.content-quality.index'))
        ->assertNotFound();
});

it('redirects old admin route when workspace context is provided', function () {
    [$admin, $workspace] = makeContentIntelligenceContext(role: 'owner', isAdmin: true);

    $this->actingAs($admin)
        ->get(route('admin.content-quality.index', ['workspace_id' => $workspace->id]))
        ->assertRedirect(route('app.workspaces.content-quality.index', $workspace));
});

it('prevents viewer roles from rerunning the audit', function () {
    [$viewer, $workspace] = makeContentIntelligenceContext(role: 'viewer');

    $this->actingAs($viewer)
        ->get(route('app.workspaces.content-quality.index', $workspace))
        ->assertOk()
        ->assertSee('your role cannot trigger new audits');

    $this->actingAs($viewer)
        ->post(route('app.workspaces.content-quality.run', $workspace), [
            'published_only' => '1',
            'limit' => 50,
            'content_type' => 'article',
        ])
        ->assertForbidden();
});

function makeContentIntelligenceContext(string $role = 'owner', bool $isAdmin = false): array
{
    $organization = Organization::query()->create([
        'name' => 'Content Intelligence Org ' . Str::lower(Str::random(6)),
        'slug' => 'content-intelligence-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Content Intelligence Org',
        'billing_address_line1' => 'Test Street 1',
        'billing_country_code' => 'NL',
        'access_tier' => Organization::ACCESS_TIER_EARLY_BIRD,
        'early_bird_started_at' => now(),
        'early_bird_ends_at' => now()->addMonth(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Content Intelligence Workspace',
        'display_name' => 'Content Intelligence Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => (string) $workspace->id,
        'type' => 'wordpress',
        'name' => 'Content Intelligence Site',
        'site_url' => 'https://content-intelligence.example.test',
        'base_url' => 'https://content-intelligence.example.test',
        'allowed_domains' => ['content-intelligence.example.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::query()->create([
        'name' => 'Content Intelligence User',
        'email' => 'content-intelligence-' . Str::lower(Str::random(8)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => $role,
        'active' => true,
        'approved_at' => now(),
        'is_admin' => $isAdmin,
        'admin_role' => $isAdmin ? 'superadmin' : null,
    ]);

    return [$user, $workspace, $site, $organization];
}

function makeAuditContent(Workspace $workspace, ClientSite $site, string $title): Content
{
    return Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => $title,
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'manual',
    ]);
}
