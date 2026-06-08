<?php

use App\Jobs\DeliverDraftJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentImage;
use App\Models\ContentPublishTarget;
use App\Models\ContentSeo;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\StructuredAnswerBlock;
use App\Models\SiteToken;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use App\Services\DraftDelivery\DeliverDraftToWordPress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeDeliveryDraft(
    array $clientRefs = [],
    array $siteOverrides = [],
    array $draftOverrides = [],
    array $contentOverrides = []
): Draft
{
    $organization = Organization::create([
        'name' => 'Delivery Org',
        'slug' => 'delivery-org-' . Str::random(6),
        'status' => 'active',
    ]);

    $workspace = Workspace::create([
        'name' => 'Delivery Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create(array_merge([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Delivery Site',
        'site_url' => 'https://wp.example',
        'allowed_domains' => ['wp.example'],
        'is_active' => true,
    ], $siteOverrides));

    $content = Content::create(array_merge([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Delivery Content',
        'primary_keyword' => 'delivery focus keyword',
        'type' => 'article',
        'status' => 'brief',
        'source' => 'wp',
        'external_key' => 'content-key-' . Str::random(6),
        'delivery_status' => 'pending',
        'generation_mode' => 'balanced',
    ], $contentOverrides));

    $brief = Brief::create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Delivery Brief',
        'primary_keyword' => 'delivery focus keyword',
        'language' => 'nl',
        'output_type' => 'kb_article',
    ]);

    return Draft::create(array_merge([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'ready_to_deliver',
        'title' => 'Delivery Draft',
        'seo_title' => 'Delivery SEO Title',
        'seo_meta_description' => 'Delivery SEO description',
        'seo_h1' => 'Delivery H1',
        'seo_canonical' => 'https://wp.example/delivery-draft',
        'seo_og_title' => 'Delivery OG title',
        'seo_og_description' => 'Delivery OG description',
        'seo_og_image' => 'https://cdn.example.test/delivery-og.png',
        'robots_index' => false,
        'robots_follow' => true,
        'schema_type' => 'Article',
        'output_type' => 'kb_article',
        'content_html' => '<p>hello</p>',
        'meta' => [
            'client_refs' => array_merge([
                'draft_webhook_url' => 'https://wp.example/webhook',
                'draft_webhook_secret' => 'topsecret',
            ], $clientRefs),
        ],
    ], $draftOverrides));
}

function wpPublishTargetForDraft(Draft $draft): ?ContentPublishTarget
{
    return ContentPublishTarget::query()
        ->where('content_id', $draft->content_id)
        ->where('client_site_id', $draft->client_site_id)
        ->where('target_type', 'wp')
        ->latest('updated_at')
        ->first();
}

it('uses content_id as stable payload id when no remote ids are known', function () {
    Http::fake([
        'https://wp.example/*' => Http::response('ok', 200),
    ]);

    $draft = makeDeliveryDraft();

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request) use ($draft) {
        $payload = json_decode((string) $request->body(), true);
        return ($payload['id'] ?? null) === (string) $draft->content_id;
    });
});

it('sends unsplash attribution with delivered article payload', function () {
    Http::fake([
        'https://wp.example/*' => Http::response('ok', 200),
    ]);

    $draft = makeDeliveryDraft(draftOverrides: [
        'content_html' => '<p>Article body.</p>',
    ]);

    ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $draft->content_id,
        'type' => 'featured',
        'provider' => 'unsplash',
        'status' => 'ready',
        'is_active' => true,
        'image_url' => 'https://images.unsplash.com/photo-1?w=1080',
        'credit_cost' => 0,
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

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request) {
        $payload = json_decode((string) $request->body(), true);
        return data_get($payload, 'content_html') === '<p>Article body.</p>'
            && data_get($payload, 'featured_image_attribution') === 'Photo by Jane Creator on Unsplash'
            && str_contains((string) data_get($payload, 'image_attribution.photographer_url'), 'utm_source=argusly');
    });
});

it('uses remote_draft_id when available to prevent duplicate wp drafts', function () {
    Http::fake([
        'https://wp.example/*' => Http::response('ok', 200),
    ]);

    $draft = makeDeliveryDraft([
        'remote_draft_id' => '77',
        'wp_post_id' => '77',
    ]);

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request) {
        $payload = json_decode((string) $request->body(), true);
        return ($payload['id'] ?? null) === '77';
    });
});

it('prefers wp_post_id over content_id when remote_draft_id is missing', function () {
    Http::fake([
        'https://wp.example/*' => Http::response('ok', 200),
    ]);

    $draft = makeDeliveryDraft([
        'wp_post_id' => '88',
    ]);

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request) {
        $payload = json_decode((string) $request->body(), true);
        return ($payload['id'] ?? null) === '88';
    });
});

it('sends correlation id in headers and payload', function () {
    Http::fake([
        'https://wp.example/*' => Http::response('ok', 200),
    ]);

    $draft = makeDeliveryDraft();
    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request) {
        $payload = json_decode((string) $request->body(), true);
        $header = (string) $request->header('X-Argusly-Correlation-Id')[0];
        $payloadCorrelation = (string) ($payload['correlation_id'] ?? '');

        return $header !== '' && $header === $payloadCorrelation;
    });
});

it('includes answer blocks and faq schema in wordpress publish payloads', function () {
    Http::fake([
        'https://wp.example/*' => Http::response(['ok' => true, 'wp_post_id' => '901'], 200),
    ]);

    $draft = makeDeliveryDraft(
        draftOverrides: [
            'content_html' => '<p>Intro</p><h2>Pricing</h2><p>Body</p>',
        ],
        contentOverrides: [
            'answer_block_render_mode' => Content::ANSWER_BLOCK_RENDER_MODE_AI_OPTIMIZED,
            'answer_block_max_visible' => 2,
        ],
    );

    StructuredAnswerBlock::query()->create([
        'content_id' => $draft->content_id,
        'question' => 'What is pricing?',
        'answer' => 'Pricing depends on your plan.',
        'entities' => ['pricing'],
        'order' => 0,
    ]);

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request): bool {
        $payload = json_decode((string) $request->body(), true);

        return str_contains((string) ($payload['content_html'] ?? ''), 'data-answer-block="true"')
            && data_get($payload, 'answer_blocks.0.question') === 'What is pricing?'
            && data_get($payload, 'faq_schema.@type') === 'FAQPage'
            && data_get($payload, 'meta.argusly.faq_schema.@type') === 'FAQPage';
    });
});

