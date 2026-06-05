<?php

use App\Enums\SeoAuditSuggestionState;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentSeo;
use App\Models\ContentVersion;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Brief;
use App\Models\SeoAudit;
use App\Models\SeoAuditFixSuggestion;
use App\Models\SeoAuditIssue;
use App\Models\SeoAuditPage;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('loads seo audit run detail dashboard for authorized user', function () {
    $ctx = makeSeoAuditRunDashboardContext();

    $response = $this->actingAs($ctx['user'])
        ->get(route('app.sites.seo-audits.show', [$ctx['site'], $ctx['audit']]));

    $response->assertOk();
    $response->assertSee('SEO Health Summary');
    $response->assertSee('Priority Fixes');
    $response->assertSee('AI SEO Fix');
    $response->assertSee('Issues Overview');
    $response->assertSee('Page Level Table');
    $response->assertSee('Audit history');
});

it('computes summary counts from underlying audit data', function () {
    $ctx = makeSeoAuditRunDashboardContext();

    $response = $this->actingAs($ctx['user'])
        ->get(route('app.sites.seo-audits.show', [$ctx['site'], $ctx['audit']]) . '?scope=all');

    $response->assertOk();

    $dashboard = $response->viewData('dashboard');

    expect((int) data_get($dashboard, 'summary.pages_analysed_total'))->toBe(3)
        ->and((int) data_get($dashboard, 'summary.publishlayer_pages_count'))->toBe(2)
        ->and((int) data_get($dashboard, 'summary.other_pages_count'))->toBe(1)
        ->and((int) data_get($dashboard, 'summary.issues.error'))->toBe(1)
        ->and((int) data_get($dashboard, 'summary.issues.warning'))->toBe(1)
        ->and((int) data_get($dashboard, 'summary.issues.improvement'))->toBe(1)
        ->and((float) data_get($dashboard, 'summary.seo_health_score'))->toBeGreaterThan(0.0);
});

it('aligns audit index issue counters with default detail scope', function () {
    $ctx = makeSeoAuditRunDashboardContext();

    $response = $this->actingAs($ctx['user'])
        ->get(route('app.sites.seo-audits.index', [$ctx['site']]));

    $response->assertOk();

    $indexAudit = collect($response->viewData('audits'))->firstWhere('id', $ctx['audit']->id);
    expect((int) data_get($indexAudit, 'overview_issue_counts.error'))->toBe(0)
        ->and((int) data_get($indexAudit, 'overview_issue_counts.warning'))->toBe(1)
        ->and((int) data_get($indexAudit, 'overview_issue_counts.info'))->toBe(0);

    $detailResponse = $this->actingAs($ctx['user'])
        ->get(route('app.sites.seo-audits.show', [$ctx['site'], $ctx['audit']]));

    $detailResponse->assertOk();
    $dashboard = $detailResponse->viewData('dashboard');

    expect((int) data_get($dashboard, 'summary.issues.error'))->toBe((int) data_get($indexAudit, 'overview_issue_counts.error'))
        ->and((int) data_get($dashboard, 'summary.issues.warning'))->toBe((int) data_get($indexAudit, 'overview_issue_counts.warning'))
        ->and((int) data_get($dashboard, 'summary.issues.improvement'))->toBe((int) data_get($indexAudit, 'overview_issue_counts.info'));
});

it('filters scope tabs for publishlayer, other, and all', function () {
    $ctx = makeSeoAuditRunDashboardContext();

    $publishlayer = $this->actingAs($ctx['user'])
        ->get(route('app.sites.seo-audits.show', [$ctx['site'], $ctx['audit']]) . '?scope=publishlayer');
    $publishlayer->assertOk();

    $publishlayerRows = collect(data_get($publishlayer->viewData('dashboard'), 'page_table_rows', []));
    expect($publishlayerRows)->toHaveCount(2);
    expect($publishlayerRows->every(fn (array $row): bool => (bool) $row['is_publishlayer']))->toBeTrue();

    $other = $this->actingAs($ctx['user'])
        ->get(route('app.sites.seo-audits.show', [$ctx['site'], $ctx['audit']]) . '?scope=other');
    $other->assertOk();

    $otherRows = collect(data_get($other->viewData('dashboard'), 'page_table_rows', []));
    expect($otherRows)->toHaveCount(1);
    expect($otherRows->every(fn (array $row): bool => ! (bool) $row['is_publishlayer']))->toBeTrue();

    $all = $this->actingAs($ctx['user'])
        ->get(route('app.sites.seo-audits.show', [$ctx['site'], $ctx['audit']]) . '?scope=all');
    $all->assertOk();

    $allRows = collect(data_get($all->viewData('dashboard'), 'page_table_rows', []));
    expect($allRows)->toHaveCount(3);
});

