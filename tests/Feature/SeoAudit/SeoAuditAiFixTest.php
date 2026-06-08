<?php

use App\Enums\SeoAuditSuggestionState;
use App\Jobs\SeoAudit\GenerateSeoFixSuggestionsJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentSeo;
use App\Models\ContentVersion;
use App\Models\CreditReservation;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\SeoAudit;
use App\Models\SeoAuditFixSuggestion;
use App\Models\SeoAuditIssue;
use App\Models\SeoApplyLog;
use App\Models\SeoAuditPage;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use App\Services\SeoAudit\SeoAuditAiFixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeSeoAuditAiFixContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'AI Fix Org',
        'slug' => 'ai-fix-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'AI Fix Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'AI Fix Site',
        'site_url' => 'https://seo-audit.example.com',
        'base_url' => 'https://seo-audit.example.com',
        'allowed_domains' => ['seo-audit.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $content = Content::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Original Content Title',
        'type' => 'article',
        'status' => 'published',
        'source' => 'pl',
        'publish_status' => 'published',
    ]);

    $audit = SeoAudit::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'started_at' => now()->subMinutes(2),
        'finished_at' => now()->subMinute(),
        'status' => 'completed',
        'pages_crawled' => 1,
        'issue_counts' => ['warning' => 1],
    ]);

    $page = SeoAuditPage::query()->create([
        'seo_audit_id' => $audit->id,
        'url' => 'https://seo-audit.example.com/pl-article',
        'status_code' => 200,
        'title' => 'Original Title',
        'meta_description' => null,
        'canonical_url' => null,
        'h1' => null,
        'word_count' => 240,
        'internal_links_count' => 1,
        'broken_links_count' => 0,
        'page_type' => SeoAuditPage::PAGE_TYPE_ARGUSLY_ARTICLE,
        'argusly_content_id' => $content->id,
    ]);

    $issue = SeoAuditIssue::query()->create([
        'seo_audit_id' => $audit->id,
        'seo_audit_page_id' => $page->id,
        'severity' => 'warning',
        'code' => 'meta_description_missing',
        'title' => 'Missing meta description',
        'description' => 'No meta description was detected.',
        'recommendation' => 'Add one.',
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    return compact('organization', 'workspace', 'site', 'audit', 'page', 'issue', 'content', 'user');
}

function seedSeoAiFixCredits(ClientSite $site, Workspace $workspace, Organization $organization, int $amount = 40): void
{
    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: $amount,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['seed' => 'seo_ai_fix_test'],
        expiresAt: now()->addDays(30),
        idempotencyKey: 'allowance:seo-ai-fix:' . $site->id,
    );
}

function configureSeoAiFixLlm(): void
{
    config()->set('argusly.ai.seo_fix.credit_cost', 3);
    config()->set('llm.default_provider', 'openai');
    config()->set('llm.providers.openai.api_key', 'test-key');
    config()->set('llm.providers.openai.base_url', 'https://api.openai.com');
    config()->set('llm.providers.openai.default_model', 'gpt-4o-mini');
    config()->set('llm.providers.openai.reasoning_model', 'gpt-4o-mini');
    config()->set('llm.json.fix_retry_enabled', false);
}

it('stores AI suggestion records and captures credits', function () {
    $ctx = makeSeoAuditAiFixContext();
    configureSeoAiFixLlm();
    seedSeoAiFixCredits($ctx['site'], $ctx['workspace'], $ctx['organization']);

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_ai_fix_1',
            'model' => 'gpt-4o-mini',
            'output_text' => json_encode([
                'title' => 'Argusly SEO Fix for Better Organic Reach',
                'meta_description' => 'Learn how Argusly improves your organic traffic with practical tips and measurable SEO fixes.',
                'h1' => 'Argusly SEO Fix Guide',
                'canonical' => 'https://seo-audit.example.com/pl-article',
                'internal_links' => [
                    ['url' => 'https://seo-audit.example.com/about', 'anchor' => 'About Argusly', 'reason' => 'Context link'],
                ],
                'notes' => ['Keep language aligned with the article tone.'],
            ], JSON_UNESCAPED_SLASHES),
            'usage' => [
                'input_tokens' => 130,
                'output_tokens' => 70,
                'total_tokens' => 200,
            ],
        ], 200, ['x-request-id' => 'req_ai_fix_1']),
    ]);

    Bus::dispatchSync(new GenerateSeoFixSuggestionsJob(
        auditId: (int) $ctx['audit']->id,
        issueIds: [(int) $ctx['issue']->id],
        userId: (int) $ctx['user']->id,
    ));

    $suggestion = SeoAuditFixSuggestion::query()->first();
    expect($suggestion)->not->toBeNull();
    expect($suggestion->status)->toBe(SeoAuditFixSuggestion::STATUS_GENERATED);
    expect($suggestion->credits_charged)->toBe(3);
    expect((string) data_get($suggestion->suggestion, 'recommended_meta_description'))->not->toBe('');

    $reservation = CreditReservation::query()
        ->where('purpose', 'seo_audit_ai_fix')
        ->latest('created_at')
        ->first();

    expect($reservation)->not->toBeNull();
    expect($reservation->status)->toBe(CreditReservation::STATUS_CAPTURED);
});