it('falls back to active webhook endpoint when draft refs are missing', function () {
    Http::fake([
        'https://wp.example/*' => Http::response('ok', 200),
    ]);

    $draft = makeDeliveryDraft([
        'draft_webhook_url' => null,
        'draft_webhook_secret' => null,
    ]);

    $meta = is_array($draft->meta) ? $draft->meta : [];
    $refs = is_array($meta['client_refs'] ?? null) ? $meta['client_refs'] : [];
    unset($refs['draft_webhook_url'], $refs['draft_webhook_secret']);
    $meta['client_refs'] = $refs;
    $draft->update(['meta' => $meta]);

    WebhookEndpoint::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $draft->client_site_id,
        'event_type' => 'draft.ready',
        'url' => 'https://wp.example/webhook',
        'signing_method' => 'hmac_sha256',
        'secret' => 'topsecret',
        'is_active' => true,
    ]);

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request) {
        return (string) $request->url() === 'https://wp.example/webhook';
    });
});

it('falls back to direct wordpress api with site token when webhook is not configured', function () {
    $plainToken = 'arg_site_testtoken123';

    Http::fake([
        'https://wp.example/wp-json/argusly/v1/posts' => Http::response([
            'ok' => true,
            'post_id' => '321',
            'wp_post_id' => '321',
            'url' => 'https://wp.example/?p=321',
        ], 200),
    ]);

    $draft = makeDeliveryDraft([
        'draft_webhook_url' => null,
        'draft_webhook_secret' => null,
    ]);

    $meta = is_array($draft->meta) ? $draft->meta : [];
    $refs = is_array($meta['client_refs'] ?? null) ? $meta['client_refs'] : [];
    unset($refs['draft_webhook_url'], $refs['draft_webhook_secret']);
    $meta['client_refs'] = $refs;
    $draft->update(['meta' => $meta]);

    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $draft->client_site_id,
        'workspace_id' => $draft->clientSite?->workspace_id,
        'name' => 'test key',
        'token_hash' => hash('sha256', $plainToken),
        'token_encrypted' => Crypt::encryptString($plainToken),
        'key_prefix' => substr($plainToken, 0, 14),
        'scopes' => ['*'],
        'abilities' => ['*'],
        'revoked' => false,
        'revoked_at' => null,
    ]);

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request) use ($plainToken) {
        $auth = (string) (($request->header('Authorization')[0] ?? ''));
        return str_starts_with((string) $request->url(), 'https://wp.example/wp-json/argusly/v1/posts')
            && $auth === 'Bearer ' . $plainToken;
    });

    $draft->refresh();
    expect((string) data_get($draft->meta, 'client_refs.wp_post_id'))->toBe('321');
    expect((string) $draft->content?->fresh()?->wp_post_id)->toBe('321');

    expect(ContentPublishTarget::query()
        ->where('content_id', $draft->content_id)
        ->where('client_site_id', $draft->client_site_id)
        ->where('target_type', 'wp')
        ->where('target_identifier', '321')
        ->exists())->toBeTrue();
});

it('updates an existing wordpress post using wp_post_id endpoint and does not create a second post', function () {
    $plainToken = 'arg_site_testtoken_update_131';

    $draft = makeDeliveryDraft([
        'draft_webhook_url' => null,
        'draft_webhook_secret' => null,
        'wp_post_id' => '131',
    ]);

    $draft->content()->update(['wp_post_id' => '131']);

    $meta = is_array($draft->meta) ? $draft->meta : [];
    $refs = is_array($meta['client_refs'] ?? null) ? $meta['client_refs'] : [];
    unset($refs['draft_webhook_url'], $refs['draft_webhook_secret']);
    $meta['client_refs'] = $refs;
    $draft->update(['meta' => $meta]);

    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $draft->client_site_id,
        'workspace_id' => $draft->clientSite?->workspace_id,
        'name' => 'test key',
        'token_hash' => hash('sha256', $plainToken),
        'token_encrypted' => Crypt::encryptString($plainToken),
        'key_prefix' => substr($plainToken, 0, 14),
        'scopes' => ['*'],
        'abilities' => ['*'],
        'revoked' => false,
        'revoked_at' => null,
    ]);

    Http::fake([
        'https://wp.example/wp-json/argusly/v1/posts/131' => Http::response([
            'ok' => true,
            'post_id' => '131',
            'wp_post_id' => '131',
            'status' => 'publish',
            'url' => 'https://wp.example/?p=131',
        ], 200),
        'https://wp.example/wp-json/argusly/v1/posts' => Http::response([
            'ok' => true,
            'post_id' => '132',
            'wp_post_id' => '132',
        ], 200),
    ]);

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request) {
        return (string) $request->url() === 'https://wp.example/wp-json/argusly/v1/posts/131';
    });

    Http::assertNotSent(function ($request) {
        return (string) $request->url() === 'https://wp.example/wp-json/argusly/v1/posts';
    });

    expect((string) $draft->fresh()->content?->wp_post_id)->toBe('131');
    expect((string) $draft->fresh()->content?->published_url)->toBe('https://wp.example/?p=131');
});

