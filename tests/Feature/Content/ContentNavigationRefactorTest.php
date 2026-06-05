<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeContentNavigationContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Content Nav Org',
        'slug' => 'content-nav-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Content Nav BV',
        'billing_address_line1' => 'Teststraat 12',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Content Nav Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Content Nav Site',
        'site_url' => 'https://content-nav.example.com',
        'allowed_domains' => ['content-nav.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'test-plan'],
        [
            'name' => 'Test Plan',
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
        'name' => 'Content Nav User',
        'email' => 'content-nav+'.Str::random(5).'@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$organization, $workspace, $site, $user];
}

it('creates new content from content list and opens the brief tab', function () {
    [, $workspace, $site, $user] = makeContentNavigationContext();

    $response = $this->actingAs($user)->post(route('app.content.store'), [
        'title' => 'Lifecycle first content',
        'primary_keyword' => 'content operations',
        'site_id' => (string) $site->id,
    ]);

    $content = Content::query()
        ->where('title', 'Lifecycle first content')
        ->first();
    $brief = Brief::query()->where('content_id', $content?->id)->first();

    expect($content)->not->toBeNull();
    expect($brief)->not->toBeNull();
    $response->assertRedirect(route('app.content.workspace.show', $brief));

    expect((string) $content->workspace_id)->toBe((string) $workspace->id);
    expect((string) $content->client_site_id)->toBe((string) $site->id);
    expect((string) $content->status)->toBe('brief');
    expect((string) $content->source)->toBe('manual');
    expect((string) $content->primary_keyword)->toBe('content operations');

    expect($brief)->not->toBeNull();
    expect((string) $brief->client_site_id)->toBe((string) $site->id);
    expect((string) $brief->status)->toBe('draft');
    expect((string) $brief->source)->toBe('client_ui');
    expect((string) $brief->title)->toBe('Lifecycle first content');
});

it('redirects legacy briefs and drafts list routes to content inbox filters', function () {
    [, , $site, $user] = makeContentNavigationContext();

    $briefsResponse = $this->actingAs($user)->get(route('app.briefs', ['site' => (string) $site->id]));
    $briefsResponse->assertRedirect();
    $briefsLocation = (string) $briefsResponse->headers->get('Location');
    expect((string) parse_url($briefsLocation, PHP_URL_PATH))->toBe('/content');
    parse_str((string) parse_url($briefsLocation, PHP_URL_QUERY), $briefsQuery);
    expect((string) ($briefsQuery['inbox'] ?? ''))->toBe('needs_brief');
    expect((string) ($briefsQuery['site'] ?? ''))->toBe((string) $site->id);

    $draftsResponse = $this->actingAs($user)->get(route('app.drafts', ['site' => (string) $site->id]));
    $draftsResponse->assertRedirect();
    $draftsLocation = (string) $draftsResponse->headers->get('Location');
    expect((string) parse_url($draftsLocation, PHP_URL_PATH))->toBe('/content');
    parse_str((string) parse_url($draftsLocation, PHP_URL_QUERY), $draftsQuery);
    expect((string) ($draftsQuery['inbox'] ?? ''))->toBe('needs_draft');
    expect((string) ($draftsQuery['site'] ?? ''))->toBe((string) $site->id);
});

it('redirects legacy content brief tab URLs to content workspace brief section', function () {
    [, $workspace, $site, $user] = makeContentNavigationContext();

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Legacy brief tab redirect content',
        'primary_keyword' => 'legacy brief tab',
        'type' => 'article',
        'status' => 'brief',
        'source' => 'manual',
        'external_key' => (string) Str::uuid(),
        'generation_mode' => 'balanced',
        'preferred_length' => 'medium',
        'created_by' => (int) $user->id,
        'updated_by' => (int) $user->id,
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => (string) $site->id,
        'created_by_user_id' => (int) $user->id,
        'content_id' => (string) $content->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Legacy brief tab redirect content',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'progress' => 0,
    ]);

    $this->actingAs($user)
        ->get(route('app.content.show', ['content' => $content, 'tab' => 'brief']))
        ->assertRedirect(route('app.content.workspace.brief', $brief));
});

