<?php

require_once __DIR__ . '/../../Support/LocalizationAgentTestHelpers.php';

use App\Agents\ContentRefresh\ContentRefreshAgent;
use App\Agents\InternalLinking\InternalLinkingAgent;
use App\Agents\Localization\LocalizationAgent;
use App\Enums\DraftType;
use App\Events\Agents\ContentPublished;
use App\Events\Agents\TranslationCompleted;
use App\Models\AgentRun;
use App\Models\AgentWorkflowRun;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('automatically runs internal linking after a draft is generated', function () {
    [$owner, $workspace, $site] = makeEventDrivenAgentWorkspace('event-draft-generated');

    $sourceContent = makeEventDrivenContent($workspace, $site, $owner, [
        'title' => 'Draft generation source article',
        'primary_keyword' => 'draft generation source article',
    ]);

    createEventDrivenPublishedTarget($workspace, $site, $owner, 'https://event-draft-generated.example.com/blog/editorial-workflow-checklist');

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $sourceContent->id,
        'created_by_user_id' => $owner->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Generated draft brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'progress' => 0,
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $sourceContent->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'Generated draft',
        'output_type' => 'kb_article',
        'draft_type' => DraftType::ORIGINAL->value,
        'language' => 'en',
        'content_html' => '<p>This generated draft references editorial workflow checklist when planning the next revision.</p>',
    ]);

    $run = AgentRun::query()
        ->where('agent_key', InternalLinkingAgent::KEY)
        ->where('draft_id', (string) $draft->id)
        ->latest('created_at')
        ->firstOrFail();
    $workflowRun = AgentWorkflowRun::query()
        ->where('workflow_key', 'workflow.draft_post_processing')
        ->where('draft_id', (string) $draft->id)
        ->latest('created_at')
        ->firstOrFail();

    expect($run->trigger_type)->toBe('event')
        ->and($run->trigger_source)->toBe('event.draft_generated')
        ->and($run->draft_id)->toBe($draft->id)
        ->and(data_get($run->output_payload, 'suggestions.0.target_title'))->toBe('Editorial workflow checklist')
        ->and($workflowRun->status->value)->toBe('warning')
        ->and(data_get($workflowRun->output_payload, 'steps.0.step_key'))->toBe('internal_linking')
        ->and(data_get($workflowRun->output_payload, 'steps.1.step_key'))->toBe('localization');

    $this->actingAs($owner)
        ->get(route('app.drafts.show', [
            'draft' => $draft,
            'tab' => 'intelligence',
        ]))
        ->assertOk()
        ->assertSee('Internal links')
        ->assertSee('Success')
        ->assertSee('1 item');
});

it('runs localization checks after translation completion and shows the persisted result on the draft', function () {
    [$owner, $workspace, $site] = makeLocalizationAgentContext('event-translation-completed', true);

    $sourceContent = makeLocalizedContent($workspace, $site, $owner, 'English source article', 'en');
    $sourceDraft = makeLocalizedDraft(
        $sourceContent,
        $site,
        'English source draft',
        'en',
        '<p>English source body.</p>',
        ['status' => 'draft']
    );

    $translatedContent = makeLocalizedContent($workspace, $site, $owner, 'Nederlandse versie', 'nl', [
        'translation_source_content_id' => $sourceContent->id,
        'translation_source_locale' => 'en',
        'is_source_locale' => false,
    ]);

    $translatedDraft = makeLocalizedDraft(
        $translatedContent,
        $site,
        'Nederlandse handleiding',
        'en',
        '<p>Dit is een Nederlandse gids en deze uitleg is voor teams in Nederland.</p>',
        [
            'status' => 'draft',
            'draft_type' => DraftType::TRANSLATION->value,
            'source_draft_id' => $sourceDraft->id,
            'translation_source_language' => 'en',
            'seo_meta_description' => '',
        ]
    );

    TranslationCompleted::dispatch(
        sourceDraftId: (string) $sourceDraft->id,
        translatedDraftId: (string) $translatedDraft->id,
        sourceContentId: (string) $sourceContent->id,
        translatedContentId: (string) $translatedContent->id,
        targetLocale: 'nl',
    );

    $run = AgentRun::query()
        ->where('agent_key', LocalizationAgent::KEY)
        ->where('draft_id', (string) $translatedDraft->id)
        ->latest('created_at')
        ->firstOrFail();

    expect($run->trigger_type)->toBe('event')
        ->and($run->trigger_source)->toBe('event.translation_completed')
        ->and($run->draft_id)->toBe($translatedDraft->id);

    $this->actingAs($owner)
        ->get(route('app.drafts.show', [
            'draft' => $translatedDraft,
            'tab' => 'intelligence',
        ]))
        ->assertOk()
        ->assertSee('Localization')
        ->assertSee('Warning');
});