it('marks plugin-dependent seo fixes as recommendation only when wordpress seo support is unavailable', function () {
    $ctx = makeSeoAuditRunDashboardContext();

    $response = $this->actingAs($ctx['user'])
        ->get(route('app.sites.seo-audits.show', [$ctx['site'], $ctx['audit']]) . '?scope=publishlayer');

    $response->assertOk();
    $rows = collect(data_get($response->viewData('dashboard'), 'ai_panel.rows', []));
    $metaDescriptionRow = $rows->firstWhere('issue_code', 'meta_description_missing');

    expect($metaDescriptionRow)->not->toBeNull();
    expect((string) data_get($metaDescriptionRow, 'wordpress_sync_label'))->toBe('Recommendation only');
    expect((string) data_get($metaDescriptionRow, 'wordpress_sync_note'))->toContain('Requires supported SEO plugin');
});

it('exposes field-level seo sync capability states in ai seo fix ui', function () {
    $ctx = makeSeoAuditRunDashboardContext();

    $response = $this->actingAs($ctx['user'])
        ->get(route('app.sites.seo-audits.show', [$ctx['site'], $ctx['audit']]) . '?scope=publishlayer');

    $response->assertOk()
        ->assertSee('Connector SEO capabilities')
        ->assertSee('Requires supported SEO plugin')
        ->assertSee('Can sync to WordPress');

    $fields = collect(data_get($response->viewData('dashboard'), 'ai_panel.seo_capability.fields', []))
        ->keyBy('key');

    expect((string) data_get($fields->get('seo_title'), 'status'))->toBe('sync');
    expect((string) data_get($fields->get('seo_h1'), 'status'))->toBe('sync');
    expect((string) data_get($fields->get('seo_meta_description'), 'status'))->toBe('requires_provider');
    expect((string) data_get($fields->get('seo_canonical'), 'status'))->toBe('requires_provider');
    expect((string) data_get($fields->get('seo_twitter_title'), 'status'))->toBe('requires_provider');
});

it('marks twitter and og fields as syncable when provider mapping supports them', function () {
    $ctx = makeSeoAuditRunDashboardContext();

    $ctx['site']->update([
        'seo_provider' => 'rankmath',
        'supports_meta_title' => true,
        'supports_meta_description' => true,
        'supports_canonical' => true,
        'supports_og_tags' => true,
    ]);

    $response = $this->actingAs($ctx['user'])
        ->get(route('app.sites.seo-audits.show', [$ctx['site'], $ctx['audit']]) . '?scope=publishlayer');

    $response->assertOk()->assertSee('Provider: Rank Math');

    $fields = collect(data_get($response->viewData('dashboard'), 'ai_panel.seo_capability.fields', []))
        ->keyBy('key');

    expect((string) data_get($fields->get('seo_og_title'), 'status'))->toBe('sync');
    expect((string) data_get($fields->get('seo_og_description'), 'status'))->toBe('sync');
    expect((string) data_get($fields->get('seo_twitter_title'), 'status'))->toBe('sync');
    expect((string) data_get($fields->get('seo_twitter_description'), 'status'))->toBe('sync');
});

