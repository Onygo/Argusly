<?php

use App\Jobs\GenerateDraftJob;
use App\Jobs\Integrations\DeliverApiWebhookJob;
use App\Jobs\SeoAudit\RunSeoAuditJob;
use App\Models\AnalyticsEvent;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Services\Api\ApiScopes;
use App\Services\Integrations\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeHeadlessContext(?array $scopes = null): array
{
    $organization = Organization::query()->create([
        'name' => 'Headless Org',
        'slug' => 'headless-org-'.Str::random(5),
        'status' => 'active',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Headless Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'laravel',
        'name' => 'Billing Site',
        'site_url' => 'https://billing-'.Str::random(6).'.example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $destination = ContentDestination::query()->create([
        'workspace_id' => $workspace->id,
        'name' => 'API Destination',
        'type' => 'api',
        'status' => 'active',
        'environment' => 'production',
        'default_language' => 'en',
        'tracking_enabled' => true,
        'seo_audit_enabled' => true,
        'config' => [
            'billing_client_site_id' => (string) $site->id,
        ],
    ]);

    $scopes = $scopes ?: ApiScopes::all();

    $created = app(ApiKeyService::class)->create(
        workspace: $workspace,
        name: 'Test key',
        scopes: $scopes,
        contentDestinationId: (string) $destination->id,
    );

    return [
        'organization' => $organization,
        'workspace' => $workspace,
        'site' => $site,
        'destination' => $destination,
        'api_key' => $created['model'],
        'plain_key' => $created['plain_text_key'],
    ];
}

function apiHeaders(string $plainKey): array
{
    return [
        'Authorization' => 'Bearer '.$plainKey,
    ];
}

it('authenticates workspace api key and returns identity payload', function () {
    $ctx = makeHeadlessContext([ApiScopes::USAGE_READ]);

    $response = $this->withHeaders(apiHeaders($ctx['plain_key']))->getJson('/api/v1/me');

    $response->assertOk()
        ->assertJsonPath('data.workspace.id', (string) $ctx['workspace']->id)
        ->assertJsonPath('data.api_key.name', 'Test key');
});

it('rejects invalid workspace api key', function () {
    $response = $this->withHeaders(apiHeaders('plk_ws_invalid'))->getJson('/api/v1/me');

    $response->assertUnauthorized();
});

it('enforces integration scopes', function () {
    $ctx = makeHeadlessContext([ApiScopes::BRIEFS_READ]);

    $response = $this->withHeaders(apiHeaders($ctx['plain_key']))
        ->postJson('/api/v1/destinations', [
            'name' => 'Blocked destination',
            'type' => 'api',
        ]);

    $response->assertForbidden()
        ->assertJsonPath('code', 'AUTH_SCOPE_MISSING');
});