it('retries against the same wordpress post without creating duplicates on the normal update path', function () {
    $plainToken = 'arg_site_testtoken_update_retry_safe';

    $draft = makeDeliveryDraft([
        'draft_webhook_url' => null,
        'draft_webhook_secret' => null,
        'wp_post_id' => '131',
    ]);

    $draft->content()->update(['wp_post_id' => '131']);

    $meta = is_array($draft->meta) ? $draft->meta : [];
    $refs = is_array($meta['client_refs'] ?? null) ? $meta['client_refs'] : [];
    unset($refs['draft_webhook_url'], $refs['draft_webhook_secret']);
    $meta['client_refs'] = $refs;
    $draft->update(['meta' => $meta]);

    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $draft->client_site_id,
        'workspace_id' => $draft->clientSite?->workspace_id,
        'name' => 'test key',
        'token_hash' => hash('sha256', $plainToken),
        'token_encrypted' => Crypt::encryptString($plainToken),
        'key_prefix' => substr($plainToken, 0, 14),
        'scopes' => ['*'],
        'abilities' => ['*'],
        'revoked' => false,
        'revoked_at' => null,
    ]);

    Http::fake([
        'https://wp.example/wp-json/argusly/v1/posts/131' => Http::response([
            'ok' => true,
            'post_id' => '131',
            'wp_post_id' => '131',
            'status' => 'publish',
            'url' => 'https://wp.example/?p=131',
        ], 200),
        'https://wp.example/wp-json/argusly/v1/posts' => Http::response([
            'ok' => true,
            'post_id' => '999',
            'wp_post_id' => '999',
        ], 200),
    ]);

    $service = app(DeliverDraftToWordPress::class);
    expect($service->deliver($draft->fresh())['ok'])->toBeTrue();
    // Force delivery on second call to bypass checksum-based skip optimization
    expect($service->deliver($draft->fresh(), forceDelivery: true)['ok'])->toBeTrue();

    Http::assertSentCount(6);
    Http::assertSent(function ($request): bool {
        return $request->method() === 'GET'
            && (string) $request->url() === 'https://wp.example/wp-json/argusly/v1/posts/131';
    });
    Http::assertSent(function ($request): bool {
        return $request->method() === 'POST'
            && (string) $request->url() === 'https://wp.example/wp-json/argusly/v1/posts/131';
    });
    Http::assertNotSent(function ($request): bool {
        return (string) $request->url() === 'https://wp.example/wp-json/argusly/v1/posts';
    });

    expect((string) $draft->fresh()->content?->wp_post_id)->toBe('131');
});

it('falls back to legacy posts endpoint when wp_post_id update endpoint is unavailable', function () {
    $plainToken = 'arg_site_testtoken_update_legacy_fallback';

    $draft = makeDeliveryDraft([
        'draft_webhook_url' => null,
        'draft_webhook_secret' => null,
        'wp_post_id' => '131',
    ]);

    $draft->content()->update(['wp_post_id' => '131']);

    $meta = is_array($draft->meta) ? $draft->meta : [];
    $refs = is_array($meta['client_refs'] ?? null) ? $meta['client_refs'] : [];
    unset($refs['draft_webhook_url'], $refs['draft_webhook_secret']);
    $meta['client_refs'] = $refs;
    $draft->update(['meta' => $meta]);

    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $draft->client_site_id,
        'workspace_id' => $draft->clientSite?->workspace_id,
        'name' => 'test key',
        'token_hash' => hash('sha256', $plainToken),
        'token_encrypted' => Crypt::encryptString($plainToken),
        'key_prefix' => substr($plainToken, 0, 14),
        'scopes' => ['*'],
        'abilities' => ['*'],
        'revoked' => false,
        'revoked_at' => null,
    ]);

    Http::fake([
        'https://wp.example/wp-json/argusly/v1/posts/131' => Http::response([
            'code' => 'rest_no_route',
            'message' => 'No route was found matching the URL and request method.',
        ], 404),
        'https://wp.example/?rest_route=/argusly/v1/posts/131' => Http::response([
            'code' => 'rest_no_route',
            'message' => 'No route was found matching the URL and request method.',
        ], 404),
        'https://wp.example/wp-json/argusly/v1/posts' => Http::response([
            'ok' => true,
            'post_id' => '131',
            'wp_post_id' => '131',
            'status' => 'publish',
            'url' => 'https://wp.example/?p=131',
        ], 200),
    ]);

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request): bool {
        return (string) $request->url() === 'https://wp.example/wp-json/argusly/v1/posts/131';
    });

    Http::assertSent(function ($request): bool {
        return (string) $request->url() === 'https://wp.example/wp-json/argusly/v1/posts';
    });

    expect((string) $draft->fresh()->content?->wp_post_id)->toBe('131');
    expect((string) $draft->fresh()->content?->published_url)->toBe('https://wp.example/?p=131');
});

it('recreates a missing wordpress post before direct api delivery when the stored wp_post_id is gone', function () {
    $plainToken = 'arg_site_testtoken_missing_remote_direct';

    $draft = makeDeliveryDraft([
        'draft_webhook_url' => null,
        'draft_webhook_secret' => null,
        'wp_post_id' => '131',
        'remote_draft_id' => '131',
    ]);

    $draft->content()->update([
        'wp_post_id' => '131',
        'published_url' => 'https://wp.example/?p=131',
    ]);

    $meta = is_array($draft->meta) ? $draft->meta : [];
    $refs = is_array($meta['client_refs'] ?? null) ? $meta['client_refs'] : [];
    unset($refs['draft_webhook_url'], $refs['draft_webhook_secret']);
    $meta['client_refs'] = $refs;
    $draft->update(['meta' => $meta]);

    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $draft->client_site_id,
        'workspace_id' => $draft->clientSite?->workspace_id,
        'name' => 'test key',
        'token_hash' => hash('sha256', $plainToken),
        'token_encrypted' => Crypt::encryptString($plainToken),
        'key_prefix' => substr($plainToken, 0, 14),
        'scopes' => ['*'],
        'abilities' => ['*'],
        'revoked' => false,
        'revoked_at' => null,
    ]);

    Http::fake([
        'https://wp.example/wp-json/argusly/v1/posts/131' => Http::response(['code' => 'rest_post_invalid_id'], 404),
        'https://wp.example/?rest_route=/argusly/v1/posts/131' => Http::response(['code' => 'rest_post_invalid_id'], 404),
        'https://wp.example/wp-json/wp/v2/posts/131?context=edit' => Http::response(['code' => 'rest_post_invalid_id'], 404),
        'https://wp.example/wp-json/wp/v2/posts/131' => Http::response(['code' => 'rest_post_invalid_id'], 404),
        'https://wp.example/wp-json/argusly/v1/posts' => Http::response([
            'ok' => true,
            'post_id' => '245',
            'wp_post_id' => '245',
            'url' => 'https://wp.example/?p=245',
        ], 200),
    ]);

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request): bool {
        return $request->method() === 'GET'
            && (string) $request->url() === 'https://wp.example/wp-json/argusly/v1/posts/131';
    });

    Http::assertNotSent(function ($request): bool {
        return $request->method() === 'POST'
            && (string) $request->url() === 'https://wp.example/wp-json/argusly/v1/posts/131';
    });

    Http::assertSent(function ($request) use ($draft): bool {
        if ($request->method() !== 'POST' || (string) $request->url() !== 'https://wp.example/wp-json/argusly/v1/posts') {
            return false;
        }

        $payload = json_decode((string) $request->body(), true);

        return ! array_key_exists('wp_post_id', $payload)
            && ($payload['id'] ?? null) === (string) $draft->content_id;
    });

    $draft->refresh();
    $content = $draft->content()->first();
    $target = wpPublishTargetForDraft($draft);

    expect((string) $content?->wp_post_id)->toBe('245');
    expect((string) $content?->published_url)->toBe('https://wp.example/?p=245');
    expect((string) $target?->sync_status)->toBe('synced');
    expect((string) data_get($target?->meta, 'remote_sync_status'))->toBe('synced');
    expect((array) data_get($target?->meta, 'previous_wp_post_ids', []))->toContain('131');
});

