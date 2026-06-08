<?php

require_once __DIR__ . '/../../Support/LocalizationAgentTestHelpers.php';

use App\Agents\ContentRefresh\ContentRefreshAgent;
use App\Agents\InternalLinking\InternalLinkingAgent;
use App\Enums\DraftType;
use App\Events\Agents\ContentPublished;
use App\Models\AgentRun;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('does nothing when automatic recommendation generation is disabled for the site', function () {
    [$owner, $workspace, $site] = makeAutoModeWorkspace('auto-mode-off');

    $site->update([
        'automation_settings' => [
            'automatic_recommendation_generation_enabled' => false,
            'smart_suggestions_enabled' => true,
            'automatic_refresh_draft_creation_enabled' => true,
            'localization_checks_enabled' => true,
        ],
    ]);

    $content = makeAutoModeContent($workspace, $site, $owner, [
        'title' => 'Auto mode disabled article',
        'primary_keyword' => 'auto mode disabled article',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'created_by_user_id' => $owner->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Auto mode disabled brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'progress' => 0,
    ]);

    Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'Auto mode disabled draft',
        'output_type' => 'kb_article',
        'draft_type' => DraftType::ORIGINAL->value,
        'language' => 'en',
        'content_html' => '<p>Editorial workflow checklist appears here.</p>',
    ]);

    expect(AgentRun::query()->count())->toBe(0);
});

it('creates a refresh draft automatically for high urgency content when auto mode is enabled', function () {
    [$owner, $workspace, $site] = makeAutoModeWorkspace('auto-refresh-draft');
    $content = makeAutoModeContent($workspace, $site, $owner, [
        'title' => 'Auto refresh article',
        'primary_keyword' => 'auto refresh article',
        'status' => 'published',
        'publish_status' => 'published',
        'seo_title' => null,
        'seo_meta_description' => null,
        'seo_h1' => null,
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => ContentVersion::TYPE_DRAFT,
        'parent_version_id' => null,
        'body' => '<p>Current content body for refresh drafting.</p>',
        'meta' => ['excerpt' => 'Refresh snapshot'],
        'source' => ContentVersion::SOURCE_ARGUSLY,
        'created_by' => $owner->id,
    ]);

    $content->update([
        'current_version_id' => $version->id,
    ]);
    $version->forceFill([
        'created_at' => now()->subDays(420),
        'updated_at' => now()->subDays(420),
    ])->saveQuietly();
    $content->forceFill([
        'created_at' => now()->subDays(420),
        'updated_at' => now()->subDays(420),
    ])->saveQuietly();

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'created_by_user_id' => $owner->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Auto refresh brief',
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
        'title' => 'Auto refresh draft',
        'output_type' => 'kb_article',
        'draft_type' => DraftType::ORIGINAL->value,
        'language' => 'en',
        'content_html' => '<p>Short body with no links.</p>',
    ]);

    $site->update([
        'automation_settings' => [
            'automatic_recommendation_generation_enabled' => true,
            'smart_suggestions_enabled' => true,
            'automatic_refresh_draft_creation_enabled' => true,
            'localization_checks_enabled' => true,
        ],
    ]);

    $draftCountBefore = Draft::query()->count();
    $currentVersionIdBefore = (string) $content->current_version_id;

    ContentPublished::dispatch(
        contentId: (string) $content->id,
        draftId: (string) $draft->id,
        source: 'test.auto_mode',
    );

    $refreshRun = AgentRun::query()
        ->where('agent_key', ContentRefreshAgent::KEY)
        ->where('content_id', (string) $content->id)
        ->latest('created_at')
        ->firstOrFail();

    $autoDraft = Draft::query()
        ->where('meta->refresh->agent_run_id', (string) $refreshRun->id)
        ->where('id', '!=', (string) $draft->id)
        ->latest('created_at')
        ->firstOrFail();

    $content->refresh();

    expect(Draft::query()->count())->toBeGreaterThan($draftCountBefore)
        ->and((string) $autoDraft->content_id)->toBe((string) $content->id)
        ->and((string) data_get($refreshRun->output_payload, 'raw_payload.auto_actions.refresh_draft_created.draft_id'))->toBe((string) $autoDraft->id)
        ->and((string) $content->current_version_id)->toBe($currentVersionIdBefore);
});