it('applies ai suggestion to draft metadata without publishing content', function () {
    $ctx = makeSeoAuditRunDashboardContext();

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $ctx['site']->id,
        'content_id' => $ctx['content']->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Dashboard brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $ctx['content']->id,
        'client_site_id' => $ctx['site']->id,
        'status' => 'generated',
        'delivery_status' => 'pending',
        'title' => 'Old dashboard draft title',
        'output_type' => 'kb_article',
        'content_html' => '<p>Dashboard draft body</p>',
    ]);

    $baseVersion = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $ctx['content']->id,
        'type' => 'draft',
        'parent_version_id' => null,
        'body' => '<p>Existing body</p>',
        'meta' => ['source' => 'test'],
        'source' => 'pl',
        'created_by' => $ctx['user']->id,
    ]);

    $ctx['content']->update([
        'current_version_id' => $baseVersion->id,
        'status' => 'published',
        'publish_status' => 'published',
    ]);

    $suggestion = SeoAuditFixSuggestion::query()->create([
        'organization_id' => $ctx['organization']->id,
        'workspace_id' => $ctx['workspace']->id,
        'client_site_id' => $ctx['site']->id,
        'seo_audit_id' => $ctx['audit']->id,
        'seo_audit_page_id' => $ctx['publishlayerPage']->id,
        'issue_code' => 'meta_description_missing',
        'status' => SeoAuditFixSuggestion::STATUS_GENERATED,
        'suggestion' => [
            'recommended_title' => 'Updated SEO Title',
            'recommended_meta_description' => 'Updated meta description from AI suggestion.',
        ],
        'created_by' => $ctx['user']->id,
    ]);

    $response = $this->actingAs($ctx['user'])
        ->post(route('app.sites.seo-audits.ai-fix.apply', [$ctx['site'], $ctx['audit'], $suggestion]));

    $response->assertRedirect();
    $response->assertSessionHas('status');

    $ctx['content']->refresh();
    $suggestion->refresh();

    $seo = ContentSeo::query()->where('content_id', $ctx['content']->id)->first();
    expect($seo)->not->toBeNull();
    expect((string) $seo->meta_title)->toBe('Updated SEO Title');
    expect((string) $seo->meta_description)->toBe('Updated meta description from AI suggestion.');

    $draft->refresh();
    expect((string) $draft->title)->toBe('Updated SEO Title');
    expect((string) $draft->seo_title)->toBe('Updated SEO Title');
    expect((string) $draft->seo_meta_description)->toBe('Updated meta description from AI suggestion.');

    expect($ctx['content']->status)->toBe('draft');
    expect($ctx['content']->publish_status)->toBe('published');
    expect((string) $ctx['content']->current_version_id)->not->toBe((string) $baseVersion->id);
    expect($suggestion->status)->toBe(SeoAuditFixSuggestion::STATUS_APPLIED);
    expect($suggestion->suggestion_state)->toBe(SeoAuditSuggestionState::APPLIED_LOCAL);
});

it('shows apply and edit actions for a newly generated suggestion', function () {
    $ctx = makeSeoAuditRunDashboardContext();

    $suggestion = SeoAuditFixSuggestion::query()->create([
        'organization_id' => $ctx['organization']->id,
        'workspace_id' => $ctx['workspace']->id,
        'client_site_id' => $ctx['site']->id,
        'seo_audit_id' => $ctx['audit']->id,
        'seo_audit_page_id' => $ctx['publishlayerPage']->id,
        'issue_code' => 'title_missing',
        'status' => SeoAuditFixSuggestion::STATUS_GENERATED,
        'suggestion_state' => SeoAuditSuggestionState::SUGGESTED,
        'suggestion' => [
            'recommended_title' => 'A better title',
        ],
        'created_by' => $ctx['user']->id,
    ]);

    $response = $this->actingAs($ctx['user'])
        ->get(route('app.sites.seo-audits.show', [$ctx['site'], $ctx['audit']]));

    $response->assertOk()
        ->assertSee('Suggestion ready')
        ->assertSee('Apply suggestion')
        ->assertSee('Edit')
        ->assertDontSee('Read only suggestion. No draft apply action available.');

    $generatedSuggestion = collect(data_get($response->viewData('dashboard'), 'ai_panel.generated_suggestions', []))
        ->firstWhere('id', $suggestion->id);

    expect((string) data_get($generatedSuggestion, 'display_state'))->toBe('suggested')
        ->and((string) data_get($generatedSuggestion, 'status_label'))->toBe('Suggestion ready')
        ->and((bool) data_get($generatedSuggestion, 'can_apply'))->toBeTrue()
        ->and((bool) data_get($generatedSuggestion, 'can_edit'))->toBeTrue()
        ->and((bool) data_get($generatedSuggestion, 'can_sync'))->toBeFalse();
});