it('recreates a missing wordpress post before webhook delivery when the stored wp_post_id is gone', function () {
    $plainToken = 'arg_site_testtoken_missing_remote_webhook';

    $draft = makeDeliveryDraft([
        'wp_post_id' => '131',
        'remote_draft_id' => '131',
    ]);

    $draft->content()->update([
        'wp_post_id' => '131',
        'published_url' => 'https://wp.example/?p=131',
    ]);

    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $draft->client_site_id,
        'workspace_id' => $draft->clientSite?->workspace_id,
        'name' => 'test key',
        'token_hash' => hash('sha256', $plainToken),
        'token_encrypted' => Crypt::encryptString($plainToken),
        'key_prefix' => substr($plainToken, 0, 14),
        'scopes' => ['*'],
        'abilities' => ['*'],
        'revoked' => false,
        'revoked_at' => null,
    ]);

    Http::fake([
        'https://wp.example/wp-json/argusly/v1/posts/131' => Http::response(['code' => 'rest_post_invalid_id'], 404),
        'https://wp.example/?rest_route=/argusly/v1/posts/131' => Http::response(['code' => 'rest_post_invalid_id'], 404),
        'https://wp.example/wp-json/wp/v2/posts/131?context=edit' => Http::response(['code' => 'rest_post_invalid_id'], 404),
        'https://wp.example/wp-json/wp/v2/posts/131' => Http::response(['code' => 'rest_post_invalid_id'], 404),
        'https://wp.example/webhook' => Http::response([
            'ok' => true,
            'post_id' => '246',
            'wp_post_id' => '246',
            'url' => 'https://wp.example/?p=246',
        ], 200),
    ]);

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request): bool {
        return $request->method() === 'GET'
            && (string) $request->url() === 'https://wp.example/wp-json/argusly/v1/posts/131';
    });

    Http::assertSent(function ($request) use ($draft): bool {
        if ($request->method() !== 'POST' || (string) $request->url() !== 'https://wp.example/webhook') {
            return false;
        }

        $payload = json_decode((string) $request->body(), true);

        return ! array_key_exists('wp_post_id', $payload)
            && ($payload['id'] ?? null) === (string) $draft->content_id;
    });

    $draft->refresh();

    expect((string) $draft->content?->fresh()?->wp_post_id)->toBe('246');
    expect((string) $draft->content?->fresh()?->published_url)->toBe('https://wp.example/?p=246');
});

it('marks delivery delivered after recreating a deleted wordpress post during the next publish job', function () {
    $plainToken = 'arg_site_testtoken_missing_remote_job';

    $draft = makeDeliveryDraft([
        'draft_webhook_url' => null,
        'draft_webhook_secret' => null,
        'wp_post_id' => '131',
        'remote_draft_id' => '131',
    ]);

    $draft->content()->update([
        'wp_post_id' => '131',
        'published_url' => 'https://wp.example/?p=131',
    ]);

    $meta = is_array($draft->meta) ? $draft->meta : [];
    $refs = is_array($meta['client_refs'] ?? null) ? $meta['client_refs'] : [];
    unset($refs['draft_webhook_url'], $refs['draft_webhook_secret']);
    $meta['client_refs'] = $refs;
    $draft->update(['meta' => $meta]);

    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $draft->client_site_id,
        'workspace_id' => $draft->clientSite?->workspace_id,
        'name' => 'test key',
        'token_hash' => hash('sha256', $plainToken),
        'token_encrypted' => Crypt::encryptString($plainToken),
        'key_prefix' => substr($plainToken, 0, 14),
        'scopes' => ['*'],
        'abilities' => ['*'],
        'revoked' => false,
        'revoked_at' => null,
    ]);

    Http::fake([
        'https://wp.example/wp-json/argusly/v1/posts/131' => Http::response(['code' => 'rest_post_invalid_id'], 404),
        'https://wp.example/?rest_route=/argusly/v1/posts/131' => Http::response(['code' => 'rest_post_invalid_id'], 404),
        'https://wp.example/wp-json/wp/v2/posts/131?context=edit' => Http::response(['code' => 'rest_post_invalid_id'], 404),
        'https://wp.example/wp-json/wp/v2/posts/131' => Http::response(['code' => 'rest_post_invalid_id'], 404),
        'https://wp.example/wp-json/argusly/v1/posts' => Http::response([
            'ok' => true,
            'post_id' => '247',
            'wp_post_id' => '247',
            'url' => 'https://wp.example/?p=247',
        ], 200),
    ]);

    // Dispatch job via Bus to use DI
    Bus::dispatchSync(new DeliverDraftJob((string) $draft->id));

    $draft->refresh();
    $content = $draft->content()->first();

    expect((string) $draft->delivery_status)->toBe('delivered');
    expect((string) $content?->publish_status)->toBe('published');
    // ContentPublication now owns the remote_id, Content.wp_post_id is synced via legacy compatibility
    expect((string) $content?->wp_post_id)->toBe('247');
});