it('marks suggestion as failed and releases reservation when JSON is invalid', function () {
    $ctx = makeSeoAuditAiFixContext();
    configureSeoAiFixLlm();
    seedSeoAiFixCredits($ctx['site'], $ctx['workspace'], $ctx['organization']);

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_ai_fix_invalid',
            'model' => 'gpt-4o-mini',
            'output_text' => 'this is not valid json',
            'usage' => [
                'input_tokens' => 70,
                'output_tokens' => 20,
                'total_tokens' => 90,
            ],
        ], 200, ['x-request-id' => 'req_ai_fix_invalid']),
    ]);

    Bus::dispatchSync(new GenerateSeoFixSuggestionsJob(
        auditId: (int) $ctx['audit']->id,
        issueIds: [(int) $ctx['issue']->id],
        userId: (int) $ctx['user']->id,
    ));

    $suggestion = SeoAuditFixSuggestion::query()->first();
    expect($suggestion)->not->toBeNull();
    expect($suggestion->status)->toBe(SeoAuditFixSuggestion::STATUS_FAILED);
    expect($suggestion->credits_charged)->toBe(0);

    $reservation = CreditReservation::query()
        ->where('purpose', 'seo_audit_ai_fix')
        ->latest('created_at')
        ->first();

    expect($reservation)->not->toBeNull();
    expect($reservation->status)->toBe(CreditReservation::STATUS_RELEASED);
});

it('applies generated suggestions to a new draft revision without publishing', function () {
    $ctx = makeSeoAuditAiFixContext();

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
        'delivery_status' => 'pending',
        'title' => 'Old title',
        'output_type' => 'kb_article',
        'content_html' => '<p>Old draft body</p>',
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
        'seo_audit_page_id' => $ctx['page']->id,
        'issue_code' => 'meta_description_missing',
        'status' => SeoAuditFixSuggestion::STATUS_GENERATED,
        'suggestion' => [
            'recommended_title' => 'Updated SEO Title for Argusly Article',
            'recommended_meta_description' => 'A concise meta description that improves click-through and reflects the article intent.',
            'recommended_robots_index' => false,
            'recommended_robots_follow' => true,
            'recommended_schema_type' => 'Article',
        ],
        'created_by' => $ctx['user']->id,
    ]);

    app(SeoAuditAiFixService::class)->applySuggestionToDraft($suggestion, $ctx['content'], (int) $ctx['user']->id);

    $ctx['content']->refresh();
    $suggestion->refresh();

    $seo = ContentSeo::query()->where('content_id', $ctx['content']->id)->first();
    expect($seo)->not->toBeNull();
    expect((string) $seo->meta_title)->toBe('Updated SEO Title for Argusly Article');
    expect((string) $seo->meta_description)->toBe('A concise meta description that improves click-through and reflects the article intent.');
    expect($seo->robots_index)->toBeFalse();
    expect($seo->robots_follow)->toBeTrue();
    expect((string) $seo->schema_type)->toBe('Article');

    $draft->refresh();
    expect((string) $draft->title)->toBe('Updated SEO Title for Argusly Article');
    expect((string) $draft->seo_title)->toBe('Updated SEO Title for Argusly Article');
    expect((string) $draft->seo_meta_description)->toBe('A concise meta description that improves click-through and reflects the article intent.');
    expect($draft->robots_index)->toBeFalse();
    expect($draft->robots_follow)->toBeTrue();
    expect((string) $draft->schema_type)->toBe('Article');

    expect((string) $ctx['content']->current_version_id)->not->toBe((string) $baseVersion->id);
    expect($ctx['content']->robots_index)->toBeFalse();
    expect($ctx['content']->robots_follow)->toBeTrue();
    expect((string) $ctx['content']->schema_type)->toBe('Article');

    $latestVersion = ContentVersion::query()->find($ctx['content']->current_version_id);
    expect($latestVersion)->not->toBeNull();
    expect((string) data_get($latestVersion->meta, 'source'))->toBe('seo_audit_ai_fix');
    expect((int) data_get($latestVersion->meta, 'seo_audit_fix_suggestion_id'))->toBe((int) $suggestion->id);
    expect(data_get($latestVersion->meta, 'seo.robots_index'))->toBeFalse();
    expect(data_get($latestVersion->meta, 'seo.robots_follow'))->toBeTrue();
    expect((string) data_get($latestVersion->meta, 'seo.schema_type'))->toBe('Article');

    expect($ctx['content']->status)->toBe('draft');
    expect($ctx['content']->publish_status)->toBe('published');
    expect($suggestion->status)->toBe(SeoAuditFixSuggestion::STATUS_APPLIED);
    expect($suggestion->suggestion_state)->toBe(SeoAuditSuggestionState::APPLIED_LOCAL);

    $applyLog = SeoApplyLog::query()->where('suggestion_id', $suggestion->id)->first();
    expect($applyLog)->not->toBeNull();
    expect((string) $applyLog->apply_status)->toBe(SeoApplyLog::STATUS_APPLIED);
    expect((string) $applyLog->apply_target)->toBe('content_and_latest_draft');
    expect($applyLog->applied_by)->toBe((int) $ctx['user']->id);
    expect($applyLog->changed_fields)->toBeArray();
    expect(data_get($applyLog->changed_fields, 'content'))->toContain('seo_title', 'seo_meta_description', 'robots_index', 'robots_follow', 'schema_type');
    expect(data_get($applyLog->changed_fields, 'draft'))->toContain('seo_title', 'seo_meta_description', 'robots_index', 'robots_follow', 'schema_type');
    expect(data_get($applyLog->old_values, 'content.seo_title'))->toBeNull();
    expect(data_get($applyLog->new_values, 'content.seo_title'))->toBe('Updated SEO Title for Argusly Article');
});

