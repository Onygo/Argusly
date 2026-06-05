<?php

use App\Jobs\GenerateDraftJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Persona;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeContentBriefFlowContext(string $prefix = 'content-brief'): array
{
    $organization = Organization::query()->create([
        'name' => 'Content Brief Org ' . Str::random(4),
        'slug' => $prefix . '-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Content Brief BV',
        'billing_address_line1' => 'Teststraat 12',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Content Brief Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Content Brief Site',
        'site_url' => 'https://content-brief.example.com',
        'allowed_domains' => ['content-brief.example.com'],
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
        'name' => 'Content Brief User',
        'email' => $prefix . '+' . Str::random(5) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$organization, $workspace, $site, $user];
}

function contentBriefUpdatePayload(string $siteId, array $overrides = []): array
{
    return array_merge([
        'site_id' => $siteId,
        'title' => 'Updated brief title',
        'content_type' => 'blog',
        'language' => 'en',
        'primary_keyword' => 'updated keyword',
        'target_audience' => 'Marketing ops',
        'tone_of_voice' => 'Practical and direct',
        'unique_angle' => 'Use governance examples',
        'key_points' => "Point one\nPoint two",
        'call_to_action' => 'Book a demo',
        'desired_length_min' => 1000,
        'desired_length_max' => 1400,
        'notes' => 'Constraints: avoid hype. Sources: docs only.',
        'status' => 'draft',
    ], $overrides);
}

it('allows editing the minimal brief created from new content', function () {
    [, , $site, $user] = makeContentBriefFlowContext();

    $this->actingAs($user)->post(route('app.content.store'), [
        'title' => 'Minimal content brief',
        'primary_keyword' => 'baseline keyword',
        'site_id' => (string) $site->id,
    ])->assertRedirect();

    $content = Content::query()->where('title', 'Minimal content brief')->firstOrFail();
    $brief = Brief::query()->where('content_id', $content->id)->firstOrFail();

    $response = $this->actingAs($user)->put(route('app.briefs.update', $brief), [
        'site_id' => (string) $site->id,
        'title' => 'Updated brief title',
        'content_type' => 'blog',
        'language' => 'en',
        'primary_keyword' => 'updated keyword',
        'target_audience' => 'Marketing ops',
        'tone_of_voice' => 'Practical and direct',
        'unique_angle' => 'Use governance examples',
        'key_points' => "Point one\nPoint two",
        'call_to_action' => 'Book a demo',
        'desired_length_min' => 1000,
        'desired_length_max' => 1400,
        'notes' => 'Constraints: avoid hype. Sources: docs only.',
        'status' => 'draft',
    ]);

    $response->assertRedirect(route('app.content.workspace.brief.edit', $brief));
    $response->assertSessionHas('status');

    $brief->refresh();

    expect((string) $brief->title)->toBe('Updated brief title')
        ->and((string) $brief->primary_keyword)->toBe('updated keyword')
        ->and((string) $brief->tone_of_voice)->toBe('Practical and direct')
        ->and((string) $brief->unique_angle)->toBe('Use governance examples')
        ->and((string) $brief->call_to_action)->toBe('Book a demo')
        ->and((int) $brief->desired_length_min)->toBe(1000)
        ->and((int) $brief->desired_length_max)->toBe(1400)
        ->and((array) $brief->key_points)->toBe(['Point one', 'Point two']);
});

it('allows changing the publishing site while content is still in brief phase', function () {
    [, $workspace, $site, $user] = makeContentBriefFlowContext('content-brief-site-switch');
    $secondSite = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Second Brief Site',
        'site_url' => 'https://second-brief.example.com',
        'allowed_domains' => ['second-brief.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $this->actingAs($user)->post(route('app.content.store'), [
        'title' => 'Switchable brief',
        'primary_keyword' => 'switchable keyword',
        'site_id' => (string) $site->id,
    ])->assertRedirect();

    $content = Content::query()->where('title', 'Switchable brief')->firstOrFail();
    $brief = Brief::query()->where('content_id', $content->id)->firstOrFail();

    $this->actingAs($user)
        ->put(route('app.briefs.update', $brief), contentBriefUpdatePayload((string) $secondSite->id))
        ->assertRedirect(route('app.content.workspace.brief.edit', $brief));

    expect((string) $brief->fresh()->client_site_id)->toBe((string) $secondSite->id)
        ->and((string) $content->fresh()->client_site_id)->toBe((string) $secondSite->id);
});

it('prevents changing the publishing site after a draft exists', function () {
    [, $workspace, $site, $user] = makeContentBriefFlowContext('content-brief-site-lock');
    $secondSite = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Locked Brief Site',
        'site_url' => 'https://locked-brief.example.com',
        'allowed_domains' => ['locked-brief.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $this->actingAs($user)->post(route('app.content.store'), [
        'title' => 'Locked brief',
        'primary_keyword' => 'locked keyword',
        'site_id' => (string) $site->id,
    ])->assertRedirect();

    $content = Content::query()->where('title', 'Locked brief')->firstOrFail();
    $brief = Brief::query()->where('content_id', $content->id)->firstOrFail();
    Draft::query()->create([
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Locked draft',
        'output_type' => 'article',
        'language' => 'en',
        'content_html' => '<p>Generated draft</p>',
    ]);

    $this->actingAs($user)
        ->put(route('app.briefs.update', $brief), contentBriefUpdatePayload((string) $secondSite->id))
        ->assertSessionHasErrors('site_id');

    expect((string) $brief->fresh()->client_site_id)->toBe((string) $site->id)
        ->and((string) $content->fresh()->client_site_id)->toBe((string) $site->id);
});