it('keeps unsafe live edits gated even when smart suggestions run automatically', function () {
    [$owner, $workspace, $site] = makeAutoModeWorkspace('auto-mode-guardrails');

    $site->update([
        'automation_settings' => [
            'automatic_recommendation_generation_enabled' => true,
            'smart_suggestions_enabled' => true,
            'automatic_refresh_draft_creation_enabled' => false,
            'localization_checks_enabled' => true,
        ],
    ]);

    $content = makeAutoModeContent($workspace, $site, $owner, [
        'title' => 'Guarded internal linking article',
        'primary_keyword' => 'guarded internal linking article',
    ]);

    Content::query()->create([
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
        'published_url' => 'https://auto-mode-guardrails.example.com/blog/editorial-workflow-checklist',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'created_by_user_id' => $owner->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Guardrails brief',
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
        'status' => 'ready',
        'title' => 'Guardrails draft',
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

    $draft->refresh();

    expect((string) $draft->content_html)->not->toContain('<a ')
        ->and(data_get($run->output_payload, 'suggestions.0.target_title'))->toBe('Editorial workflow checklist');
});

it('reuses the same refresh draft when the same published snapshot is analyzed twice', function () {
    [$owner, $workspace, $site] = makeAutoModeWorkspace('auto-refresh-draft-dedupe');
    $content = makeAutoModeContent($workspace, $site, $owner, [
        'title' => 'Auto refresh dedupe article',
        'primary_keyword' => 'auto refresh dedupe article',
        'status' => 'published',
        'publish_status' => 'published',
        'seo_title' => null,
        'seo_meta_description' => null,
        'seo_h1' => null,
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => ContentVersion::TYPE_DRAFT,
        'parent_version_id' => null,
        'body' => '<p>Current content body for refresh drafting.</p>',
        'meta' => ['excerpt' => 'Refresh snapshot'],
        'source' => ContentVersion::SOURCE_ARGUSLY,
        'created_by' => $owner->id,
    ]);

    $content->update([
        'current_version_id' => $version->id,
    ]);
    $version->forceFill([
        'created_at' => now()->subDays(420),
        'updated_at' => now()->subDays(420),
    ])->saveQuietly();
    $content->forceFill([
        'created_at' => now()->subDays(420),
        'updated_at' => now()->subDays(420),
    ])->saveQuietly();

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'created_by_user_id' => $owner->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Auto refresh dedupe brief',
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
        'title' => 'Auto refresh dedupe draft',
        'output_type' => 'kb_article',
        'draft_type' => DraftType::ORIGINAL->value,
        'language' => 'en',
        'content_html' => '<p>Short body with no links.</p>',
    ]);

    $site->update([
        'automation_settings' => [
            'automatic_recommendation_generation_enabled' => true,
            'smart_suggestions_enabled' => true,
            'automatic_refresh_draft_creation_enabled' => true,
            'localization_checks_enabled' => true,
        ],
    ]);

    ContentPublished::dispatch(
        contentId: (string) $content->id,
        draftId: (string) $draft->id,
        source: 'test.auto_mode.first',
    );

    $firstRefreshRun = AgentRun::query()
        ->where('agent_key', ContentRefreshAgent::KEY)
        ->where('content_id', (string) $content->id)
        ->latest('created_at')
        ->firstOrFail();

    $autoDraft = Draft::query()
        ->where('meta->refresh->agent_run_id', (string) $firstRefreshRun->id)
        ->where('id', '!=', (string) $draft->id)
        ->latest('created_at')
        ->firstOrFail();

    ContentPublished::dispatch(
        contentId: (string) $content->id,
        draftId: (string) $draft->id,
        source: 'test.auto_mode.second',
    );

    $secondRefreshRun = AgentRun::query()
        ->where('agent_key', ContentRefreshAgent::KEY)
        ->where('content_id', (string) $content->id)
        ->latest('created_at')
        ->firstOrFail();

    $refreshDrafts = Draft::query()
        ->where('content_id', (string) $content->id)
        ->where('meta->refresh->created_from_content_id', (string) $content->id)
        ->where('id', '!=', (string) $draft->id)
        ->get();

    expect($refreshDrafts)->toHaveCount(1)
        ->and((string) data_get($secondRefreshRun->output_payload, 'raw_payload.auto_actions.refresh_draft_reused.draft_id'))->toBe((string) $autoDraft->id);
});

function makeAutoModeWorkspace(string $prefix): array
{
    $organization = Organization::query()->create([
        'name' => 'Auto Mode Org',
        'slug' => $prefix . '-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Auto Mode BV',
        'billing_address_line1' => 'Teststraat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Auto Mode Workspace',
        'organization_id' => $organization->id,
        'enabled_content_languages' => ['en', 'nl'],
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Auto Mode Site',
        'site_url' => 'https://' . $prefix . '.example.com',
        'base_url' => 'https://' . $prefix . '.example.com',
        'allowed_domains' => [$prefix . '.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => $prefix . '-plan'],
        [
            'name' => 'Auto Mode Plan',
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
        'name' => 'Auto Mode Owner',
        'email' => $prefix . '+owner@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$owner, $workspace, $site];
}

function makeAutoModeContent(Workspace $workspace, ClientSite $site, User $owner, array $overrides = []): Content
{
    return Content::query()->create(array_merge([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Auto mode article',
        'language' => 'en',
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'draft',
        'source' => 'manual',
        'primary_keyword' => 'auto mode article',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ], $overrides));
}