it('auto-creates a draft from current content before applying a suggestion when no draft exists', function () {
    $ctx = makeSeoAuditAiFixContext();

    $baseVersion = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $ctx['content']->id,
        'type' => 'draft',
        'parent_version_id' => null,
        'body' => '<p>Current content body</p>',
        'meta' => ['source' => 'test'],
        'source' => 'pl',
        'created_by' => $ctx['user']->id,
    ]);

    $ctx['content']->update([
        'current_version_id' => $baseVersion->id,
        'status' => 'published',
        'publish_status' => 'published',
        'delivery_status' => 'delivered',
        'seo_title' => 'Current content SEO title',
        'seo_h1' => 'Current content heading',
    ]);

    $suggestion = SeoAuditFixSuggestion::query()->create([
        'organization_id' => $ctx['organization']->id,
        'workspace_id' => $ctx['workspace']->id,
        'client_site_id' => $ctx['site']->id,
        'seo_audit_id' => $ctx['audit']->id,
        'seo_audit_page_id' => $ctx['page']->id,
        'issue_code' => 'meta_description_missing',
        'status' => SeoAuditFixSuggestion::STATUS_GENERATED,
        'suggestion' => [
            'recommended_meta_description' => 'Fresh local metadata from the SEO suggestion.',
        ],
        'created_by' => $ctx['user']->id,
    ]);

    $draft = app(SeoAuditAiFixService::class)->applySuggestionToDraft($suggestion, $ctx['content']->fresh(), (int) $ctx['user']->id);

    expect($draft)->toBeInstanceOf(Draft::class);

    $draft->refresh();
    $suggestion->refresh();
    $ctx['content']->refresh();

    expect((string) $draft->content_id)->toBe((string) $ctx['content']->id);
    expect((string) $draft->content_html)->toContain('Current content body');
    expect((string) $draft->seo_meta_description)->toBe('Fresh local metadata from the SEO suggestion.');
    expect((string) $draft->delivery_status)->toBe('out_of_sync');
    expect((string) $draft->brief_id)->not->toBe('');
    expect($suggestion->suggestion_state)->toBe(SeoAuditSuggestionState::APPLIED_LOCAL);
    expect((string) $ctx['content']->delivery_status)->toBe('out_of_sync');
});