it('updates mapping when wordpress returns a different id and logs a warning', function () {
    // Allow 'delivery' channel calls for structured logging
    $channelMock = Mockery::mock('Psr\Log\LoggerInterface');
    $channelMock->shouldReceive('log')->andReturnNull();
    Log::shouldReceive('channel')
        ->with('delivery')
        ->andReturn($channelMock);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'wp_post_id_changed_after_publish'
                && (string) ($context['expected_wp_post_id'] ?? '') === '131'
                && (string) ($context['returned_wp_post_id'] ?? '') === '132';
        });
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    $plainToken = 'arg_site_testtoken_update_change';

    $draft = makeDeliveryDraft([
        'draft_webhook_url' => null,
        'draft_webhook_secret' => null,
        'wp_post_id' => '131',
    ]);

    $draft->content()->update(['wp_post_id' => '131']);

    $meta = is_array($draft->meta) ? $draft->meta : [];
    $refs = is_array($meta['client_refs'] ?? null) ? $meta['client_refs'] : [];
    unset($refs['draft_webhook_url'], $refs['draft_webhook_secret']);
    $meta['client_refs'] = $refs;
    $draft->update(['meta' => $meta]);

    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $draft->client_site_id,
        'workspace_id' => $draft->clientSite?->workspace_id,
        'name' => 'test key',
        'token_hash' => hash('sha256', $plainToken),
        'token_encrypted' => Crypt::encryptString($plainToken),
        'key_prefix' => substr($plainToken, 0, 14),
        'scopes' => ['*'],
        'abilities' => ['*'],
        'revoked' => false,
        'revoked_at' => null,
    ]);

    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        if ($request->method() === 'GET' && $request->url() === 'https://wp.example/wp-json/argusly/v1/posts/131') {
            return Http::response([
                'ok' => true,
                'post_id' => '131',
                'wp_post_id' => '131',
                'status' => 'publish',
                'url' => 'https://wp.example/?p=131',
            ], 200);
        }

        if ($request->method() === 'POST' && $request->url() === 'https://wp.example/wp-json/argusly/v1/posts/131') {
            return Http::response([
                'ok' => true,
                'post_id' => '132',
                'wp_post_id' => '132',
                'status' => 'publish',
                'url' => 'https://wp.example/?p=132',
            ], 200);
        }

        return Http::response([], 404);
    });

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    expect((string) $draft->fresh()->content?->wp_post_id)->toBe('132');
    expect((string) $draft->fresh()->content?->published_url)->toBe('https://wp.example/?p=132');
});

it('sends yoast mapped wp meta fields when site supports seo syncing', function () {
    Http::fake([
        'https://wp.example/*' => Http::response(['ok' => true, 'wp_post_id' => '777'], 200),
    ]);

    $draft = makeDeliveryDraft(
        siteOverrides: [
            'seo_provider' => 'yoast',
            'supports_meta_title' => true,
            'supports_meta_description' => true,
            'supports_canonical' => true,
            'supports_og_tags' => true,
        ]
    );

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request): bool {
        $payload = json_decode((string) $request->body(), true);
        $metaInput = (array) ($payload['meta_input'] ?? []);

        return data_get($payload, 'seo_sync.mode') === 'sync'
            && data_get($payload, 'seo_sync.provider') === 'yoast'
            && ($metaInput['_yoast_wpseo_title'] ?? null) === 'Delivery SEO Title'
            && ($metaInput['_yoast_wpseo_metadesc'] ?? null) === 'Delivery SEO description'
            && ($metaInput['_yoast_wpseo_focuskw'] ?? null) === 'delivery focus keyword'
            && ($metaInput['_yoast_wpseo_canonical'] ?? null) === 'https://wp.example/delivery-draft';
    });

    $target = wpPublishTargetForDraft($draft);
    expect($target)->not->toBeNull();
    expect((string) $target->seo_sync_status)->toBe('synced');
    expect((string) $target->seo_sync_mode)->toBe('sync');
    expect($target->seo_sync_error)->toBeNull();
    expect((array) ($target->seo_synced_fields ?? []))->toContain('_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw');
    expect((string) data_get($target->meta, 'seo_sync.provider'))->toBe('yoast');
    expect((string) data_get($target->meta, 'seo_sync.status'))->toBe('synced');
    expect((string) data_get($target->meta, 'seo_sync.mode'))->toBe('sync');
});

it('falls back to legacy content_seo values when typed seo columns are empty', function () {
    Http::fake([
        'https://wp.example/*' => Http::response(['ok' => true, 'wp_post_id' => '783'], 200),
    ]);

    $draft = makeDeliveryDraft(
        siteOverrides: [
            'seo_provider' => 'yoast',
            'supports_meta_title' => true,
            'supports_meta_description' => true,
            'supports_canonical' => true,
            'supports_og_tags' => true,
        ],
        draftOverrides: [
            'seo_title' => null,
            'seo_meta_description' => null,
            'robots_index' => null,
            'robots_follow' => null,
            'schema_type' => null,
        ],
        contentOverrides: [
            'seo_title' => null,
            'seo_meta_description' => null,
            'primary_keyword' => null,
            'robots_index' => null,
            'robots_follow' => null,
            'schema_type' => null,
        ],
    );

    ContentSeo::query()->create([
        'content_id' => $draft->content_id,
        'meta_title' => 'Legacy SEO title',
        'meta_description' => 'Legacy SEO description',
        'primary_keyword' => 'legacy focus keyword',
        'robots_index' => false,
        'robots_follow' => false,
        'schema_type' => 'HowTo',
    ]);

    $draft->brief()->update(['primary_keyword' => null]);
    $meta = is_array($draft->meta) ? $draft->meta : [];
    unset($meta['primary_keyword'], $meta['robots_index'], $meta['robots_follow'], $meta['schema_type']);
    $draft->update(['meta' => $meta]);

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request): bool {
        $payload = json_decode((string) $request->body(), true);
        $metaInput = (array) ($payload['meta_input'] ?? []);

        return ($metaInput['_yoast_wpseo_title'] ?? null) === 'Legacy SEO title'
            && ($metaInput['_yoast_wpseo_metadesc'] ?? null) === 'Legacy SEO description'
            && ($metaInput['_yoast_wpseo_focuskw'] ?? null) === 'legacy focus keyword'
            && data_get($payload, 'robots_index') === false
            && data_get($payload, 'robots_follow') === false
            && data_get($payload, 'schema_type') === 'HowTo'
            && data_get($payload, 'meta.argusly.seo.robots_index') === false
            && data_get($payload, 'meta.argusly.seo.robots_follow') === false
            && data_get($payload, 'meta.argusly.seo.schema_type') === 'HowTo';
    });
});

