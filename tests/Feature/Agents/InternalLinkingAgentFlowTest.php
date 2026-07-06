<?php

use App\Agents\InternalLinking\InternalLinkingAgent;
use App\Enums\DraftType;
use App\Models\AgentRun;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\ContentRevision;
use App\Models\ContentVersion;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Draft;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('allows an authorized user to run internal linking for a draft and renders the persisted result', function () {
    [$owner, $viewer, $site, $sourceContent, $draft] = makeInternalLinkingFeatureContext('feature-draft-links');

    $target = createInternalLinkingPublishedTarget($sourceContent, $site, 'https://feature-draft-links.example.com/blog/editorial-workflow-checklist');

    $draft->update([
        'content_html' => '<p>This draft references editorial workflow checklist when planning the next revision.</p>',
    ]);

    $this->actingAs($owner)
        ->post(route('app.drafts.internal-linking.run', $draft), ['tab' => 'draft'])
        ->assertRedirect();

    $run = AgentRun::query()->sole();

    expect($run->agent_key)->toBe(InternalLinkingAgent::KEY)
        ->and($run->trigger_source)->toBe('app.drafts.internal_linking')
        ->and($run->draft_id)->toBe($draft->id)
        ->and($run->content_id)->toBe($sourceContent->id)
        ->and(data_get($run->output_payload, 'suggestions.0.target_content_id'))->toBe($target->id);

    $this->actingAs($owner)
        ->get(route('app.drafts.show', [
            'draft' => $draft,
            'tab' => 'intelligence',
            'internal_linking_run' => $run->id,
        ]))
        ->assertOk()
        ->assertSee('Internal links')
        ->assertSee('Success')
        ->assertSee('1 item');
});

it('prevents unauthorized users from running internal linking for a draft', function () {
    [$owner, $viewer, $site, $sourceContent, $draft] = makeInternalLinkingFeatureContext('feature-draft-links-forbidden');

    Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $sourceContent->workspace_id,
        'client_site_id' => $site->id,
        'title' => 'Editorial workflow checklist',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'manual',
        'primary_keyword' => 'editorial workflow checklist',
        'published_url' => 'https://feature-draft-links-forbidden.example.com/blog/editorial-workflow-checklist',
    ]);

    $draft->update([
        'content_html' => '<p>This draft references editorial workflow checklist when planning the next revision.</p>',
    ]);

    $this->actingAs($viewer)
        ->post(route('app.drafts.internal-linking.run', $draft))
        ->assertForbidden();

    expect(AgentRun::query()->count())->toBe(0);
});

it('applies an internal link suggestion to the draft body safely', function () {
    [$owner, $viewer, $site, $sourceContent, $draft] = makeInternalLinkingFeatureContext('feature-draft-apply');

    $target = createInternalLinkingPublishedTarget($sourceContent, $site, 'https://feature-draft-apply.example.com/blog/editorial-workflow-checklist');

    $draft->update([
        'content_html' => '<p>This draft references editorial workflow checklist when planning the next revision.</p>',
    ]);

    $this->actingAs($owner)
        ->post(route('app.drafts.internal-linking.run', $draft), ['tab' => 'draft'])
        ->assertRedirect();

    $run = AgentRun::query()->sole();

    $this->actingAs($owner)
        ->post(route('app.drafts.internal-linking.apply', $draft), [
            'agent_run_id' => $run->id,
            'suggestion_index' => 0,
            'tab' => 'draft',
        ])
        ->assertRedirect();

    $draft->refresh();
    $run->refresh();

    expect((string) $draft->content_html)->toContain('<a href="' . $target->published_url . '">editorial workflow checklist</a>')
        ->and(data_get($run->output_payload, 'suggestions.0.applied_resource_type'))->toBe('draft')
        ->and(data_get($run->output_payload, 'suggestions.0.applied_at'))->not->toBeNull();
});

it('skips candidates without a verified same-site live url', function () {
    [$owner, $viewer, $site, $sourceContent, $draft] = makeInternalLinkingFeatureContext('feature-draft-safe-targets');

    Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $sourceContent->workspace_id,
        'client_site_id' => $site->id,
        'title' => 'Editorial workflow checklist',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'manual',
        'primary_keyword' => 'editorial workflow checklist',
        'seo_canonical' => 'https://external.example.com/blog/editorial-workflow-checklist',
    ]);

    $draft->update([
        'content_html' => '<p>This draft references editorial workflow checklist when planning the next revision.</p>',
    ]);

    $this->actingAs($owner)
        ->post(route('app.drafts.internal-linking.run', $draft), ['tab' => 'draft'])
        ->assertRedirect();

    $run = AgentRun::query()->sole();

    expect(data_get($run->output_payload, 'suggestions', []))->toBe([]);
});

