<?php

use App\Agents\ContentRefresh\ContentRefreshAgent;
use App\Enums\DraftType;
use App\Models\AgentRun;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
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

it('renders refresh recommendations with score and next steps in the content ui', function () {
    [$owner, $site, $content, $draft] = makeContentRefreshAgentContext('content-refresh-render');

    $content->update([
        'seo_title' => null,
        'seo_meta_description' => null,
        'seo_h1' => null,
    ]);
    $draft->update([
        'content_html' => '<p>Short body with no links.</p>',
    ]);

    $this->actingAs($owner)
        ->post(route('app.content.refresh-recommendations.run', $content))
        ->assertRedirect();

    $run = AgentRun::query()->sole();

    $this->actingAs($owner)
        ->get(route('app.content.show', [
            'content' => $content,
            'tab' => 'overview',
            'refresh_recommendations_run' => $run->id,
        ]))
        ->assertOk()
        ->assertSee('Refresh score')
        ->assertSee('Create refresh draft')
        ->assertSee('Missing SEO structure')
        ->assertSee('Suggested Actions');
});

it('creates a refresh draft from the current content state without overwriting live content', function () {
    [$owner, $site, $content, $draft] = makeContentRefreshAgentContext('content-refresh-create');

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
        'status' => 'published',
        'publish_status' => 'published',
    ]);

    $this->actingAs($owner)
        ->post(route('app.content.refresh-recommendations.run', $content))
        ->assertRedirect();

    $run = AgentRun::query()->sole();
    $draftCountBefore = Draft::query()->count();
    $currentVersionIdBefore = (string) $content->current_version_id;

    $this->actingAs($owner)
        ->post(route('app.content.refresh-recommendations.create-draft', $content), [
            'agent_run_id' => $run->id,
        ])
        ->assertRedirect();

    $content->refresh();
    $refreshDraft = Draft::query()
        ->where('meta->refresh->agent_run_id', (string) $run->id)
        ->firstOrFail();

    expect(Draft::query()->count())->toBe($draftCountBefore + 1)
        ->and((string) $refreshDraft->content_id)->toBe((string) $content->id)
        ->and((string) data_get($refreshDraft->meta, 'refresh.agent_run_id'))->toBe((string) $run->id)
        ->and((int) data_get($refreshDraft->meta, 'refresh.refresh_score'))->toBe((int) data_get($run->output_payload, 'raw_payload.refresh_score'))
        ->and((string) $refreshDraft->content_html)->toBe('<p>Current content body for refresh drafting.</p>')
        ->and((string) $content->current_version_id)->toBe($currentVersionIdBefore);
});

it('preserves translation lineage when creating a refresh draft for translated content', function () {
    [$owner, $site, $content, $draft] = makeContentRefreshAgentContext('content-refresh-translation');

    $sourceContent = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $content->workspace_id,
        'client_site_id' => $site->id,
        'title' => 'English source article',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'manual',
        'primary_keyword' => 'english source article',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    $sourceBrief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $sourceContent->id,
        'created_by_user_id' => $owner->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'English source brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'progress' => 0,
    ]);

    $sourceDraft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $sourceBrief->id,
        'content_id' => $sourceContent->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'English source draft',
        'output_type' => 'kb_article',
        'draft_type' => DraftType::ORIGINAL->value,
        'language' => 'en',
        'content_html' => '<p>English source body.</p>',
    ]);

    $content->update([
        'title' => 'Nederlandse versie',
        'language' => 'nl',
        'status' => 'published',
        'publish_status' => 'published',
        'translation_source_content_id' => $sourceContent->id,
        'translation_source_locale' => 'en',
        'is_source_locale' => false,
    ]);

    $draft->update([
        'draft_type' => DraftType::TRANSLATION->value,
        'language' => 'nl',
        'source_draft_id' => $sourceDraft->id,
        'translation_source_language' => 'en',
        'content_html' => '<p>Dit is de bestaande Nederlandse versie.</p>',
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => ContentVersion::TYPE_DRAFT,
        'parent_version_id' => null,
        'body' => '<p>Bijgewerkte Nederlandse body voor refresh drafting.</p>',
        'meta' => ['excerpt' => 'Translated refresh snapshot'],
        'source' => ContentVersion::SOURCE_ARGUSLY,
        'created_by' => $owner->id,
    ]);

    $content->update([
        'current_version_id' => $version->id,
    ]);

    $this->actingAs($owner)
        ->post(route('app.content.refresh-recommendations.run', $content))
        ->assertRedirect();

    $run = AgentRun::query()->sole();

    $this->actingAs($owner)
        ->post(route('app.content.refresh-recommendations.create-draft', $content), [
            'agent_run_id' => $run->id,
        ])
        ->assertRedirect();

    $refreshDraft = Draft::query()
        ->where('meta->refresh->agent_run_id', (string) $run->id)
        ->where('id', '!=', (string) $draft->id)
        ->firstOrFail();

    expect((string) $refreshDraft->draft_type->value)->toBe(DraftType::TRANSLATION->value)
        ->and((string) $refreshDraft->source_draft_id)->toBe((string) $sourceDraft->id)
        ->and((string) $refreshDraft->translation_source_language)->toBe('en')
        ->and((string) $refreshDraft->content_html)->toBe('<p>Bijgewerkte Nederlandse body voor refresh drafting.</p>');
});

function makeContentRefreshAgentContext(string $prefix = 'content-refresh-agent'): array
{
    $organization = Organization::query()->create([
        'name' => 'Content Refresh Org',
        'slug' => $prefix . '-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Content Refresh BV',
        'billing_address_line1' => 'Teststraat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Content Refresh Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Content Refresh Site',
        'site_url' => 'https://' . $prefix . '.example.com',
        'allowed_domains' => [$prefix . '.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => $prefix . '-plan'],
        [
            'name' => 'Content Refresh Plan',
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
        'name' => 'Content Refresh Owner',
        'email' => $prefix . '+owner@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Content refresh article',
        'language' => 'en',
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'draft',
        'source' => 'manual',
        'primary_keyword' => 'content refresh article',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'created_by_user_id' => $owner->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Content refresh brief',
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
        'title' => 'Content refresh draft',
        'output_type' => 'kb_article',
        'draft_type' => DraftType::ORIGINAL->value,
        'language' => 'en',
        'content_html' => '<p>Short body.</p>',
    ]);

    return [$owner, $site, $content, $draft];
}