it('includes robots and schema fields in wordpress payload without provider-specific meta mapping', function () {
    Http::fake([
        'https://wp.example/*' => Http::response(['ok' => true, 'wp_post_id' => '782'], 200),
    ]);

    $draft = makeDeliveryDraft(
        siteOverrides: [
            'seo_provider' => 'yoast',
            'supports_meta_title' => true,
            'supports_meta_description' => true,
            'supports_canonical' => true,
            'supports_og_tags' => true,
        ],
    );

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request): bool {
        $payload = json_decode((string) $request->body(), true);
        $metaInput = (array) data_get($payload, 'meta_input', []);

        return data_get($payload, 'robots_index') === false
            && data_get($payload, 'robots_follow') === true
            && data_get($payload, 'schema_type') === 'Article'
            && data_get($payload, 'meta.argusly.seo.robots_index') === false
            && data_get($payload, 'meta.argusly.seo.robots_follow') === true
            && data_get($payload, 'meta.argusly.seo.schema_type') === 'Article'
            && ! array_key_exists('robots_index', $metaInput)
            && ! array_key_exists('robots_follow', $metaInput)
            && ! array_key_exists('schema_type', $metaInput);
    });
});

it('sends rankmath mapped wp meta fields when site reports rankmath provider', function () {
    Http::fake([
        'https://wp.example/*' => Http::response(['ok' => true, 'wp_post_id' => '779'], 200),
    ]);

    $draft = makeDeliveryDraft(
        siteOverrides: [
            'seo_provider' => 'rankmath',
            // Keep legacy false flags to ensure provider-based sync still works.
            'supports_meta_title' => false,
            'supports_meta_description' => false,
            'supports_canonical' => false,
            'supports_og_tags' => false,
        ],
        draftOverrides: [
            'seo_twitter_title' => 'Delivery Twitter title',
            'seo_twitter_description' => 'Delivery Twitter description',
        ],
    );

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request): bool {
        $payload = json_decode((string) $request->body(), true);
        $metaInput = (array) ($payload['meta_input'] ?? []);
        $syncableFields = (array) data_get($payload, 'seo_sync.syncable_fields');

        return data_get($payload, 'seo_sync.mode') === 'sync'
            && data_get($payload, 'seo_sync.provider') === 'rankmath'
            && ($metaInput['rank_math_title'] ?? null) === 'Delivery SEO Title'
            && ($metaInput['rank_math_description'] ?? null) === 'Delivery SEO description'
            && ($metaInput['rank_math_focus_keyword'] ?? null) === 'delivery focus keyword'
            && ($metaInput['rank_math_canonical_url'] ?? null) === 'https://wp.example/delivery-draft'
            && ($metaInput['rank_math_facebook_title'] ?? null) === 'Delivery OG title'
            && ($metaInput['rank_math_facebook_description'] ?? null) === 'Delivery OG description'
            && ($metaInput['rank_math_facebook_image'] ?? null) === 'https://cdn.example.test/delivery-og.png'
            && ($metaInput['rank_math_twitter_title'] ?? null) === 'Delivery Twitter title'
            && ($metaInput['rank_math_twitter_description'] ?? null) === 'Delivery Twitter description'
            && in_array('primary_keyword', $syncableFields, true)
            && in_array('seo_twitter_title', $syncableFields, true)
            && in_array('seo_twitter_description', $syncableFields, true);
    });

    $target = wpPublishTargetForDraft($draft);
    expect($target)->not->toBeNull();
    expect((string) $target->seo_sync_status)->toBe('synced');
    expect((string) $target->seo_sync_mode)->toBe('sync');
    expect((string) data_get($target->meta, 'seo_sync.provider'))->toBe('rankmath');
    expect((array) ($target->seo_synced_fields ?? []))->toContain('rank_math_title', 'rank_math_focus_keyword');
});

it('sends aioseo mapped wp meta fields when site reports aioseo provider', function () {
    Http::fake([
        'https://wp.example/*' => Http::response(['ok' => true, 'wp_post_id' => '780'], 200),
    ]);

    $draft = makeDeliveryDraft(
        siteOverrides: [
            'seo_provider' => 'aioseo',
            // Keep legacy false flags to ensure provider-based sync still works.
            'supports_meta_title' => false,
            'supports_meta_description' => false,
            'supports_canonical' => false,
            'supports_og_tags' => false,
        ],
        draftOverrides: [
            'seo_twitter_title' => 'Delivery Twitter title',
            'seo_twitter_description' => 'Delivery Twitter description',
        ],
    );

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request): bool {
        $payload = json_decode((string) $request->body(), true);
        $metaInput = (array) ($payload['meta_input'] ?? []);
        $syncableFields = (array) data_get($payload, 'seo_sync.syncable_fields');

        return data_get($payload, 'seo_sync.mode') === 'sync'
            && data_get($payload, 'seo_sync.provider') === 'aioseo'
            && ($metaInput['_aioseo_title'] ?? null) === 'Delivery SEO Title'
            && ($metaInput['_aioseo_description'] ?? null) === 'Delivery SEO description'
            && ($metaInput['_aioseo_focus_keyphrase'] ?? null) === 'delivery focus keyword'
            && ($metaInput['_aioseo_canonical_url'] ?? null) === 'https://wp.example/delivery-draft'
            && ($metaInput['_aioseo_og_title'] ?? null) === 'Delivery OG title'
            && ($metaInput['_aioseo_og_description'] ?? null) === 'Delivery OG description'
            && ($metaInput['_aioseo_og_image'] ?? null) === 'https://cdn.example.test/delivery-og.png'
            && ($metaInput['_aioseo_twitter_title'] ?? null) === 'Delivery Twitter title'
            && ($metaInput['_aioseo_twitter_description'] ?? null) === 'Delivery Twitter description'
            && in_array('primary_keyword', $syncableFields, true)
            && in_array('seo_twitter_title', $syncableFields, true)
            && in_array('seo_twitter_description', $syncableFields, true);
    });

    $target = wpPublishTargetForDraft($draft);
    expect($target)->not->toBeNull();
    expect((string) $target->seo_sync_status)->toBe('synced');
    expect((string) $target->seo_sync_mode)->toBe('sync');
    expect((string) data_get($target->meta, 'seo_sync.provider'))->toBe('aioseo');
    expect((array) ($target->seo_synced_fields ?? []))->toContain('_aioseo_title', '_aioseo_focus_keyphrase');
});