it('shows applied suggestion actions after local apply', function () {
    $ctx = makeSeoAuditRunDashboardContext();

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $ctx['site']->id,
        'content_id' => $ctx['content']->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'SEO brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $ctx['content']->id,
        'client_site_id' => $ctx['site']->id,
        'status' => 'generated',
        'delivery_status' => 'out_of_sync',
        'title' => 'Old title',
        'output_type' => 'kb_article',
        'content_html' => '<p>Old draft body</p>',
    ]);

    $suggestion = SeoAuditFixSuggestion::query()->create([
        'organization_id' => $ctx['organization']->id,
        'workspace_id' => $ctx['workspace']->id,
        'client_site_id' => $ctx['site']->id,
        'seo_audit_id' => $ctx['audit']->id,
        'seo_audit_page_id' => $ctx['publishlayerPage']->id,
        'issue_code' => 'title_missing',
        'status' => SeoAuditFixSuggestion::STATUS_APPLIED,
        'suggestion_state' => SeoAuditSuggestionState::APPLIED_LOCAL,
        'suggestion' => [
            'recommended_title' => 'Updated SEO Title',
        ],
        'created_by' => $ctx['user']->id,
        'applied_by' => $ctx['user']->id,
    ]);

    \App\Models\SeoApplyLog::query()->create([
        'suggestion_id' => $suggestion->id,
        'seo_audit_id' => $ctx['audit']->id,
        'seo_audit_page_id' => $ctx['publishlayerPage']->id,
        'issue_code' => 'title_missing',
        'content_id' => $ctx['content']->id,
        'draft_id' => $draft->id,
        'applied_by' => $ctx['user']->id,
        'apply_target' => 'content_and_latest_draft',
        'changed_fields' => ['content' => ['seo_title']],
        'old_values' => ['content' => ['seo_title' => null]],
        'new_values' => ['content' => ['seo_title' => 'Updated SEO Title']],
        'apply_status' => 'applied',
        'applied_at' => now()->subMinute(),
    ]);

    $response = $this->actingAs($ctx['user'])
        ->get(route('app.sites.seo-audits.show', [$ctx['site'], $ctx['audit']]));

    $response->assertOk();
    $response->assertSee('Applied to content');
    $response->assertSee('Sync to WordPress');
    $response->assertSee('Edit');
});

it('shows synced suggestion state after wordpress delivery', function () {
    $ctx = makeSeoAuditRunDashboardContext();

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $ctx['site']->id,
        'content_id' => $ctx['content']->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Synced brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $ctx['content']->id,
        'client_site_id' => $ctx['site']->id,
        'status' => 'delivered',
        'delivery_status' => 'delivered',
        'delivered_at' => now(),
        'title' => 'Synced draft',
        'output_type' => 'kb_article',
        'content_html' => '<p>Synced draft body</p>',
    ]);

    $suggestion = SeoAuditFixSuggestion::query()->create([
        'organization_id' => $ctx['organization']->id,
        'workspace_id' => $ctx['workspace']->id,
        'client_site_id' => $ctx['site']->id,
        'seo_audit_id' => $ctx['audit']->id,
        'seo_audit_page_id' => $ctx['publishlayerPage']->id,
        'issue_code' => 'title_missing',
        'status' => SeoAuditFixSuggestion::STATUS_APPLIED,
        'suggestion_state' => SeoAuditSuggestionState::APPLIED_LOCAL,
        'suggestion' => [
            'recommended_title' => 'Updated SEO Title',
        ],
        'created_by' => $ctx['user']->id,
        'applied_by' => $ctx['user']->id,
    ]);

    \App\Models\SeoApplyLog::query()->create([
        'suggestion_id' => $suggestion->id,
        'seo_audit_id' => $ctx['audit']->id,
        'seo_audit_page_id' => $ctx['publishlayerPage']->id,
        'issue_code' => 'title_missing',
        'content_id' => $ctx['content']->id,
        'draft_id' => $draft->id,
        'applied_by' => $ctx['user']->id,
        'apply_target' => 'content_and_latest_draft',
        'changed_fields' => ['content' => ['seo_title']],
        'old_values' => ['content' => ['seo_title' => null]],
        'new_values' => ['content' => ['seo_title' => 'Updated SEO Title']],
        'apply_status' => 'applied',
        'applied_at' => now()->subMinute(),
    ]);

    $response = $this->actingAs($ctx['user'])
        ->get(route('app.sites.seo-audits.show', [$ctx['site'], $ctx['audit']]));

    $response->assertOk()->assertSee('Synced to WordPress');

    $generatedSuggestion = collect(data_get($response->viewData('dashboard'), 'ai_panel.generated_suggestions', []))
        ->firstWhere('id', $suggestion->id);

    expect((string) data_get($generatedSuggestion, 'display_state'))->toBe('synced_external')
        ->and((string) data_get($generatedSuggestion, 'status_label'))->toBe('Synced to WordPress')
        ->and((bool) data_get($generatedSuggestion, 'can_sync'))->toBeFalse()
        ->and((bool) data_get($generatedSuggestion, 'can_edit'))->toBeTrue();
});