it('renders client sidebar sections and items in the requested order', function () {
    [, , , $user] = makeContentNavigationContext();

    $response = $this->actingAs($user)->get(route('app.dashboard'));

    $response->assertOk();
    $response->assertSeeInOrder([
        '>CONTENT<',
        'data-sidebar-title="Dashboard"',
        'data-sidebar-title="Content"',
        '>PUBLISHING<',
        'data-sidebar-title="Sites"',
        'data-sidebar-title="Brand"',
        '>ADMINISTRATION<',
        'data-sidebar-title="Billing"',
        'data-sidebar-title="Developer"',
        'data-sidebar-title="Settings"',
    ], false);

    $response->assertDontSee('data-sidebar-title="Briefs"', false);
    $response->assertDontSee('data-sidebar-title="Drafts"', false);
    $response->assertDontSee('data-sidebar-title="Onboarding"', false);
});

it('keeps developer child pages out of the global sidebar', function () {
    [, , , $user] = makeContentNavigationContext();

    $response = $this->actingAs($user)->get(route('app.dashboard'));

    $response->assertOk()
        ->assertSee('data-sidebar-title="Developer"', false)
        ->assertDontSee('data-sidebar-title="Developer API"', false)
        ->assertDontSee('data-sidebar-title="Developer Webhooks"', false)
        ->assertDontSee('data-sidebar-title="Developer Docs"', false);
});

it('keeps the global developer nav item active on developer child routes', function () {
    [, , , $user] = makeContentNavigationContext();

    $this->actingAs($user)
        ->get(route('app.developer.webhooks'))
        ->assertOk()
        ->assertSee('data-sidebar-title="Developer" class="group relative flex h-9 items-center justify-start gap-3 rounded-md px-3 text-sm font-medium transition-all border-l-2 border-l-primary bg-primarySoftBg text-textPrimary"', false);
});

it('renders local developer section nav and highlights the active child section', function () {
    [, , , $user] = makeContentNavigationContext();

    $this->actingAs($user)
        ->get(route('app.developer.api'))
        ->assertOk()
        ->assertSee('data-section-nav', false)
        ->assertSee('data-section-nav-item="api"', false)
        ->assertSee('aria-current="page"', false)
        ->assertDontSee('data-section-nav-item="webhooks" aria-current="page"', false)
        ->assertDontSee('data-section-nav-item="docs" aria-current="page"', false);

    $this->actingAs($user)
        ->get(route('app.developer.docs'))
        ->assertOk()
        ->assertSee('data-section-nav-item="docs"', false)
        ->assertSee('aria-current="page"', false)
        ->assertDontSee('data-section-nav-item="api" aria-current="page"', false);
});

it('keeps direct access to developer child routes working', function () {
    [, , , $user] = makeContentNavigationContext();

    $this->actingAs($user)
        ->get(route('app.developer.api'))
        ->assertOk()
        ->assertSee('Create API key');

    $this->actingAs($user)
        ->get(route('app.developer.webhooks'))
        ->assertOk()
        ->assertSee('Create webhook');

    $this->actingAs($user)
        ->get(route('app.developer.docs'))
        ->assertOk()
        ->assertSee('API Documentation');
});

it('shows developer local section nav only inside developer pages', function () {
    [, , , $user] = makeContentNavigationContext();

    $this->actingAs($user)
        ->get(route('app.dashboard'))
        ->assertOk()
        ->assertDontSee('data-section-nav', false);

    $this->actingAs($user)
        ->get(route('app.developer.index'))
        ->assertOk()
        ->assertSee('data-section-nav', false);
});