it('sends argusly mapped wp meta fields when site reports argusly provider', function () {
    Http::fake([
        'https://wp.example/*' => Http::response(['ok' => true, 'wp_post_id' => '783'], 200),
    ]);

    $draft = makeDeliveryDraft(
        siteOverrides: [
            'seo_provider' => 'argusly',
            'supports_meta_title' => true,
            'supports_meta_description' => true,
            'supports_canonical' => true,
            'supports_og_tags' => true,
        ],
        draftOverrides: [
            'seo_twitter_title' => 'Delivery Twitter title',
            'seo_twitter_description' => 'Delivery Twitter description',
        ],
    );

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request): bool {
        $payload = json_decode((string) $request->body(), true);
        $metaInput = (array) ($payload['meta_input'] ?? []);
        $syncableFields = (array) data_get($payload, 'seo_sync.syncable_fields');

        return data_get($payload, 'seo_sync.mode') === 'sync'
            && data_get($payload, 'seo_sync.provider') === 'argusly'
            && ($metaInput['_pl_seo_title'] ?? null) === 'Delivery SEO Title'
            && ($metaInput['_pl_seo_meta_description'] ?? null) === 'Delivery SEO description'
            && ($metaInput['_pl_seo_focus_keyword'] ?? null) === 'delivery focus keyword'
            && ($metaInput['_pl_seo_canonical'] ?? null) === 'https://wp.example/delivery-draft'
            && ($metaInput['_pl_seo_og_title'] ?? null) === 'Delivery OG title'
            && ($metaInput['_pl_seo_og_description'] ?? null) === 'Delivery OG description'
            && ($metaInput['_pl_seo_og_image'] ?? null) === 'https://cdn.example.test/delivery-og.png'
            && ($metaInput['_pl_seo_twitter_title'] ?? null) === 'Delivery Twitter title'
            && ($metaInput['_pl_seo_twitter_description'] ?? null) === 'Delivery Twitter description'
            && in_array('primary_keyword', $syncableFields, true)
            && in_array('seo_twitter_title', $syncableFields, true)
            && in_array('seo_twitter_description', $syncableFields, true);
    });

    $target = wpPublishTargetForDraft($draft);
    expect($target)->not->toBeNull();
    expect((string) $target->seo_sync_status)->toBe('synced');
    expect((string) $target->seo_sync_mode)->toBe('sync');
    expect((string) data_get($target->meta, 'seo_sync.provider'))->toBe('argusly');
    expect((array) ($target->seo_synced_fields ?? []))->toContain('_pl_seo_title', '_pl_seo_meta_description');
});

it('skips focus keyword provider mapping when focus keyword is null', function () {
    Http::fake([
        'https://wp.example/*' => Http::response(['ok' => true, 'wp_post_id' => '781'], 200),
    ]);

    $draft = makeDeliveryDraft(
        siteOverrides: [
            'seo_provider' => 'yoast',
            'supports_meta_title' => true,
            'supports_meta_description' => true,
            'supports_canonical' => true,
            'supports_og_tags' => true,
        ],
        contentOverrides: [
            'primary_keyword' => null,
        ],
    );

    $draft->brief()->update(['primary_keyword' => null]);
    $meta = is_array($draft->meta) ? $draft->meta : [];
    unset($meta['primary_keyword']);
    $draft->update(['meta' => $meta]);

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request): bool {
        $payload = json_decode((string) $request->body(), true);
        $metaInput = (array) ($payload['meta_input'] ?? []);

        return data_get($payload, 'seo_sync.mode') === 'sync'
            && data_get($payload, 'seo_sync.provider') === 'yoast'
            && ! array_key_exists('_yoast_wpseo_focuskw', $metaInput);
    });
});

it('skips wordpress seo meta sync when no supported provider is detected', function () {
    Http::fake([
        'https://wp.example/*' => Http::response(['ok' => true, 'wp_post_id' => '778'], 200),
    ]);

    $draft = makeDeliveryDraft(
        siteOverrides: [
            'seo_provider' => 'none',
            'supports_meta_title' => false,
            'supports_meta_description' => false,
            'supports_canonical' => false,
            'supports_og_tags' => false,
        ]
    );

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request): bool {
        $payload = json_decode((string) $request->body(), true);
        $metaInput = (array) ($payload['meta_input'] ?? []);

        return data_get($payload, 'seo_sync.mode') === 'advisory'
            && data_get($payload, 'seo_sync.provider') === 'none'
            && data_get($metaInput, 'argusly_origin') === 'argusly'
            && is_array(data_get($payload, 'seo_recommendations'));
    });

    $target = wpPublishTargetForDraft($draft);
    expect($target)->not->toBeNull();
    expect((string) $target->seo_sync_status)->toBe('advisory');
    expect((string) $target->seo_sync_mode)->toBe('advisory');
    expect((string) $target->seo_sync_error)->toBe('seo_plugin_not_supported');
    expect((string) data_get($target->meta, 'seo_sync.provider'))->toBe('none');
    expect((string) data_get($target->meta, 'seo_sync.reason'))->toBe('seo_plugin_not_supported');
    expect((string) data_get($target->meta, 'seo_sync.status'))->toBe('advisory');
});

