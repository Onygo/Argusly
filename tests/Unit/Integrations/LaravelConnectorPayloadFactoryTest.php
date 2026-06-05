<?php

use App\Models\ClientSite;
use App\Models\Brief;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentImage;
use App\Models\StructuredAnswerBlock;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Integrations\LaravelConnectorPayloadFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('maps knowledge article payloads for laravel connector destinations', function () {
    $organization = Organization::query()->create([
        'name' => 'Payload Org',
        'slug' => 'payload-org-'.Str::random(6),
        'status' => 'active',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Payload Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => 'Payload Site',
        'site_url' => 'https://payload.example.com',
        'base_url' => 'https://payload.example.com',
        'allowed_domains' => ['payload.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $destination = ContentDestination::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'name' => 'Laravel Connector',
        'type' => 'laravel',
        'status' => 'active',
        'environment' => 'production',
        'default_language' => 'en',
        'tracking_enabled' => true,
        'seo_audit_enabled' => true,
        'config' => [
            'billing_client_site_id' => (string) $site->id,
            'laravel_connector' => [
                'base_url' => 'https://payload.example.com',
                'site_id' => 'site-123',
                'sync_endpoint' => '/publishlayer/sync',
                'enabled' => true,
                'mode' => 'hosted_views',
            ],
        ],
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'content_destination_id' => $destination->id,
        'title' => 'Platform Connector Article',
        'primary_keyword' => 'laravel connector',
        'seo_meta_description' => 'Fallback description',
        'type' => 'article',
        'status' => 'published',
        'source' => 'manual',
        'external_key' => 'ext-article-1',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_destination_id' => $destination->id,
        'content_id' => $content->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Payload brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'content_destination_id' => $destination->id,
        'status' => 'generated',
        'title' => 'Connector Article Draft',
        'seo_title' => 'Connector SEO Title',
        'seo_meta_description' => 'Connector SEO Description',
        'seo_og_image' => 'https://cdn.example.com/og.png',
        'seo_canonical' => 'https://payload.example.com/knowledge/platform-connector-article',
        'content_html' => '<h1>Connector Article</h1><p>Body</p>',
        'meta' => [
            'summary' => 'Short synced summary',
            'slug' => 'platform-connector-article',
            'hreflang_alternates' => [
                ['locale' => 'nl', 'url' => 'https://payload.example.com/nl/platform-connector-article'],
            ],
            'ai_visibility' => ['llm_answer_ready' => true],
            'category' => [
                'id' => 'cat-knowledge',
                'name' => 'Knowledge',
                'slug' => 'knowledge',
                'description' => 'Knowledge content',
            ],
            'related_articles' => [
                [
                    'source_publishlayer_id' => 'rel-1',
                    'slug' => 'related-one',
                    'title' => 'Related One',
                ],
            ],
        ],
    ]);

    ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'featured',
        'image_url' => 'https://cdn.example.com/featured.png',
        'status' => 'ready',
        'is_active' => true,
        'metadata' => [
            'source' => 'unsplash',
            'license' => 'Unsplash License',
            'photo_url' => 'https://unsplash.com/photos/photo-1',
            'attribution' => [
                'text' => 'Photo by Jane Creator on Unsplash',
                'photographer_name' => 'Jane Creator',
                'photographer_url' => 'https://unsplash.com/@janecreator',
                'provider_name' => 'Unsplash',
                'provider_url' => 'https://unsplash.com',
            ],
        ],
    ]);

    $content->update([
        'answer_block_render_mode' => Content::ANSWER_BLOCK_RENDER_MODE_AI_OPTIMIZED,
        'answer_block_max_visible' => 2,
    ]);

    StructuredAnswerBlock::query()->create([
        'content_id' => $content->id,
        'question' => 'What is the connector?',
        'answer' => 'The connector syncs PublishLayer content into Laravel.',
        'entities' => ['connector'],
        'order' => 0,
    ]);

    $payload = app(LaravelConnectorPayloadFactory::class)->make($content->fresh(), $destination, 'draft', [
        'execution_mode' => 'guided',
        'approval_status' => 'approved',
        'action_run_id' => 'run-123',
        'idempotency_key' => 'idem-123',
    ]);

    expect(data_get($payload, 'type'))->toBe('knowledge_article');
    expect(data_get($payload, 'site_id'))->toBe('site-123');
    expect(data_get($payload, 'article.id'))->toBe((string) $content->id);
    expect(data_get($payload, 'article.title'))->toBe('Connector Article Draft');
    expect(data_get($payload, 'article.slug'))->toBe('platform-connector-article');
    expect(data_get($payload, 'article.summary'))->toBe('Short synced summary');
    expect(data_get($payload, 'article.content_html'))->toContain('data-answer-block="true"');
    expect(data_get($payload, 'article.answer_blocks.0.question'))->toBe('What is the connector?');
    expect(data_get($payload, 'article.faq_schema.@type'))->toBe('FAQPage');
    expect(data_get($payload, 'article.seo_title'))->toBe('Connector SEO Title');
    expect(data_get($payload, 'article.seo_description'))->toBe('Connector SEO Description');
    expect(data_get($payload, 'article.featured_image_url'))->toBe('https://cdn.example.com/featured.png');
    expect(data_get($payload, 'article.featured_image_attribution'))->toBe('Photo by Jane Creator on Unsplash');
    expect(data_get($payload, 'article.image_attribution.photographer_name'))->toBe('Jane Creator');
    expect((string) data_get($payload, 'article.image_attribution.photographer_url'))->toContain('utm_source=publishlayer');
    expect(data_get($payload, 'article.status'))->toBe('draft');
    expect(data_get($payload, 'article.locale'))->toBe('en');
    expect(data_get($payload, 'article.canonical_url'))->toBe('https://payload.example.com/knowledge/platform-connector-article');
    expect(data_get($payload, 'article.hreflang_alternates.0.locale'))->toBe('nl');
    expect(data_get($payload, 'article.ai_visibility.llm_answer_ready'))->toBeTrue();
    expect(data_get($payload, 'policy.execution_mode'))->toBe('guided');
    expect(data_get($payload, 'policy.approval_status'))->toBe('approved');
    expect(data_get($payload, 'policy.action_run_id'))->toBe('run-123');
    expect(data_get($payload, 'policy.idempotency_key'))->toBe('idem-123');
    expect(data_get($payload, 'article.published_at'))->not->toBeNull();
    expect(data_get($payload, 'article.source_updated_at'))->not->toBeNull();

    expect(data_get($payload, 'article.category'))->toBe([
        'id' => 'cat-knowledge',
        'name' => 'Knowledge',
        'slug' => 'knowledge',
        'description' => 'Knowledge content',
    ]);

    expect(data_get($payload, 'article.related_articles'))->toBe([]);
});