it('applies an internal link suggestion to content by creating a new refresh revision', function () {
    [$owner, $viewer, $site, $sourceContent, $draft] = makeInternalLinkingFeatureContext('feature-content-apply');

    $target = createInternalLinkingPublishedTarget($sourceContent, $site, 'https://feature-content-apply.example.com/blog/editorial-workflow-checklist');

    $sourceContent->update([
        'status' => 'published',
        'publish_status' => 'published',
    ]);

    $revision = ContentRevision::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $sourceContent->id,
        'draft_id' => $draft->id,
        'revision_number' => 1,
        'label' => 'R1',
        'content_html' => '<p>This content references editorial workflow checklist when planning the next revision.</p>',
        'meta' => [],
        'is_active' => true,
        'created_by_user_id' => $owner->id,
    ]);
    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $sourceContent->id,
        'type' => ContentVersion::TYPE_DRAFT,
        'parent_version_id' => null,
        'body' => '<p>This content references editorial workflow checklist when planning the next revision.</p>',
        'meta' => [],
        'source' => ContentVersion::SOURCE_ARGUSLY,
        'created_by' => $owner->id,
    ]);
    $sourceContent->update([
        'current_revision_id' => $revision->id,
        'current_version_id' => $version->id,
    ]);

    $this->actingAs($owner)
        ->post(route('app.content.internal-linking.run', $sourceContent), ['tab' => 'overview'])
        ->assertRedirect();

    $run = AgentRun::query()->sole();
    $previousRevisionId = (string) $revision->id;
    $previousVersionId = (string) $version->id;
    $previousRevisionHtml = (string) $revision->content_html;
    $previousVersionBody = (string) $version->body;

    $this->actingAs($owner)
        ->post(route('app.content.internal-linking.apply', $sourceContent), [
            'agent_run_id' => $run->id,
            'suggestion_index' => 0,
            'tab' => 'overview',
        ])
        ->assertRedirect();

    $sourceContent->refresh()->load('currentRevision', 'currentVersion');
    $run->refresh();
    $revision->refresh();
    $version->refresh();

    expect((string) $sourceContent->current_revision_id)->not->toBe($previousRevisionId)
        ->and((string) $sourceContent->current_version_id)->not->toBe($previousVersionId)
        ->and((string) $sourceContent->currentRevision?->content_html)->toContain('<a href="' . $target->published_url . '">editorial workflow checklist</a>')
        ->and((string) $sourceContent->currentVersion?->body)->toContain('<a href="' . $target->published_url . '">editorial workflow checklist</a>')
        ->and((string) $revision->content_html)->toBe($previousRevisionHtml)
        ->and((string) $version->body)->toBe($previousVersionBody)
        ->and(data_get($run->output_payload, 'suggestions.0.applied_resource_type'))->toBe('content_revision');
});

it('applies an event-triggered internal link suggestion from the content detail sidebar', function () {
    [$owner, $viewer, $site, $sourceContent, $draft] = makeInternalLinkingFeatureContext('feature-content-event-apply');

    $target = createInternalLinkingPublishedTarget($sourceContent, $site, 'https://feature-content-event-apply.example.com/blog/editorial-workflow-checklist');

    $revision = ContentRevision::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $sourceContent->id,
        'draft_id' => $draft->id,
        'revision_number' => 1,
        'label' => 'R1',
        'content_html' => '<p>This content references editorial workflow checklist when planning the next revision.</p>',
        'meta' => [],
        'is_active' => true,
        'created_by_user_id' => $owner->id,
    ]);
    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $sourceContent->id,
        'type' => ContentVersion::TYPE_DRAFT,
        'parent_version_id' => null,
        'body' => '<p>This content references editorial workflow checklist when planning the next revision.</p>',
        'meta' => [],
        'source' => ContentVersion::SOURCE_ARGUSLY,
        'created_by' => $owner->id,
    ]);
    $sourceContent->update([
        'current_revision_id' => $revision->id,
        'current_version_id' => $version->id,
    ]);

    $run = AgentRun::query()->create([
        'id' => (string) Str::uuid(),
        'agent_key' => InternalLinkingAgent::KEY,
        'trigger_type' => 'event',
        'trigger_source' => 'agents.content_published',
        'organization_id' => $owner->organization_id,
        'workspace_id' => $sourceContent->workspace_id,
        'site_id' => $site->id,
        'content_id' => $sourceContent->id,
        'user_id' => $owner->id,
        'input_payload' => [],
        'output_payload' => [
            'suggestions' => [
                [
                    'anchor_text' => 'editorial workflow checklist',
                    'target_title' => 'Editorial workflow checklist',
                    'target_url' => $target->published_url,
                    'target_content_id' => $target->id,
                    'reason' => 'Matches same-site topic overlap.',
                ],
            ],
        ],
        'summary' => 'Found 1 suggested internal link.',
        'status' => \App\Agents\Support\AgentRunStatus::SUCCESS->value,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $this->actingAs($owner)
        ->post(route('app.content.internal-linking.apply', $sourceContent), [
            'agent_run_id' => $run->id,
            'suggestion_index' => 0,
            'tab' => 'draft',
        ])
        ->assertRedirect(route('app.content.show', [
            'content' => $sourceContent,
            'tab' => 'draft',
            'internal_linking_run' => $run->id,
        ]));

    $sourceContent->refresh()->load('currentRevision');
    $run->refresh();

    expect((string) $sourceContent->currentRevision?->content_html)
        ->toContain('<a href="' . $target->published_url . '">editorial workflow checklist</a>')
        ->and(data_get($run->output_payload, 'suggestions.0.applied_resource_type'))->toBe('content_revision');
});

