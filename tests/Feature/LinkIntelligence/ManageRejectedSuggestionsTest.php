<?php

use App\Models\ClientSite;
use App\Models\Brief;
use App\Models\Draft;
use App\Models\LinkSuggestion;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeDraftForSuggestion(Workspace $workspace, ClientSite $site, string $title): Draft
{
    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'status' => 'done',
        'progress' => 1,
        'title' => $title . ' Brief',
        'language' => 'nl',
        'output_type' => 'kb_article',
    ]);

    return Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => $title,
        'output_type' => 'kb_article',
        'content_html' => '<p>content</p>',
    ]);
}

it('allows removing a single rejected suggestion', function () {
    $organization = Organization::create([
        'name' => 'Link Org',
        'slug' => 'link-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Link Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);
    $workspace = Workspace::create(['name' => 'W1', 'organization_id' => $organization->id]);
    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Site',
        'site_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
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

    $source = makeDraftForSuggestion($workspace, $site, 'Source');
    $target = makeDraftForSuggestion($workspace, $site, 'Target');

    $suggestion = LinkSuggestion::query()->create([
        'source_article_id' => $source->id,
        'target_article_id' => $target->id,
        'source_workspace_id' => $workspace->id,
        'target_workspace_id' => $workspace->id,
        'source_client_site_id' => $site->id,
        'target_client_site_id' => $site->id,
        'similarity_score' => 0.90,
        'shared_entities' => ['ai'],
        'intent_match_score' => 1.00,
        'audience_overlap_score' => 0.80,
        'suggested_anchor_variants' => ['AI strategy'],
        'suggested_placement' => 'inline',
        'status' => 'rejected',
    ]);

    $user = User::create([
        'name' => 'Editor',
        'email' => 'editor+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'editor',
        'approved_at' => now(),
        'active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('app.drafts.link-suggestions.delete', [$source, $suggestion]))
        ->assertRedirect();

    $this->assertDatabaseMissing('link_suggestions', [
        'id' => $suggestion->id,
    ]);
});

it('clear rejected removes only rejected suggestions', function () {
    $organization = Organization::create([
        'name' => 'Link Org',
        'slug' => 'link-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Link Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);
    $workspace = Workspace::create(['name' => 'W1', 'organization_id' => $organization->id]);
    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Site',
        'site_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
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

    $source = makeDraftForSuggestion($workspace, $site, 'Source');
    $target = makeDraftForSuggestion($workspace, $site, 'Target');

    $rejected = LinkSuggestion::query()->create([
        'source_article_id' => $source->id,
        'target_article_id' => $target->id,
        'source_workspace_id' => $workspace->id,
        'target_workspace_id' => $workspace->id,
        'source_client_site_id' => $site->id,
        'target_client_site_id' => $site->id,
        'similarity_score' => 0.91,
        'shared_entities' => ['ai'],
        'intent_match_score' => 1.00,
        'audience_overlap_score' => 0.80,
        'suggested_anchor_variants' => ['AI strategy'],
        'suggested_placement' => 'inline',
        'status' => 'rejected',
    ]);

    $suggested = LinkSuggestion::query()->create([
        'source_article_id' => $source->id,
        'target_article_id' => $target->id,
        'source_workspace_id' => $workspace->id,
        'target_workspace_id' => $workspace->id,
        'source_client_site_id' => $site->id,
        'target_client_site_id' => $site->id,
        'similarity_score' => 0.93,
        'shared_entities' => ['ai'],
        'intent_match_score' => 1.00,
        'audience_overlap_score' => 0.82,
        'suggested_anchor_variants' => ['AI strategy 2'],
        'suggested_placement' => 'inline',
        'status' => 'suggested',
    ]);

    $owner = User::create([
        'name' => 'Owner',
        'email' => 'owner+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $this->actingAs($owner)
        ->post(route('app.drafts.link-suggestions.clear-rejected', $source))
        ->assertRedirect();

    $this->assertDatabaseMissing('link_suggestions', [
        'id' => $rejected->id,
    ]);
    $this->assertDatabaseHas('link_suggestions', [
        'id' => $suggested->id,
        'status' => 'suggested',
    ]);
});