it('applies smart brand defaults when creating content', function () {
    [, $workspace, $site, $user] = makeContentBriefFlowContext('content-smart-defaults');

    $brandVoice = \App\Models\BrandVoice::query()->create([
        'workspace_id' => $workspace->id,
        'organization_id' => $workspace->organization_id,
        'name' => 'Default Brand Voice',
        'default_language' => 'en',
        'is_default' => true,
    ]);

    $buyerPersona = Persona::query()->create([
        'organization_id' => $workspace->organization_id,
        'type' => Persona::TYPE_BUYER,
        'name' => 'Operations Manager Olivia',
        'source_type' => 'manual',
        'profile_data' => ['role' => 'Operations Manager'],
        'status' => Persona::STATUS_APPROVED,
    ]);

    $teamMember = TeamMember::query()->create([
        'organization_id' => $workspace->organization_id,
        'name' => 'Alex Architect',
        'role' => 'CTO',
        'profile_data' => ['use_as_writing_persona' => true],
        'status' => TeamMember::STATUS_APPROVED,
        'is_active' => true,
    ]);

    $this->actingAs($user)->post(route('app.content.store'), [
        'title' => 'Smart defaults article',
        'primary_keyword' => 'smart defaults',
        'site_id' => (string) $site->id,
    ])->assertRedirect();

    $content = Content::query()->where('title', 'Smart defaults article')->firstOrFail();
    $brief = Brief::query()->where('content_id', $content->id)->firstOrFail();

    expect((string) $content->brand_voice_id)->toBe((string) $brandVoice->id)
        ->and((int) $content->buyer_persona_id)->toBe((int) $buyerPersona->id)
        ->and((int) $content->team_member_id)->toBe((int) $teamMember->id)
        ->and((string) $brief->audience)->toBe('Operations Manager Olivia (Operations Manager)')
        ->and((string) data_get($brief->client_refs, 'brand_voice_id'))->toBe((string) $brandVoice->id)
        ->and((int) data_get($brief->client_refs, 'buyer_persona_id'))->toBe((int) $buyerPersona->id)
        ->and((int) data_get($brief->client_refs, 'team_member_id'))->toBe((int) $teamMember->id);
});

it('returns not found when editing a brief outside user organization', function () {
    // Global organization scope filters out resources from other organizations
    // returning 404 instead of 403 for improved security (no existence disclosure)
    [, , $siteA, $userA] = makeContentBriefFlowContext('brief-user-a');
    [, , $siteB] = makeContentBriefFlowContext('brief-user-b');

    $foreignBrief = Brief::query()->withoutGlobalScopes()->create([
        'client_site_id' => $siteB->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'progress' => 0,
        'title' => 'Foreign brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $this->actingAs($userA)
        ->put(route('app.briefs.update', $foreignBrief), [
            'site_id' => (string) $siteA->id,
            'title' => 'Should not update',
            'content_type' => 'blog',
            'language' => 'en',
            'status' => 'draft',
        ])
        ->assertStatus(404);
});

it('can save brief edits and queue generation with the existing draft job pipeline', function () {
    Queue::fake();
    [, , $site, $user] = makeContentBriefFlowContext();
    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'progress' => 0,
        'title' => 'Queue from edit',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'desired_length_min' => 900,
        'desired_length_max' => 1200,
    ]);

    $response = $this->actingAs($user)->put(route('app.briefs.update', $brief), [
        'site_id' => (string) $site->id,
        'title' => 'Queue from edit updated',
        'content_type' => 'blog',
        'language' => 'en',
        'primary_keyword' => 'pipeline keyword',
        'desired_length_min' => 900,
        'desired_length_max' => 1200,
        'generate_draft' => '1',
        'requested_max_output_tokens' => 8000,
    ]);

    $response->assertRedirect();

    $brief->refresh();
    $draft = Draft::query()->where('brief_id', $brief->id)->first();

    expect($draft)->not->toBeNull()
        ->and((string) $brief->title)->toBe('Queue from edit updated')
        ->and((string) $draft?->status)->toBe('queued');

    Queue::assertPushed(GenerateDraftJob::class, 1);
    Queue::assertPushed(GenerateDraftJob::class, function (GenerateDraftJob $job) use ($draft): bool {
        return (string) $job->draftId === (string) $draft?->id;
    });
});

it('is idempotent when generate draft is clicked twice quickly', function () {
    Queue::fake();
    [, , $site, $user] = makeContentBriefFlowContext();
    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'progress' => 0,
        'title' => 'Double click brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $this->actingAs($user)->post(route('app.briefs.generate-draft', $brief))->assertRedirect();
    $this->actingAs($user)->post(route('app.briefs.generate-draft', $brief))->assertRedirect();

    $brief->refresh();
    $drafts = Draft::query()->where('brief_id', $brief->id)->get();

    expect($drafts)->toHaveCount(1)
        ->and((string) $brief->status)->toBe('done')
        ->and((string) $drafts->first()->status)->toBe('queued');

    Queue::assertPushed(GenerateDraftJob::class, 1);
});