it('renders every internal link suggestion in the content detail sidebar', function () {
    [$owner, , $site, $sourceContent] = makeInternalLinkingFeatureContext('feature-content-sidebar-links');

    $run = AgentRun::query()->create([
        'id' => (string) Str::uuid(),
        'agent_key' => InternalLinkingAgent::KEY,
        'trigger_type' => 'event',
        'trigger_source' => 'agents.content_published',
        'organization_id' => $owner->organization_id,
        'workspace_id' => $sourceContent->workspace_id,
        'site_id' => $site->id,
        'content_id' => $sourceContent->id,
        'user_id' => $owner->id,
        'input_payload' => [],
        'output_payload' => [
            'suggestions' => [
                [
                    'anchor_text' => 'answer engine visibility',
                    'target_title' => 'Answer engine visibility basics',
                    'target_url' => 'https://feature-content-sidebar-links.example.com/blog/answer-engine-visibility',
                    'reason' => 'Matches heading overlap.',
                ],
                [
                    'anchor_text' => 'marketing intelligence',
                    'target_title' => 'Marketing intelligence workflows',
                    'target_url' => 'https://feature-content-sidebar-links.example.com/blog/marketing-intelligence',
                    'reason' => 'Matches same-site topic overlap.',
                ],
                [
                    'anchor_text' => 'autonomous workflows',
                    'target_title' => 'Autonomous workflow planning',
                    'target_url' => 'https://feature-content-sidebar-links.example.com/blog/autonomous-workflows',
                    'reason' => 'Matches adjacent workflow intent.',
                ],
            ],
        ],
        'summary' => 'Found 3 suggested internal links for EN on Feature Internal Linking Site.',
        'status' => \App\Agents\Support\AgentRunStatus::SUCCESS->value,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('app.content.show', [
            'content' => $sourceContent,
            'tab' => 'overview',
            'insight' => 'links',
            'internal_linking_run' => $run->id,
        ]))
        ->assertOk()
        ->assertSee('Found 3 suggested internal links')
        ->assertSee('Answer engine visibility basics')
        ->assertSee('Marketing intelligence workflows')
        ->assertSee('Autonomous workflow planning');
});

function makeInternalLinkingFeatureContext(string $prefix = 'feature-internal-linking'): array
{
    $organization = Organization::query()->create([
        'name' => 'Feature Internal Linking Org',
        'slug' => $prefix . '-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Feature Internal Linking BV',
        'billing_address_line1' => 'Teststraat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Feature Internal Linking Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Feature Internal Linking Site',
        'site_url' => 'https://' . $prefix . '.example.com',
        'allowed_domains' => [$prefix . '.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => $prefix . '-plan'],
        [
            'name' => 'Feature Internal Linking Plan',
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

    $owner = User::query()->create([
        'name' => 'Feature Internal Linking Owner',
        'email' => $prefix . '+owner@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $viewer = User::query()->create([
        'name' => 'Feature Internal Linking Viewer',
        'email' => $prefix . '+viewer@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'viewer',
        'active' => true,
        'approved_at' => now(),
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Feature internal linking content',
        'language' => 'en',
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'draft',
        'source' => 'manual',
        'primary_keyword' => 'feature internal linking content',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'created_by_user_id' => $owner->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Feature internal linking brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'progress' => 0,
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Feature internal linking draft',
        'output_type' => 'kb_article',
        'draft_type' => DraftType::ORIGINAL->value,
        'language' => 'en',
        'seo_meta_description' => '',
        'content_html' => '<p>Short body.</p>',
    ]);

    return [$owner, $viewer, $site, $content, $draft];
}

function createInternalLinkingPublishedTarget(Content $sourceContent, ClientSite $site, string $url): Content
{
    $target = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $sourceContent->workspace_id,
        'client_site_id' => $site->id,
        'title' => 'Editorial workflow checklist',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'manual',
        'primary_keyword' => 'editorial workflow checklist',
        'published_url' => $url,
    ]);

    ContentPublication::query()->create([
        'content_id' => $target->id,
        'client_site_id' => $site->id,
        'locale' => 'en',
        'provider' => ContentPublication::PROVIDER_WORDPRESS,
        'remote_id' => (string) Str::uuid(),
        'remote_type' => 'post',
        'remote_url' => $url,
        'remote_status' => ContentPublication::REMOTE_PUBLISHED,
        'delivery_status' => ContentPublication::STATUS_DELIVERED,
        'last_verified_at' => now(),
        'last_delivered_at' => now(),
    ]);

    return $target;
}
