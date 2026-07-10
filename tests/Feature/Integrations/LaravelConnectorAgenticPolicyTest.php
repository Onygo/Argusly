<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Argusly\LaravelConnector\Models\ArguslyArticle;
use Onygo\ArguslyConnector\Http\Controllers\ConnectorSyncController;

uses(RefreshDatabase::class);

function agenticConnectorPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'type' => 'knowledge_article',
        'site_id' => 'agentic-site',
        'article' => [
            'id' => 'content-1',
            'title' => 'Agentic Draft',
            'slug' => 'agentic-draft',
            'summary' => 'Short summary',
            'content_html' => '<p>Body</p>',
            'status' => 'draft',
            'locale' => 'en',
            'canonical_url' => 'https://example.com/agentic-draft',
            'hreflang_alternates' => [
                ['locale' => 'nl', 'url' => 'https://example.com/nl/agentic-draft'],
            ],
            'answer_blocks' => [
                ['question' => 'What is this?', 'answer' => 'A policy guarded draft.'],
            ],
        ],
        'policy' => [
            'execution_mode' => 'guided',
            'approval_status' => 'approved',
            'safety_check_status' => 'pass',
            'max_allowed_operation' => 'draft',
            'idempotency_key' => 'idem-agentic-1',
        ],
    ], $overrides);
}

function agenticConnectorSyncRouteIsRegistered(): bool
{
    return collect(Route::getRoutes())->contains(
        fn ($route): bool => $route->uri() === 'api/argusly/sync'
            && in_array('POST', $route->methods(), true)
    );
}

beforeEach(function () {
    if (! agenticConnectorSyncRouteIsRegistered()) {
        Route::post('api/argusly/sync', ConnectorSyncController::class);
    }

    cache()->flush();
    config()->set('argusly-connector.webhooks.enabled', true);
    config()->set('argusly-connector.api.token', 'sync-secret');
    config()->set('argusly-connector.site.id', 'agentic-site');
    config()->set('argusly-connector.policy.allowed_operations', ['create', 'update', 'draft']);
    config()->set('argusly-connector.policy.autonomous_allowed', false);
    config()->set('argusly.enabled', true);
    config()->set('argusly.api_key', 'sync-secret');
    config()->set('argusly.require_signature', false);
    config()->set('argusly.site_id', 'agentic-site');
    config()->set('argusly.allowed_operations', ['create', 'update', 'draft']);
    config()->set('argusly.autonomous_allowed', false);
});

it('creates guided drafts and stores structured agentic metadata', function () {
    $response = $this->withHeaders(['X-Argusly-Api-Key' => 'sync-secret'])
        ->postJson('/api/argusly/sync', agenticConnectorPayload());

    $response->assertOk()
        ->assertJsonPath('accepted', true)
        ->assertJsonPath('draft_created', true)
        ->assertJsonPath('preview_ready', true)
        ->assertJsonPath('processed_idempotency_key', 'idem-agentic-1');

    $article = ArguslyArticle::query()->where('source_argusly_id', 'content-1')->firstOrFail();

    expect($article->status)->toBe('draft')
        ->and($article->locale)->toBe('en')
        ->and($article->canonical_url)->toBe('https://example.com/agentic-draft')
        ->and($article->hreflang_alternates[0]['locale'])->toBe('nl')
        ->and($article->answer_blocks[0]['question'])->toBe('What is this?');
});

it('accepts guided publishing when publish operations are allowed', function () {
    config()->set('argusly-connector.policy.allowed_operations', ['create', 'update', 'draft', 'publish']);

    $response = $this->withHeaders(['X-Argusly-Api-Key' => 'sync-secret'])
        ->postJson('/api/argusly/sync', agenticConnectorPayload([
            'article' => [
                'id' => 'content-publish-1',
                'title' => 'Agentic Published Article',
                'slug' => 'agentic-published-article',
                'status' => 'published',
            ],
            'policy' => [
                'max_allowed_operation' => 'publish',
                'idempotency_key' => 'idem-agentic-publish',
            ],
        ]));

    $response->assertOk()
        ->assertJsonPath('accepted', true)
        ->assertJsonPath('draft_created', false);

    $article = ArguslyArticle::query()->where('source_argusly_id', 'content-publish-1')->firstOrFail();

    expect($article->status)->toBe('published')
        ->and($article->locale)->toBe('en');
});

it('blocks autonomous publishing by default', function () {
    $response = $this->withHeaders(['X-Argusly-Api-Key' => 'sync-secret'])
        ->postJson('/api/argusly/sync', agenticConnectorPayload([
            'policy' => [
                'execution_mode' => 'autonomous',
                'idempotency_key' => 'idem-agentic-autonomous',
            ],
        ]));

    $response->assertForbidden()
        ->assertJsonPath('blocked', true)
        ->assertJsonPath('rejected', true);
});

it('keeps webhook sync accepted when another article already owns the requested slug', function () {
    ArguslyArticle::query()->create([
        'source_argusly_id' => 'content-original',
        'title' => 'Original',
        'slug' => 'shared-slug',
        'content_html' => '<p>Original</p>',
        'status' => 'draft',
    ]);

    $this->withHeaders(['X-Argusly-Api-Key' => 'sync-secret'])
        ->postJson('/api/argusly/sync', agenticConnectorPayload([
            'article' => [
                'id' => 'content-with-conflict',
                'slug' => 'shared-slug',
            ],
            'policy' => [
                'idempotency_key' => 'idem-agentic-slug-conflict',
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('accepted', true);

    expect(ArguslyArticle::query()->where('source_argusly_id', 'content-with-conflict')->firstOrFail()->slug)
        ->toBe('shared-slug-2');
});

it('rejects safety blocked actions and duplicate idempotency keys', function () {
    $this->withHeaders(['X-Argusly-Api-Key' => 'sync-secret'])
        ->postJson('/api/argusly/sync', agenticConnectorPayload([
            'policy' => [
                'safety_check_status' => 'block',
                'idempotency_key' => 'idem-agentic-safety',
            ],
        ]))
        ->assertStatus(423)
        ->assertJsonPath('blocked', true);

    $payload = agenticConnectorPayload([
        'article' => ['id' => 'content-2', 'slug' => 'agentic-draft-2'],
        'policy' => ['idempotency_key' => 'idem-agentic-duplicate'],
    ]);

    $this->withHeaders(['X-Argusly-Api-Key' => 'sync-secret'])
        ->postJson('/api/argusly/sync', $payload)
        ->assertOk();

    $this->withHeaders(['X-Argusly-Api-Key' => 'sync-secret'])
        ->postJson('/api/argusly/sync', $payload)
        ->assertStatus(409)
        ->assertJsonPath('rejected', true);
});