it('tracks seo sync as failed when wordpress delivery fails', function () {
    $draft = makeDeliveryDraft(
        siteOverrides: [
            'seo_provider' => 'yoast',
            'supports_meta_title' => true,
            'supports_meta_description' => true,
            'supports_canonical' => true,
            'supports_og_tags' => true,
        ],
        clientRefs: [
            'draft_webhook_url' => null,
            'draft_webhook_secret' => null,
        ],
    );

    $meta = is_array($draft->meta) ? $draft->meta : [];
    $refs = is_array($meta['client_refs'] ?? null) ? $meta['client_refs'] : [];
    unset($refs['draft_webhook_url'], $refs['draft_webhook_secret']);
    $meta['client_refs'] = $refs;
    $draft->update(['meta' => $meta]);

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeFalse();

    $target = wpPublishTargetForDraft($draft);
    expect($target)->not->toBeNull();
    expect((string) $target->sync_status)->toBe('failed');
    expect((string) $target->seo_sync_status)->toBe('failed');
    expect((string) $target->seo_sync_mode)->toBe('sync');
    expect((string) data_get($target->meta, 'seo_sync.provider'))->toBe('yoast');
    expect((string) data_get($target->meta, 'seo_sync.status'))->toBe('failed');
    expect((string) data_get($target->meta, 'seo_sync.error'))->not->toBe('');
});

it('stores argusly metadata in wordpress post meta for direct api delivery', function () {
    $plainToken = 'arg_site_testtoken_argusly_meta';

    $draft = makeDeliveryDraft([
        'draft_webhook_url' => null,
        'draft_webhook_secret' => null,
    ]);

    $meta = is_array($draft->meta) ? $draft->meta : [];
    $refs = is_array($meta['client_refs'] ?? null) ? $meta['client_refs'] : [];
    unset($refs['draft_webhook_url'], $refs['draft_webhook_secret']);
    $meta['client_refs'] = $refs;
    $draft->update(['meta' => $meta]);

    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $draft->client_site_id,
        'workspace_id' => $draft->clientSite?->workspace_id,
        'name' => 'test key',
        'token_hash' => hash('sha256', $plainToken),
        'token_encrypted' => Crypt::encryptString($plainToken),
        'key_prefix' => substr($plainToken, 0, 14),
        'scopes' => ['*'],
        'abilities' => ['*'],
        'revoked' => false,
        'revoked_at' => null,
    ]);

    Http::fake([
        'https://wp.example/wp-json/argusly/v1/posts' => Http::response([
            'ok' => true,
            'wp_post_id' => '991',
            'url' => 'https://wp.example/?p=991',
        ], 200),
    ]);

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    $publication = \App\Models\ContentPublication::query()
        ->where('content_id', $draft->content_id)
        ->where('client_site_id', $draft->client_site_id)
        ->sole();

    Http::assertSent(function ($request) use ($draft, $publication): bool {
        $payload = json_decode((string) $request->body(), true);

        return data_get($payload, 'meta_input.argusly_content_id') === (string) $draft->content_id
            && data_get($payload, 'meta_input.argusly_publication_id') === (string) $publication->id
            && data_get($payload, 'meta_input.argusly_origin') === 'argusly'
            && data_get($payload, 'wp_post_meta.argusly_content_id') === (string) $draft->content_id
            && data_get($payload, 'wp_post_meta.argusly_publication_id') === (string) $publication->id
            && data_get($payload, 'wp_post_meta.argusly_origin') === 'argusly';
    });
});

it('surfaces connector auth failures during direct api delivery', function () {
    $plainToken = 'arg_site_testtoken_auth_failure';

    $draft = makeDeliveryDraft([
        'draft_webhook_url' => null,
        'draft_webhook_secret' => null,
    ]);

    $meta = is_array($draft->meta) ? $draft->meta : [];
    $refs = is_array($meta['client_refs'] ?? null) ? $meta['client_refs'] : [];
    unset($refs['draft_webhook_url'], $refs['draft_webhook_secret']);
    $meta['client_refs'] = $refs;
    $draft->update(['meta' => $meta]);

    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $draft->client_site_id,
        'workspace_id' => $draft->clientSite?->workspace_id,
        'name' => 'test key',
        'token_hash' => hash('sha256', $plainToken),
        'token_encrypted' => Crypt::encryptString($plainToken),
        'key_prefix' => substr($plainToken, 0, 14),
        'scopes' => ['*'],
        'abilities' => ['*'],
        'revoked' => false,
        'revoked_at' => null,
    ]);

    Http::fake([
        'https://wp.example/wp-json/argusly/v1/posts' => Http::response([
            'message' => 'Invalid token',
        ], 401),
    ]);

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());

    expect($result['ok'])->toBeFalse()
        ->and($result['status'])->toBe(401)
        ->and((string) $result['error'])->toContain('Invalid token');
});

it('surfaces malformed connector responses during direct api delivery', function () {
    $plainToken = 'arg_site_testtoken_malformed_response';

    $draft = makeDeliveryDraft([
        'draft_webhook_url' => null,
        'draft_webhook_secret' => null,
    ]);

    $meta = is_array($draft->meta) ? $draft->meta : [];
    $refs = is_array($meta['client_refs'] ?? null) ? $meta['client_refs'] : [];
    unset($refs['draft_webhook_url'], $refs['draft_webhook_secret']);
    $meta['client_refs'] = $refs;
    $draft->update(['meta' => $meta]);

    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $draft->client_site_id,
        'workspace_id' => $draft->clientSite?->workspace_id,
        'name' => 'test key',
        'token_hash' => hash('sha256', $plainToken),
        'token_encrypted' => Crypt::encryptString($plainToken),
        'key_prefix' => substr($plainToken, 0, 14),
        'scopes' => ['*'],
        'abilities' => ['*'],
        'revoked' => false,
        'revoked_at' => null,
    ]);

    Http::fake([
        'https://wp.example/wp-json/argusly/v1/posts' => Http::response([
            'ok' => true,
            'url' => 'https://wp.example/?p=123',
        ], 200),
    ]);

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());

    expect($result['ok'])->toBeFalse()
        ->and((string) $result['error'])->toContain('post identifier')
        ->and((string) $draft->fresh()->content?->wp_post_id)->toBe('');
});