it('creates an editable draft when opening suggestion edit without an existing draft', function () {
    $ctx = makeSeoAuditRunDashboardContext();

    $baseVersion = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $ctx['content']->id,
        'type' => 'draft',
        'parent_version_id' => null,
        'body' => '<p>Existing body</p>',
        'meta' => ['source' => 'test'],
        'source' => 'pl',
        'created_by' => $ctx['user']->id,
    ]);

    $ctx['content']->update([
        'current_version_id' => $baseVersion->id,
    ]);

    $suggestion = SeoAuditFixSuggestion::query()->create([
        'organization_id' => $ctx['organization']->id,
        'workspace_id' => $ctx['workspace']->id,
        'client_site_id' => $ctx['site']->id,
        'seo_audit_id' => $ctx['audit']->id,
        'seo_audit_page_id' => $ctx['publishlayerPage']->id,
        'issue_code' => 'title_missing',
        'status' => SeoAuditFixSuggestion::STATUS_GENERATED,
        'suggestion_state' => SeoAuditSuggestionState::SUGGESTED,
        'suggestion' => [
            'recommended_title' => 'Editable title',
        ],
        'created_by' => $ctx['user']->id,
    ]);

    expect(Draft::query()->where('content_id', $ctx['content']->id)->count())->toBe(0);

    $response = $this->actingAs($ctx['user'])
        ->get(route('app.sites.seo-audits.ai-fix.edit', [$ctx['site'], $ctx['audit'], $suggestion]));

    $draft = Draft::query()->where('content_id', $ctx['content']->id)->latest('created_at')->first();

    expect($draft)->not->toBeNull()
        ->and((string) $draft->content_html)->toBe('<p>Existing body</p>');

    $response->assertRedirect(route('app.drafts.show', ['draft' => $draft, 'tab' => 'improve']));
});

function makeSeoAuditRunDashboardContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'SEO Dashboard Org',
        'slug' => 'seo-dashboard-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'SEO Dashboard BV',
        'billing_address_line1' => 'Street 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'SEO Dashboard Workspace',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'seo-dashboard-test-plan'],
        [
            'name' => 'SEO Dashboard Plan',
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

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'SEO Dashboard Site',
        'site_url' => 'https://seo-dashboard.example.com',
        'base_url' => 'https://seo-dashboard.example.com',
        'allowed_domains' => ['seo-dashboard.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $content = Content::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'PublishLayer Article 1',
        'type' => 'article',
        'status' => 'published',
        'source' => 'pl',
        'publish_status' => 'published',
    ]);

    $contentTwo = Content::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'PublishLayer Article 2',
        'type' => 'article',
        'status' => 'published',
        'source' => 'pl',
        'publish_status' => 'published',
    ]);

    $audit = SeoAudit::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'started_at' => now()->subMinutes(10),
        'finished_at' => now()->subMinutes(5),
        'status' => 'completed',
        'pages_crawled' => 3,
        'issue_counts' => ['error' => 1, 'warning' => 1, 'info' => 1],
    ]);

    $historyAudit = SeoAudit::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'started_at' => now()->subDays(1),
        'finished_at' => now()->subDays(1)->addMinutes(4),
        'status' => 'completed',
        'pages_crawled' => 2,
        'issue_counts' => ['error' => 0, 'warning' => 1, 'info' => 1],
    ]);

    $publishlayerPage = SeoAuditPage::query()->create([
        'seo_audit_id' => $audit->id,
        'url' => 'https://seo-dashboard.example.com/pl-1',
        'status_code' => 200,
        'title' => null,
        'meta_description' => null,
        'canonical_url' => 'https://seo-dashboard.example.com/pl-1',
        'h1' => 'PL One',
        'word_count' => 400,
        'internal_links_count' => 3,
        'broken_links_count' => 0,
        'page_type' => SeoAuditPage::PAGE_TYPE_PUBLISHLAYER_ARTICLE,
        'publishlayer_article_id' => $content->id,
    ]);

    SeoAuditPage::query()->create([
        'seo_audit_id' => $audit->id,
        'url' => 'https://seo-dashboard.example.com/pl-2',
        'status_code' => 200,
        'title' => 'Healthy PL Page',
        'meta_description' => 'Healthy meta',
        'canonical_url' => 'https://seo-dashboard.example.com/pl-2',
        'h1' => 'PL Two',
        'word_count' => 500,
        'internal_links_count' => 4,
        'broken_links_count' => 0,
        'page_type' => SeoAuditPage::PAGE_TYPE_PUBLISHLAYER_ARTICLE,
        'publishlayer_article_id' => $contentTwo->id,
    ]);

    $otherPage = SeoAuditPage::query()->create([
        'seo_audit_id' => $audit->id,
        'url' => 'https://seo-dashboard.example.com/about',
        'status_code' => 200,
        'title' => 'About',
        'meta_description' => 'About page',
        'canonical_url' => null,
        'h1' => 'About',
        'word_count' => 220,
        'internal_links_count' => 1,
        'broken_links_count' => 1,
        'page_type' => SeoAuditPage::PAGE_TYPE_SITE_PAGE,
        'publishlayer_article_id' => null,
    ]);

    SeoAuditIssue::query()->create([
        'seo_audit_id' => $audit->id,
        'seo_audit_page_id' => $publishlayerPage->id,
        'severity' => 'warning',
        'code' => 'meta_description_missing',
        'title' => 'Missing meta description',
        'description' => 'No meta description was found.',
        'recommendation' => 'Add a concise description.',
    ]);

    SeoAuditIssue::query()->create([
        'seo_audit_id' => $audit->id,
        'seo_audit_page_id' => $otherPage->id,
        'severity' => 'error',
        'code' => 'http_error',
        'title' => 'HTTP error',
        'description' => 'The page could not be parsed safely.',
        'recommendation' => 'Fix server status and response.',
    ]);

    SeoAuditIssue::query()->create([
        'seo_audit_id' => $audit->id,
        'seo_audit_page_id' => $otherPage->id,
        'severity' => 'info',
        'code' => 'canonical_missing',
        'title' => 'Missing canonical',
        'description' => 'No canonical tag found.',
        'recommendation' => 'Add canonical.',
    ]);

    SeoAuditPage::query()->create([
        'seo_audit_id' => $historyAudit->id,
        'url' => 'https://seo-dashboard.example.com/history',
        'status_code' => 200,
        'title' => 'History',
        'meta_description' => null,
        'canonical_url' => null,
        'h1' => 'History',
        'word_count' => 180,
        'internal_links_count' => 1,
        'broken_links_count' => 0,
        'page_type' => SeoAuditPage::PAGE_TYPE_SITE_PAGE,
        'publishlayer_article_id' => null,
    ]);

    SeoAuditIssue::query()->create([
        'seo_audit_id' => $historyAudit->id,
        'seo_audit_page_id' => SeoAuditPage::query()->where('seo_audit_id', $historyAudit->id)->value('id'),
        'severity' => 'warning',
        'code' => 'meta_description_missing',
        'title' => 'Missing meta description',
        'description' => 'Missing meta',
        'recommendation' => 'Add one.',
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    return compact('organization', 'workspace', 'site', 'audit', 'publishlayerPage', 'content', 'user');
}
