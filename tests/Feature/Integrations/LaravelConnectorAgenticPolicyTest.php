<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Argusly\LaravelConnector\Models\ArguslyArticle;

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

beforeEach(function () {
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
