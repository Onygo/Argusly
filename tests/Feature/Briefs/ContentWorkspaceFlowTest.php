<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Draft;
use App\Models\DraftComparison;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use App\Services\DraftComparison\DraftComparisonModelCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeContentWorkspaceContext(string $prefix = 'content-workspace'): array
{
    $organization = Organization::query()->create([
        'name' => 'Content Workspace Org',
        'slug' => $prefix . '-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Content Workspace BV',
        'billing_address_line1' => 'Teststraat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Content Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Content Workspace Site',
        'site_url' => 'https://content-workspace.example.com',
        'allowed_domains' => ['content-workspace.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'content-workspace-test-plan'],
        [
            'name' => 'Content Workspace Plan',
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
        'name' => 'Content Workspace User',
        'email' => $prefix . '+' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$organization, $workspace, $site, $user];
}

it('renders content index and create content page', function () {
    [, , , $user] = makeContentWorkspaceContext();

    $this->actingAs($user)
        ->get(route('app.content.index'))
        ->assertOk()
        ->assertSee('Content');

    $this->actingAs($user)
        ->get(route('app.content.create'))
        ->assertOk()
        ->assertSee('Create content')
        ->assertSee('Brief settings')
        ->assertSee('Complete briefing');
});

it('stores content brief from create content and redirects to content workspace', function () {
    [, , $site, $user] = makeContentWorkspaceContext('content-workspace-store');

    $response = $this->actingAs($user)->post(route('app.content.create.store'), [
        'site_id' => (string) $site->id,
        'title' => 'Content workspace article',
        'content_type' => 'blog',
        'language' => 'en',
        'primary_keyword' => 'content workflow',
        'desired_length_min' => 900,
        'desired_length_max' => 1200,
    ]);

    $brief = Brief::query()->where('title', 'Content workspace article')->first();

    expect($brief)->not->toBeNull();
    $response->assertRedirect(route('app.content.workspace.show', $brief));
});

it('stores content from a complete pasted briefing', function () {
    [, , $site, $user] = makeContentWorkspaceContext('content-workspace-complete-briefing');

    $briefing = <<<'BRIEF'
Content Briefing
Working title

The Biggest AI Bottleneck Isn't Talent. It's Your Marketing Operating System.
Primary keyword

AI marketing operating system
Secondary keywords
agentic marketing
AI content operations
AI governance marketing
Target audience
CMOs
Marketing Directors
Core message

The biggest bottleneck is the absence of an operational system that enables marketers and AI to work together.
Angle

React to the growing narrative that companies need to hire AI specialists.
Key discussion points
1. AI is becoming a commodity
2. Hiring more AI talent does not scale
BRIEF;

    $response = $this->actingAs($user)->post(route('app.content.create.store'), [
        'site_id' => (string) $site->id,
        'content_type' => 'blog',
        'language' => 'en',
        'complete_briefing' => $briefing,
        'desired_length_min' => 900,
        'desired_length_max' => 1200,
    ]);

    $brief = Brief::query()
        ->where('title', "The Biggest AI Bottleneck Isn't Talent. It's Your Marketing Operating System.")
        ->first();

    expect($brief)->not->toBeNull()
        ->and((string) $brief->primary_keyword)->toBe('AI marketing operating system')
        ->and($brief->secondary_keywords)->toContain('agentic marketing')
        ->and((string) $brief->target_audience)->toContain('CMOs')
        ->and((string) data_get($brief->client_refs, 'complete_briefing.raw'))->toContain('Content Briefing');

    $response->assertRedirect(route('app.content.workspace.show', $brief));
});

it('derives the title from an inline title in a pasted complete briefing', function () {
    [, , $site, $user] = makeContentWorkspaceContext('content-workspace-inline-briefing-title');

    $briefing = <<<'BRIEF'
Title: Stop Chasing More Traffic. Start Fixing What Happens After the Click.

Alternatieve SEO-title:

Why More Traffic Isn't Your Biggest Growth Problem Anymore

Doel

Laten zien dat bedrijven zich vaak blindstaren op meer verkeer, terwijl de grootste groeikans juist ligt in het verbeteren van conversie.
BRIEF;

    $response = $this->actingAs($user)->post(route('app.content.create.store'), [
        'site_id' => (string) $site->id,
        'content_type' => 'blog',
        'language' => 'en',
        'complete_briefing' => $briefing,
    ]);

    $brief = Brief::query()
        ->where('title', 'Stop Chasing More Traffic. Start Fixing What Happens After the Click.')
        ->first();

    expect($brief)->not->toBeNull()
        ->and((string) data_get($brief->client_refs, 'complete_briefing.derived.title'))->toBe('Stop Chasing More Traffic. Start Fixing What Happens After the Click.');

    $response->assertRedirect(route('app.content.workspace.show', $brief));
});

it('renders content workspace primary actions and recent compare runs', function () {
    [, , $site, $user] = makeContentWorkspaceContext('content-workspace-actions');

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Workspace action brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'progress' => 0,
    ]);

    DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'mode' => 'single',
        'status' => DraftComparison::STATUS_COMPLETED,
        'items_total' => 1,
        'items_done' => 1,
        'estimated_credit_cost' => 10,
        'credits_used' => 10,
    ]);

    $response = $this->actingAs($user)->get(route('app.content.workspace.show', $brief));

    $response->assertOk()
        ->assertSee('Content workspace')
        ->assertSee('Generate draft')
        ->assertSee('Start comparison')
        ->assertSee('Edit brief')
        ->assertSee('Recent compare runs');
});