it('creates brief and queues draft generation with async operation', function () {
    Queue::fake();

    $ctx = makeHeadlessContext([
        ApiScopes::BRIEFS_WRITE,
        ApiScopes::BRIEFS_READ,
        ApiScopes::DRAFTS_READ,
        ApiScopes::DRAFTS_WRITE,
        ApiScopes::GENERATIONS_READ,
    ]);

    $response = $this->withHeaders(apiHeaders($ctx['plain_key']))
        ->postJson('/api/v1/briefs', [
            'title' => 'Headless brief',
            'content_destination_id' => (string) $ctx['destination']->id,
            'generate_draft' => true,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.title', 'Headless brief')
        ->assertJsonPath('meta.operation.operation_type', 'draft_generation')
        ->assertJsonPath('meta.operation.status', 'queued');

    Queue::assertPushed(GenerateDraftJob::class);

    $operationId = (string) $response->json('meta.operation.id');

    $operationResponse = $this->withHeaders(apiHeaders($ctx['plain_key']))
        ->getJson('/api/v1/operations/'.$operationId);

    $operationResponse->assertOk()
        ->assertJsonPath('data.id', $operationId)
        ->assertJsonPath('data.status', 'queued');
});

it('exports draft in headless json shape', function () {
    $ctx = makeHeadlessContext([
        ApiScopes::DRAFTS_READ,
        ApiScopes::CONTENT_READ,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $ctx['workspace']->id,
        'client_site_id' => $ctx['site']->id,
        'content_destination_id' => $ctx['destination']->id,
        'title' => 'Export content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'api',
        'external_key' => (string) Str::uuid(),
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $ctx['site']->id,
        'content_destination_id' => $ctx['destination']->id,
        'content_id' => $content->id,
        'status' => 'done',
        'source' => 'api',
        'progress' => 1,
        'title' => 'Export brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $ctx['site']->id,
        'content_destination_id' => $ctx['destination']->id,
        'status' => 'generated',
        'title' => 'Export draft',
        'language' => 'en',
        'output_type' => 'kb_article',
        'content_html' => '<h1>Hello</h1><p>World</p>',
        'seo_title' => 'SEO title',
        'seo_meta_description' => 'SEO description',
        'meta' => [
            'excerpt' => 'Draft excerpt',
            'key_takeaways' => ['one', 'two'],
            'cta' => ['text' => 'Try now', 'url' => 'https://example.com'],
        ],
        'credit_cost' => 12,
    ]);

    $response = $this->withHeaders(apiHeaders($ctx['plain_key']))
        ->getJson('/api/v1/drafts/'.$draft->id.'/export?format=json');

    $response->assertOk()
        ->assertJsonPath('data.id', (string) $draft->id)
        ->assertJsonPath('data.content.html', '<h1>Hello</h1><p>World</p>')
        ->assertJsonPath('data.summary.key_takeaways.0', 'one')
        ->assertJsonPath('data.usage.credits_used', 12);
});

it('creates webhook and queues brief.created delivery', function () {
    Queue::fake();

    $ctx = makeHeadlessContext([
        ApiScopes::WEBHOOKS_WRITE,
        ApiScopes::BRIEFS_WRITE,
    ]);

    $webhookResponse = $this->withHeaders(apiHeaders($ctx['plain_key']))
        ->postJson('/api/v1/webhooks', [
            'name' => 'Integration webhook',
            'target_url' => 'https://example.com/argusly-webhook',
            'secret' => Str::random(32),
            'events' => ['brief.created'],
        ]);

    $webhookResponse->assertCreated()
        ->assertJsonPath('data.name', 'Integration webhook');

    $briefResponse = $this->withHeaders(apiHeaders($ctx['plain_key']))
        ->postJson('/api/v1/briefs', [
            'title' => 'Webhook brief',
            'content_destination_id' => (string) $ctx['destination']->id,
            'generate_draft' => false,
        ]);

    $briefResponse->assertCreated();

    Queue::assertPushed(DeliverApiWebhookJob::class);
});

it('starts seo audit as async operation', function () {
    Queue::fake();

    $ctx = makeHeadlessContext([
        ApiScopes::SEO_AUDITS_WRITE,
        ApiScopes::GENERATIONS_READ,
    ]);

    $response = $this->withHeaders(apiHeaders($ctx['plain_key']))
        ->postJson('/api/v1/seo-audits', [
            'content_destination_id' => (string) $ctx['destination']->id,
            'max_pages' => 25,
        ]);

    $response->assertStatus(202)
        ->assertJsonPath('data.operation_type', 'seo_audit')
        ->assertJsonPath('data.status', 'queued');

    Queue::assertPushed(RunSeoAuditJob::class);
});

it('validates and stores analytics ingest events', function () {
    $ctx = makeHeadlessContext([
        ApiScopes::ANALYTICS_WRITE,
    ]);

    $invalidResponse = $this->withHeaders(apiHeaders($ctx['plain_key']))
        ->postJson('/api/v1/analytics/events', [
            'events' => [[
                'event_type' => 'unknown_event',
                'page_url' => 'https://example.com/post',
                'timestamp' => now()->toIso8601String(),
            ]],
        ]);

    $invalidResponse->assertStatus(422);

    $validResponse = $this->withHeaders(apiHeaders($ctx['plain_key']))
        ->postJson('/api/v1/analytics/events', [
            'events' => [[
                'event_type' => 'article_view',
                'article_identifier' => (string) Str::uuid(),
                'page_url' => 'https://example.com/blog/post-1',
                'timestamp' => now()->toIso8601String(),
                'session_id' => 'sess_1',
                'visitor_id' => 'vis_1',
            ]],
        ]);

    $validResponse->assertStatus(202)
        ->assertJsonPath('data.received', 1);

    expect(AnalyticsEvent::query()->count())->toBe(1);
});

it('applies api key plan limit from workspace entitlements', function () {
    $ctx = makeHeadlessContext([
        ApiScopes::API_KEYS_WRITE,
    ]);

    WorkspaceEntitlement::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $ctx['workspace']->id,
        'organization_id' => $ctx['organization']->id,
        'feature_key' => 'api_max_keys',
        'value_type' => 'int',
        'value_int' => 1,
        'source' => 'manual',
        'effective_at' => now()->subMinute(),
    ]);

    $response = $this->withHeaders(apiHeaders($ctx['plain_key']))
        ->postJson('/api/v1/api-keys', [
            'name' => 'Second key',
            'scopes' => [ApiScopes::BRIEFS_READ],
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'PLAN_LIMIT_REACHED');
});