it('keeps content_seo mirror aligned with canonical typed fields on partial apply', function () {
    $ctx = makeSeoAuditAiFixContext();

    $ctx['content']->update([
        'title' => 'Canonical content title',
        'seo_title' => 'Canonical typed title',
        'seo_meta_description' => 'Canonical typed description',
        'primary_keyword' => 'canonical focus keyword',
        'robots_index' => true,
        'robots_follow' => true,
        'schema_type' => 'Article',
    ]);

    ContentSeo::query()->create([
        'content_id' => $ctx['content']->id,
        'meta_title' => 'Stale legacy title',
        'meta_description' => 'Stale legacy description',
        'primary_keyword' => 'stale legacy keyword',
        'robots_index' => false,
        'robots_follow' => true,
        'schema_type' => 'WebPage',
    ]);

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

    Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $ctx['content']->id,
        'client_site_id' => $ctx['site']->id,
        'status' => 'generated',
        'delivery_status' => 'pending',
        'title' => 'Existing draft title',
        'output_type' => 'kb_article',
        'content_html' => '<p>Existing draft body</p>',
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
        'seo_audit_page_id' => $ctx['page']->id,
        'issue_code' => 'meta_description_missing',
        'status' => SeoAuditFixSuggestion::STATUS_GENERATED,
        'suggestion' => [
            'recommended_robots_follow' => false,
        ],
        'created_by' => $ctx['user']->id,
    ]);

    app(SeoAuditAiFixService::class)->applySuggestionToDraft($suggestion, $ctx['content']->fresh(), (int) $ctx['user']->id);

    $seo = ContentSeo::query()->where('content_id', $ctx['content']->id)->first();
    expect($seo)->not->toBeNull();
    expect((string) $seo->meta_title)->toBe('Canonical typed title');
    expect((string) $seo->meta_description)->toBe('Canonical typed description');
    expect((string) $seo->primary_keyword)->toBe('canonical focus keyword');
    expect($seo->robots_index)->toBeTrue();
    expect($seo->robots_follow)->toBeFalse();
    expect((string) $seo->schema_type)->toBe('Article');

    $applyLog = SeoApplyLog::query()->where('suggestion_id', $suggestion->id)->first();
    expect($applyLog)->not->toBeNull();
    expect((string) $applyLog->apply_status)->toBe(SeoApplyLog::STATUS_APPLIED);
    expect(data_get($applyLog->changed_fields, 'content'))->toContain('robots_follow');
    expect(data_get($applyLog->new_values, 'content.robots_follow'))->toBeFalse();
});

it('does not write an apply success log when suggestion has no applicable seo fields', function () {
    $ctx = makeSeoAuditAiFixContext();

    $suggestion = SeoAuditFixSuggestion::query()->create([
        'organization_id' => $ctx['organization']->id,
        'workspace_id' => $ctx['workspace']->id,
        'client_site_id' => $ctx['site']->id,
        'seo_audit_id' => $ctx['audit']->id,
        'seo_audit_page_id' => $ctx['page']->id,
        'issue_code' => 'meta_description_missing',
        'status' => SeoAuditFixSuggestion::STATUS_GENERATED,
        'suggestion' => [
            'notes' => ['No applicable fields'],
        ],
        'created_by' => $ctx['user']->id,
    ]);

    expect(fn () => app(SeoAuditAiFixService::class)->applySuggestionToDraft($suggestion, $ctx['content'], (int) $ctx['user']->id))
        ->toThrow(RuntimeException::class, 'Suggestion has no draft-applicable SEO fields.');

    expect(SeoApplyLog::query()->count())->toBe(0);

    $suggestion->refresh();
    expect($suggestion->status)->toBe(SeoAuditFixSuggestion::STATUS_GENERATED);
    expect($suggestion->suggestion_state)->toBe(SeoAuditSuggestionState::SUGGESTED);
});

it('blocks unauthorized users from generating and applying AI seo fixes', function () {
    $this->withoutMiddleware();

    $ctx = makeSeoAuditAiFixContext();

    $unauthorizedUser = User::factory()->create([
        'organization_id' => $ctx['organization']->id,
        'role' => 'member',
        'approved_at' => now(),
        'active' => true,
    ]);

    $suggestion = SeoAuditFixSuggestion::query()->create([
        'organization_id' => $ctx['organization']->id,
        'workspace_id' => $ctx['workspace']->id,
        'client_site_id' => $ctx['site']->id,
        'seo_audit_id' => $ctx['audit']->id,
        'seo_audit_page_id' => $ctx['page']->id,
        'issue_code' => 'meta_description_missing',
        'status' => SeoAuditFixSuggestion::STATUS_GENERATED,
        'suggestion' => [
            'recommended_title' => 'Draft title',
        ],
        'created_by' => $ctx['user']->id,
    ]);

    $this->actingAs($unauthorizedUser)
        ->post(route('app.sites.seo-audits.ai-fix.generate', [$ctx['site'], $ctx['audit']]), [
            'issue_ids' => [(int) $ctx['issue']->id],
        ])
        ->assertForbidden();

    $this->actingAs($unauthorizedUser)
        ->post(route('app.sites.seo-audits.ai-fix.apply', [$ctx['site'], $ctx['audit'], $suggestion]))
        ->assertForbidden();
});