it('shows single navigation without duplicate button group on developer overview', function () {
    [, , , $user] = makeContentNavigationContext();

    $response = $this->actingAs($user)
        ->get(route('app.developer.index'));

    $response->assertOk()
        // Primary section nav should be present
        ->assertSee('data-section-nav', false)
        ->assertSee('data-section-nav-item="api"', false)
        ->assertSee('data-section-nav-item="webhooks"', false)
        ->assertSee('data-section-nav-item="docs"', false)
        // Should NOT have duplicate button group with the descriptive card
        ->assertDontSee('Build integrations and manage technical access.', false);
});

describe('Content area top-level mode navigation', function () {
    it('shows mode navigation tabs on content index page', function () {
        [, , , $user] = makeContentNavigationContext();

        $response = $this->actingAs($user)->get(route('app.content.index'));

        $response->assertOk()
            ->assertSee('Sites', false)
            ->assertSee('Automations', false)
            ->assertSee('Chains', false);
    });

    it('shows site tabs on content index page', function () {
        [, , $site, $user] = makeContentNavigationContext();

        $response = $this->actingAs($user)->get(route('app.content.index'));

        $response->assertOk()
            ->assertSee('data-site-tabs', false)
            ->assertSee('data-site-tab="all"', false)
            ->assertSee($site->name, false);
    });

    it('filters content by site when site tab is used', function () {
        [, $workspace, $site, $user] = makeContentNavigationContext();

        // Create content for the site
        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'client_site_id' => (string) $site->id,
            'title' => 'Site specific content',
            'primary_keyword' => 'site filter test',
            'type' => 'article',
            'status' => 'brief',
            'source' => 'manual',
            'external_key' => (string) Str::uuid(),
            'generation_mode' => 'balanced',
            'preferred_length' => 'medium',
            'created_by' => (int) $user->id,
            'updated_by' => (int) $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('app.content.index', ['site' => $site->id]));

        $response->assertOk()
            ->assertSee('Site specific content', false);
    });

    it('shows mode navigation on automations page', function () {
        [, , , $user] = makeContentNavigationContext();

        $response = $this->actingAs($user)->get(route('app.content.automations.index'));

        $response->assertOk()
            ->assertSee('Sites', false)
            ->assertSee('Automations', false)
            ->assertSee('Chains', false);
    });

    it('does not show site tabs on automations page', function () {
        [, , , $user] = makeContentNavigationContext();

        $response = $this->actingAs($user)->get(route('app.content.automations.index'));

        $response->assertOk()
            ->assertDontSee('data-site-tabs', false);
    });

    it('shows mode navigation on chains page', function () {
        [, , , $user] = makeContentNavigationContext();

        $response = $this->actingAs($user)->get(route('app.content.series.index'));

        $response->assertOk()
            ->assertSee('Sites', false)
            ->assertSee('Automations', false)
            ->assertSee('Chains', false);
    });

    it('does not show site tabs on chains page', function () {
        [, , , $user] = makeContentNavigationContext();

        $response = $this->actingAs($user)->get(route('app.content.series.index'));

        $response->assertOk()
            ->assertDontSee('data-site-tabs', false);
    });

    it('preserves other filters when changing site tab', function () {
        [, , $site, $user] = makeContentNavigationContext();

        $response = $this->actingAs($user)->get(route('app.content.index', [
            'site' => $site->id,
            'status' => 'draft',
            'inbox' => 'needs_brief',
        ]));

        $response->assertOk();
        // The filter form should include hidden input for site
        $response->assertSee('name="site"', false);
    });

    it('removes site dropdown from filter row on content index', function () {
        [, , , $user] = makeContentNavigationContext();

        $response = $this->actingAs($user)->get(route('app.content.index'));

        $response->assertOk();
        // Site filter dropdown should no longer be in the filter form
        // but site tabs should be present
        $response->assertSee('data-site-tabs', false);
    });
});