it('runs refresh analysis after content is published and shows the persisted result on the content page', function () {
    [$owner, $workspace, $site] = makeEventDrivenAgentWorkspace('event-content-published');

    $content = makeEventDrivenContent($workspace, $site, $owner, [
        'title' => 'Stale published article',
        'primary_keyword' => 'stale published article',
        'status' => 'published',
        'publish_status' => 'published',
        'seo_title' => null,
        'seo_meta_description' => null,
        'seo_h1' => null,
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'created_by_user_id' => $owner->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Stale article brief',
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
        'title' => 'Stale article draft',
        'output_type' => 'kb_article',
        'draft_type' => DraftType::ORIGINAL->value,
        'language' => 'en',
        'content_html' => '<p>Short body with no links.</p>',
    ]);

    ContentPublished::dispatch(
        contentId: (string) $content->id,
        draftId: (string) $draft->id,
        source: 'test.content_published',
    );

    $run = AgentRun::query()
        ->where('agent_key', ContentRefreshAgent::KEY)
        ->where('content_id', (string) $content->id)
        ->latest('created_at')
        ->firstOrFail();
    $workflowRun = AgentWorkflowRun::query()
        ->where('workflow_key', 'workflow.published_content_optimization')
        ->where('content_id', (string) $content->id)
        ->latest('created_at')
        ->firstOrFail();

    expect($run->trigger_type)->toBe('event')
        ->and($run->trigger_source)->toBe('event.content_published')
        ->and($run->content_id)->toBe($content->id)
        ->and((int) data_get($run->output_payload, 'raw_payload.refresh_score'))->toBeGreaterThan(0)
        ->and(data_get($workflowRun->output_payload, 'steps.0.step_key'))->toBe('refresh_recommendations')
        ->and(data_get($workflowRun->output_payload, 'steps.1.step_key'))->toBe('localization');

    $this->actingAs($owner)
        ->get(route('app.content.show', [
            'content' => $content,
            'tab' => 'overview',
            'insight' => 'refresh',
        ]))
        ->assertOk()
        ->assertSee('Content Health')
        ->assertSee('AI findings')
        ->assertSee('Freshness')
        ->assertSee('Missing SEO structure')
        ->assertSee('Create refresh draft');
});

function makeEventDrivenAgentWorkspace(string $prefix): array
{
    $organization = Organization::query()->create([
        'name' => 'Event Agent Org',
        'slug' => $prefix . '-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Event Agent BV',
        'billing_address_line1' => 'Teststraat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Event Agent Workspace',
        'organization_id' => $organization->id,
        'enabled_content_languages' => ['en', 'nl', 'de'],
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Event Agent Site',
        'site_url' => 'https://' . $prefix . '.example.com',
        'base_url' => 'https://' . $prefix . '.example.com',
        'allowed_domains' => [$prefix . '.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => $prefix . '-plan'],
        [
            'name' => 'Event Agent Plan',
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
        'name' => 'Event Agent Owner',
        'email' => $prefix . '+owner@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$owner, $workspace, $site];
}

function makeEventDrivenContent(Workspace $workspace, ClientSite $site, User $owner, array $overrides = []): Content
{
    return Content::query()->create(array_merge([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Event driven content',
        'language' => 'en',
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'draft',
        'source' => 'manual',
        'primary_keyword' => 'event driven content',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ], $overrides));
}

function createEventDrivenPublishedTarget(Workspace $workspace, ClientSite $site, User $owner, string $url): Content
{
    $target = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Editorial workflow checklist',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'manual',
        'primary_keyword' => 'editorial workflow checklist',
        'published_url' => $url,
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
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