it('keeps draft generation CTA working from content workspace routes', function () {
    Queue::fake();

    [, , $site, $user] = makeContentWorkspaceContext('content-workspace-generate');

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Workspace generate brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'progress' => 0,
    ]);

    $response = $this->actingAs($user)->post(route('app.content.workspace.drafts.generate', $brief), [
        'requested_max_output_tokens' => 8000,
    ]);

    $response->assertRedirect();

    $draft = Draft::query()->where('brief_id', $brief->id)->first();
    expect($draft)->not->toBeNull();
    expect((string) $draft->status)->not->toBe('');
});

it('supports nested content workspace compare routes and redirects legacy setup route', function () {
    Queue::fake();

    [, , $site, $user] = makeContentWorkspaceContext('content-workspace-compare');

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 200,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Workspace compare brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'progress' => 0,
    ]);

    $this->actingAs($user)
        ->get(route('app.briefs.compare.setup', $brief))
        ->assertRedirect(route('app.content.workspace.compare.setup', $brief));

    $this->actingAs($user)
        ->get(route('app.content.workspace.compare.setup', $brief))
        ->assertOk()
        ->assertSee('Compare AI Drafts');

    $selected = collect(app(DraftComparisonModelCatalog::class)->options())
        ->take(2)
        ->pluck('key')
        ->values()
        ->all();

    $response = $this->actingAs($user)->post(route('app.content.workspace.compare.store', $brief), [
        'mode' => 'compare_two',
        'model_keys' => $selected,
        'requested_max_output_tokens' => 10000,
    ]);

    $comparison = DraftComparison::query()->where('brief_id', $brief->id)->latest('created_at')->first();

    expect($comparison)->not->toBeNull();
    $response->assertRedirect(route('app.content.workspace.compare.show', [$brief, $comparison]));
});

it('keeps content workspace authorization boundaries and brief edit access', function () {
    [, , $siteA, $userA] = makeContentWorkspaceContext('content-workspace-auth-a');
    [, , $siteB] = makeContentWorkspaceContext('content-workspace-auth-b');

    $brief = Brief::query()->withoutGlobalScopes()->create([
        'client_site_id' => $siteB->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Foreign workspace brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'progress' => 0,
    ]);

    $this->actingAs($userA)
        ->get(route('app.content.workspace.show', $brief))
        ->assertStatus(404);

    $ownBrief = Brief::query()->create([
        'client_site_id' => $siteA->id,
        'created_by_user_id' => $userA->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Own workspace brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'progress' => 0,
    ]);

    $this->actingAs($userA)
        ->get(route('app.content.workspace.brief.edit', $ownBrief))
        ->assertOk()
        ->assertSee('Edit brief');
});
