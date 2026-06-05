<?php

use App\Jobs\DeliverDraftJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\SiteToken;
use App\Models\Workspace;
use App\Services\DraftDelivery\DeliveryLockService;
use App\Services\DraftDelivery\DeliverDraftToWordPress;
use App\Services\DraftDelivery\PayloadChecksumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('acquires a delivery lock successfully', function () {
    $lockService = new DeliveryLockService();
    $contentId = (string) Str::uuid();
    $destinationId = (string) Str::uuid();

    $lock = $lockService->acquireDeliveryLock($contentId, $destinationId);

    expect($lock)->not->toBeNull();
    expect($lockService->isLocked($contentId, $destinationId))->toBeTrue();

    $lockService->releaseLock($lock);
    expect($lockService->isLocked($contentId, $destinationId))->toBeFalse();
});

it('prevents concurrent lock acquisition', function () {
    $lockService = new DeliveryLockService();
    $contentId = (string) Str::uuid();
    $destinationId = (string) Str::uuid();

    $firstLock = $lockService->acquireDeliveryLock($contentId, $destinationId);
    expect($firstLock)->not->toBeNull();

    $secondLock = $lockService->acquireDeliveryLock($contentId, $destinationId);
    expect($secondLock)->toBeNull();

    $lockService->releaseLock($firstLock);

    $thirdLock = $lockService->acquireDeliveryLock($contentId, $destinationId);
    expect($thirdLock)->not->toBeNull();

    $lockService->releaseLock($thirdLock);
});

it('allows different content to have separate locks', function () {
    $lockService = new DeliveryLockService();
    $destinationId = (string) Str::uuid();

    $lockOne = $lockService->acquireDeliveryLock((string) Str::uuid(), $destinationId);
    $lockTwo = $lockService->acquireDeliveryLock((string) Str::uuid(), $destinationId);

    expect($lockOne)->not->toBeNull();
    expect($lockTwo)->not->toBeNull();

    $lockService->releaseLock($lockOne);
    $lockService->releaseLock($lockTwo);
});

it('executes callback with lock and releases automatically', function () {
    $lockService = new DeliveryLockService();
    $contentId = (string) Str::uuid();
    $destinationId = (string) Str::uuid();

    $result = $lockService->withDeliveryLock(
        $contentId,
        $destinationId,
        fn () => 'callback_result'
    );

    expect($result['acquired'])->toBeTrue();
    expect($result['result'])->toBe('callback_result');
    expect($lockService->isLocked($contentId, $destinationId))->toBeFalse();
});

it('releases lock even when callback throws exception', function () {
    $lockService = new DeliveryLockService();
    $contentId = (string) Str::uuid();
    $destinationId = (string) Str::uuid();

    try {
        $lockService->withDeliveryLock(
            $contentId,
            $destinationId,
            fn () => throw new RuntimeException('Test exception')
        );
    } catch (RuntimeException) {
        // expected
    }

    expect($lockService->isLocked($contentId, $destinationId))->toBeFalse();
});

it('calculates consistent checksums for identical payloads', function () {
    $service = new PayloadChecksumService();

    $payload = [
        'title' => 'Test Title',
        'content_html' => '<p>Test content</p>',
        'slug' => 'test-slug',
    ];

    $checksumOne = $service->calculateChecksum($payload);
    $checksumTwo = $service->calculateChecksum($payload);

    expect($checksumOne)->toBe($checksumTwo);
    expect(strlen($checksumOne))->toBe(64);
});

it('produces different checksums for different content', function () {
    $service = new PayloadChecksumService();

    $checksumOne = $service->calculateChecksum([
        'title' => 'Title 1',
        'content_html' => '<p>Content 1</p>',
    ]);

    $checksumTwo = $service->calculateChecksum([
        'title' => 'Title 2',
        'content_html' => '<p>Content 2</p>',
    ]);

    expect($checksumOne)->not->toBe($checksumTwo);
});

it('normalizes whitespace for consistent checksums', function () {
    $service = new PayloadChecksumService();

    $checksumOne = $service->calculateChecksum([
        'title' => '  Test Title  ',
        'content_html' => "Line1\r\nLine2",
    ]);

    $checksumTwo = $service->calculateChecksum([
        'title' => 'Test Title',
        'content_html' => "Line1\nLine2",
    ]);

    expect($checksumOne)->toBe($checksumTwo);
});

it('skips delivery when checksum matches', function () {
    $service = new PayloadChecksumService();
    $payload = ['title' => 'Test', 'content_html' => '<p>Test</p>'];
    $storedChecksum = $service->calculateChecksum($payload);

    $result = $service->shouldSkipDelivery($payload, $storedChecksum);

    expect($result['skip'])->toBeTrue();
    expect($result['reason'])->toBe('checksum_unchanged');
});

it('forces delivery when forceDelivery is true', function () {
    $service = new PayloadChecksumService();
    $payload = ['title' => 'Test', 'content_html' => '<p>Test</p>'];
    $storedChecksum = $service->calculateChecksum($payload);

    $result = $service->shouldSkipDelivery($payload, $storedChecksum, forceDelivery: true);

    expect($result['skip'])->toBeFalse();
    expect($result['reason'])->toBe('force_delivery_requested');
});

it('does not skip when no previous checksum exists', function () {
    $service = new PayloadChecksumService();

    $result = $service->shouldSkipDelivery([
        'title' => 'Test',
        'content_html' => '<p>Test</p>',
    ], null);

    expect($result['skip'])->toBeFalse();
    expect($result['reason'])->toBe('no_previous_checksum');
});

it('does not skip when checksum has changed', function () {
    $service = new PayloadChecksumService();
    $storedChecksum = $service->calculateChecksum([
        'title' => 'Old Title',
        'content_html' => '<p>Old</p>',
    ]);

    $result = $service->shouldSkipDelivery([
        'title' => 'New Title',
        'content_html' => '<p>New</p>',
    ], $storedChecksum);

    expect($result['skip'])->toBeFalse();
    expect($result['reason'])->toBe('checksum_changed');
});

it('does not create duplicate remote posts when a concurrent delivery is already running', function () {
    Http::fake([
        'https://wp.example/webhook' => Http::response([
            'ok' => true,
            'wp_post_id' => '901',
            'url' => 'https://wp.example/?p=901',
        ], 200),
    ]);

    $draft = makeConcurrencyDeliveryDraft();
    $locale = $draft->fresh()->language->value;
    $lock = Cache::lock("delivery_lock:{$draft->content_id}:{$draft->client_site_id}:{$locale}", 300);
    expect($lock->get())->toBeTrue();

    $blockedResult = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());

    expect($blockedResult['ok'])->toBeFalse();
    expect($blockedResult['skipped'] ?? false)->toBeTrue();
    expect($blockedResult['reason'] ?? null)->toBe('concurrent_lock');
    Http::assertNothingSent();

    $lock->release();

    $successfulResult = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($successfulResult['ok'])->toBeTrue();

    $publication = ContentPublication::query()
        ->where('content_id', $draft->content_id)
        ->where('client_site_id', $draft->client_site_id)
        ->sole();

    expect($publication->remote_id)->toBe('901');
    expect($publication->delivery_status)->toBe('delivered');
    expect(ContentPublication::query()->where('content_id', $draft->content_id)->count())->toBe(1);

    Http::assertSentCount(1);
});

it('preserves the publication mapping across a temporary retry failure', function () {
    $draft = makeConcurrencyDeliveryDraft([
        'draft_webhook_url' => null,
        'draft_webhook_secret' => null,
        'wp_post_id' => '131',
    ]);

    disableConcurrencyDraftWebhook($draft);
    attachConcurrencySiteToken($draft, 'pl_site_retry_safe');

    $draft->content()->update([
        'wp_post_id' => '131',
        'published_url' => 'https://wp.example/?p=131',
    ]);

    $publication = seedConcurrencyPublication($draft, '131', 'https://wp.example/?p=131');

    $postAttempts = 0;

    Http::fake(function ($request) use (&$postAttempts) {
        $url = (string) $request->url();

        if ($request->method() === 'GET' && $url === 'https://wp.example/wp-json/publishlayer/v1/posts/131') {
            return Http::response([
                'ok' => true,
                'wp_post_id' => '131',
                'url' => 'https://wp.example/?p=131',
            ], 200);
        }

        if ($request->method() === 'POST' && $url === 'https://wp.example/wp-json/publishlayer/v1/posts/131') {
            $postAttempts++;

            if ($postAttempts === 1) {
                return Http::response([
                    'message' => 'Temporary upstream timeout',
                ], 503);
            }

            return Http::response([
                'ok' => true,
                'wp_post_id' => '131',
                'post_id' => '131',
                'status' => 'publish',
                'url' => 'https://wp.example/?p=131',
            ], 200);
        }

        return Http::response(['message' => 'Unexpected request'], 500);
    });

    expect(fn () => Bus::dispatchSync(new DeliverDraftJob((string) $draft->id)))
        ->toThrow(RuntimeException::class);

    $publication->refresh();
    $draft->refresh();

    expect($publication->remote_id)->toBe('131');
    expect($publication->delivery_status)->toBe('failed');
    expect($draft->delivery_status)->toBe('failed');
    expect((string) $draft->content()->first()?->wp_post_id)->toBe('131');

    Bus::dispatchSync(new DeliverDraftJob((string) $draft->id));

    $publication->refresh();
    $draft->refresh();

    expect($publication->remote_id)->toBe('131');
    expect($publication->delivery_status)->toBe('delivered');
    expect($draft->delivery_status)->toBe('delivered');
    expect((string) $draft->content()->first()?->wp_post_id)->toBe('131');
    expect(ContentPublication::query()->where('content_id', $draft->content_id)->count())->toBe(1);

    Http::assertNotSent(function ($request) {
        return $request->method() === 'POST'
            && (string) $request->url() === 'https://wp.example/wp-json/publishlayer/v1/posts';
    });
});

it('recreates safely after the remote wordpress post has been deleted', function () {
    $draft = makeConcurrencyDeliveryDraft([
        'draft_webhook_url' => null,
        'draft_webhook_secret' => null,
        'wp_post_id' => '131',
        'remote_draft_id' => '131',
    ]);

    disableConcurrencyDraftWebhook($draft);
    attachConcurrencySiteToken($draft, 'pl_site_missing_remote_safe');

    $draft->content()->update([
        'wp_post_id' => '131',
        'published_url' => 'https://wp.example/?p=131',
    ]);

    $publication = seedConcurrencyPublication($draft, '131', 'https://wp.example/?p=131');

    Http::fake([
        'https://wp.example/wp-json/publishlayer/v1/posts/131' => Http::response([
            'code' => 'rest_post_invalid_id',
        ], 404),
        'https://wp.example/?rest_route=/publishlayer/v1/posts/131' => Http::response([
            'code' => 'rest_post_invalid_id',
        ], 404),
        'https://wp.example/wp-json/wp/v2/posts/131?context=edit' => Http::response([
            'code' => 'rest_post_invalid_id',
        ], 404),
        'https://wp.example/wp-json/wp/v2/posts/131' => Http::response([
            'code' => 'rest_post_invalid_id',
        ], 404),
        'https://wp.example/wp-json/publishlayer/v1/posts' => Http::response([
            'ok' => true,
            'wp_post_id' => '245',
            'post_id' => '245',
            'url' => 'https://wp.example/?p=245',
        ], 200),
    ]);

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    $publication->refresh();
    $draft->refresh();

    expect($publication->remote_id)->toBe('245');
    expect($publication->delivery_status)->toBe('delivered');
    expect((array) data_get($publication->meta, 'previous_remote_ids', []))->toContain('131');
    expect((string) $draft->content()->first()?->wp_post_id)->toBe('245');
    expect(ContentPublication::query()->where('content_id', $draft->content_id)->count())->toBe(1);

    Http::assertNotSent(function ($request) {
        return $request->method() === 'POST'
            && (string) $request->url() === 'https://wp.example/wp-json/publishlayer/v1/posts/131';
    });

    Http::assertSent(function ($request) use ($draft) {
        if ($request->method() !== 'POST' || (string) $request->url() !== 'https://wp.example/wp-json/publishlayer/v1/posts') {
            return false;
        }

        $payload = json_decode((string) $request->body(), true);

        return ! array_key_exists('wp_post_id', $payload)
            && ($payload['id'] ?? null) === (string) $draft->content_id;
    });
});

it('updates the same remote post on repeated forced republishes without creating duplicates', function () {
    $draft = makeConcurrencyDeliveryDraft([
        'draft_webhook_url' => null,
        'draft_webhook_secret' => null,
        'wp_post_id' => '131',
        'remote_draft_id' => '131',
    ]);

    disableConcurrencyDraftWebhook($draft);
    attachConcurrencySiteToken($draft, 'pl_site_force_republish_safe');

    $draft->content()->update([
        'wp_post_id' => '131',
        'published_url' => 'https://wp.example/?p=131',
    ]);

    $publication = seedConcurrencyPublication($draft, '131', 'https://wp.example/?p=131');

    Http::fake([
        'https://wp.example/wp-json/publishlayer/v1/posts/131' => Http::response([
            'ok' => true,
            'wp_post_id' => '131',
            'post_id' => '131',
            'status' => 'publish',
            'url' => 'https://wp.example/?p=131',
        ], 200),
        'https://wp.example/wp-json/publishlayer/v1/posts' => Http::response([
            'ok' => true,
            'wp_post_id' => '999',
            'post_id' => '999',
            'url' => 'https://wp.example/?p=999',
        ], 200),
    ]);

    $service = app(DeliverDraftToWordPress::class);

    expect($service->deliver($draft->fresh(), forceDelivery: true)['ok'])->toBeTrue();
    expect($service->deliver($draft->fresh(), forceDelivery: true)['ok'])->toBeTrue();

    $publication->refresh();

    expect($publication->remote_id)->toBe('131');
    expect($publication->delivery_status)->toBe('delivered');
    expect(ContentPublication::query()->where('content_id', $draft->content_id)->count())->toBe(1);

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && (string) $request->url() === 'https://wp.example/wp-json/publishlayer/v1/posts/131';
    });

    Http::assertNotSent(function ($request) {
        return $request->method() === 'POST'
            && (string) $request->url() === 'https://wp.example/wp-json/publishlayer/v1/posts';
    });
});

it('recovers an existing wordpress post by publishlayer meta when the local mapping is missing', function () {
    $draft = makeConcurrencyDeliveryDraft([
        'draft_webhook_url' => null,
        'draft_webhook_secret' => null,
    ]);

    disableConcurrencyDraftWebhook($draft);
    attachConcurrencySiteToken($draft, 'pl_site_meta_recovery_safe');

    Http::fake([
        'https://wp.example/wp-json/publishlayer/v1/posts/lookup*' => Http::response([
            'items' => [[
                'post_id' => '515',
                'wp_post_id' => '515',
                'link' => 'https://wp.example/?p=515',
                'status' => 'publish',
            ]],
        ], 200),
        'https://wp.example/wp-json/publishlayer/v1/posts/515' => Http::response([
            'ok' => true,
            'post_id' => '515',
            'wp_post_id' => '515',
            'status' => 'publish',
            'url' => 'https://wp.example/?p=515',
        ], 200),
        'https://wp.example/wp-json/publishlayer/v1/posts' => Http::response([
            'ok' => true,
            'post_id' => '999',
            'wp_post_id' => '999',
            'url' => 'https://wp.example/?p=999',
        ], 200),
    ]);

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    $publication = ContentPublication::query()
        ->where('content_id', $draft->content_id)
        ->where('client_site_id', $draft->client_site_id)
        ->sole();

    expect($publication->remote_id)->toBe('515')
        ->and($publication->delivery_status)->toBe('delivered')
        ->and((string) $draft->fresh()->content?->wp_post_id)->toBe('515');

    Http::assertSent(function ($request): bool {
        return $request->method() === 'GET'
            && str_contains((string) $request->url(), '/wp-json/publishlayer/v1/posts/lookup');
    });
    Http::assertSent(function ($request): bool {
        return $request->method() === 'POST'
            && (string) $request->url() === 'https://wp.example/wp-json/publishlayer/v1/posts/515';
    });
    Http::assertNotSent(function ($request): bool {
        return $request->method() === 'POST'
            && (string) $request->url() === 'https://wp.example/wp-json/publishlayer/v1/posts';
    });
});

it('skips the remote wordpress update when the payload hash is unchanged', function () {
    $draft = makeConcurrencyDeliveryDraft([
        'draft_webhook_url' => null,
        'draft_webhook_secret' => null,
        'wp_post_id' => '131',
        'remote_draft_id' => '131',
    ]);

    disableConcurrencyDraftWebhook($draft);
    attachConcurrencySiteToken($draft, 'pl_site_skip_unchanged_safe');

    $draft->content()->update([
        'wp_post_id' => '131',
        'published_url' => 'https://wp.example/?p=131',
    ]);

    seedConcurrencyPublication($draft, '131', 'https://wp.example/?p=131');

    Http::fake([
        'https://wp.example/wp-json/publishlayer/v1/posts/131' => Http::response([
            'ok' => true,
            'post_id' => '131',
            'wp_post_id' => '131',
            'status' => 'publish',
            'url' => 'https://wp.example/?p=131',
        ], 200),
    ]);

    $service = app(DeliverDraftToWordPress::class);

    expect($service->deliver($draft->fresh(), forceDelivery: true)['ok'])->toBeTrue();

    $second = $service->deliver($draft->fresh());

    expect($second['ok'])->toBeTrue()
        ->and($second['skipped'] ?? false)->toBeTrue()
        ->and($second['reason'] ?? null)->toBe('checksum_unchanged');

    Http::assertSentCount(5);
    Http::assertSent(function ($request): bool {
        return $request->method() === 'POST'
            && (string) $request->url() === 'https://wp.example/wp-json/publishlayer/v1/posts/131';
    });
    Http::assertNotSent(function ($request): bool {
        return $request->method() === 'POST'
            && (string) $request->url() === 'https://wp.example/wp-json/publishlayer/v1/posts'
            && str_contains((string) $request->body(), 'Concurrency test content');
    });
});

it('updates the existing wordpress post when content changes and never appends the previous body', function () {
    $draft = makeConcurrencyDeliveryDraft([
        'draft_webhook_url' => null,
        'draft_webhook_secret' => null,
        'wp_post_id' => '131',
        'remote_draft_id' => '131',
    ]);

    disableConcurrencyDraftWebhook($draft);
    attachConcurrencySiteToken($draft, 'pl_site_update_changed_safe');

    $draft->content()->update([
        'wp_post_id' => '131',
        'published_url' => 'https://wp.example/?p=131',
    ]);

    seedConcurrencyPublication($draft, '131', 'https://wp.example/?p=131');

    $sentBodies = [];

    Http::fake(function ($request) use (&$sentBodies) {
        $url = (string) $request->url();

        if ($request->method() === 'GET' && $url === 'https://wp.example/wp-json/publishlayer/v1/posts/131') {
            return Http::response([
                'ok' => true,
                'post_id' => '131',
                'wp_post_id' => '131',
                'status' => 'publish',
                'url' => 'https://wp.example/?p=131',
            ], 200);
        }

        if ($request->method() === 'POST' && $url === 'https://wp.example/wp-json/publishlayer/v1/posts/131') {
            $sentBodies[] = json_decode((string) $request->body(), true);

            return Http::response([
                'ok' => true,
                'post_id' => '131',
                'wp_post_id' => '131',
                'status' => 'publish',
                'url' => 'https://wp.example/?p=131',
            ], 200);
        }

        return Http::response(['message' => 'Unexpected request'], 500);
    });

    $service = app(DeliverDraftToWordPress::class);

    $draft->update(['content_html' => '<p>Old body.</p>']);
    expect($service->deliver($draft->fresh(), forceDelivery: true)['ok'])->toBeTrue();

    $draft->update(['content_html' => '<p>New body only.</p>']);
    expect($service->deliver($draft->fresh(), forceDelivery: true)['ok'])->toBeTrue();

    expect($sentBodies)->toHaveCount(2)
        ->and(data_get($sentBodies, '1.content'))->toBe('<p>New body only.</p>')
        ->and((string) data_get($sentBodies, '1.content'))->not->toContain('Old body');

    Http::assertNotSent(function ($request): bool {
        return $request->method() === 'POST'
            && (string) $request->url() === 'https://wp.example/wp-json/publishlayer/v1/posts';
    });
});

it('keeps dutch and english wordpress publications stable per locale on the same site', function () {
    $dutchDraft = makeConcurrencyDeliveryDraft([
        'draft_webhook_url' => null,
        'draft_webhook_secret' => null,
    ], [], [
        'language' => 'nl',
    ], [
        'language' => 'nl',
    ]);

    disableConcurrencyDraftWebhook($dutchDraft);
    attachConcurrencySiteToken($dutchDraft, 'pl_site_locale_safe');

    $englishContent = Content::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $dutchDraft->content?->workspace_id,
        'client_site_id' => $dutchDraft->client_site_id,
        'title' => 'English variant',
        'language' => 'en',
        'translation_source_content_id' => $dutchDraft->content_id,
        'translation_source_locale' => 'nl',
        'is_source_locale' => false,
        'primary_keyword' => 'concurrency',
        'type' => 'article',
        'status' => 'brief',
        'source' => 'wp',
        'external_key' => 'concurrency-en-' . Str::lower(Str::random(6)),
        'delivery_status' => 'pending',
        'generation_mode' => 'balanced',
    ]);

    $englishBrief = Brief::create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $dutchDraft->client_site_id,
        'content_id' => $englishContent->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Concurrency Brief EN',
        'primary_keyword' => 'concurrency',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $englishDraft = Draft::create([
        'id' => (string) Str::uuid(),
        'brief_id' => $englishBrief->id,
        'content_id' => $englishContent->id,
        'client_site_id' => $dutchDraft->client_site_id,
        'status' => 'ready_to_deliver',
        'delivery_status' => 'pending',
        'title' => 'Concurrency Draft EN',
        'language' => 'en',
        'seo_title' => 'Concurrency EN SEO Title',
        'seo_meta_description' => 'Concurrency EN SEO description',
        'seo_h1' => 'Concurrency EN H1',
        'seo_canonical' => 'https://wp.example/concurrency-draft-en',
        'seo_og_title' => 'Concurrency EN OG title',
        'seo_og_description' => 'Concurrency EN OG description',
        'seo_og_image' => 'https://cdn.example.test/concurrency-og-en.png',
        'robots_index' => false,
        'robots_follow' => true,
        'schema_type' => 'Article',
        'output_type' => 'kb_article',
        'content_html' => '<p>English body.</p>',
        'meta' => ['client_refs' => []],
    ]);

    Http::fake([
        'https://wp.example/wp-json/publishlayer/v1/posts/lookup*' => Http::response([
            'exists' => false,
        ], 200),
        'https://wp.example/wp-json/publishlayer/v1/posts' => Http::sequence()
            ->push([
                'ok' => true,
                'post_id' => '201',
                'wp_post_id' => '201',
                'status' => 'publish',
                'url' => 'https://wp.example/nl/article',
            ], 200)
            ->push([
                'ok' => true,
                'post_id' => '202',
                'wp_post_id' => '202',
                'status' => 'publish',
                'url' => 'https://wp.example/en/article',
            ], 200),
        'https://wp.example/wp-json/publishlayer/v1/posts/202' => Http::response([
            'ok' => true,
            'post_id' => '202',
            'wp_post_id' => '202',
            'status' => 'publish',
            'url' => 'https://wp.example/en/article',
        ], 200),
        'https://wp.example/wp-json/publishlayer/v1/posts/201' => Http::response([
            'ok' => true,
            'post_id' => '201',
            'wp_post_id' => '201',
            'status' => 'publish',
            'url' => 'https://wp.example/nl/article',
        ], 200),
    ]);

    $service = app(DeliverDraftToWordPress::class);

    expect($service->deliver($dutchDraft->fresh())['ok'])->toBeTrue();
    expect($service->deliver($englishDraft->fresh())['ok'])->toBeTrue();

    $englishDraft->update(['content_html' => '<p>English body updated.</p>']);
    expect($service->deliver($englishDraft->fresh(), forceDelivery: true)['ok'])->toBeTrue();

    $publications = ContentPublication::query()
        ->whereIn('content_id', [$dutchDraft->content_id, $englishDraft->content_id])
        ->orderBy('locale')
        ->get()
        ->keyBy(fn (ContentPublication $publication) => $publication->locale?->value ?? $publication->getRawOriginal('locale'));

    expect((string) $publications['nl']->remote_id)->toBe('201')
        ->and((string) $publications['en']->remote_id)->toBe('202');

    Http::assertNotSent(function ($request): bool {
        return $request->method() === 'POST'
            && (string) $request->url() === 'https://wp.example/wp-json/publishlayer/v1/posts/201'
            && str_contains((string) $request->body(), 'English body updated.');
    });
});

it('stores payload checksum in the publication after a successful delivery', function () {
    Http::fake([
        'https://wp.example/webhook' => Http::response([
            'ok' => true,
            'wp_post_id' => '456',
            'url' => 'https://wp.example/?p=456',
        ], 200),
    ]);

    $draft = makeConcurrencyDeliveryDraft();

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeTrue();

    $publication = ContentPublication::query()
        ->where('content_id', $draft->content_id)
        ->where('client_site_id', $draft->client_site_id)
        ->sole();

    expect($publication->payload_checksum)->not->toBeNull();
    expect(strlen((string) $publication->payload_checksum))->toBe(64);
});

it('releases the delivery lock after a failed delivery attempt', function () {
    Http::fake([
        'https://wp.example/webhook' => Http::response([
            'message' => 'Server error',
        ], 500),
    ]);

    $draft = makeConcurrencyDeliveryDraft();
    $lockKey = "delivery_lock:{$draft->content_id}:{$draft->client_site_id}:{$draft->fresh()->language->value}";

    $result = app(DeliverDraftToWordPress::class)->deliver($draft->fresh());
    expect($result['ok'])->toBeFalse();

    $lock = Cache::lock($lockKey, 1);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('exposes the forceDelivery flag on the draft delivery job', function () {
    expect((new DeliverDraftJob((string) Str::uuid()))->forceDelivery)->toBeFalse();
    expect((new DeliverDraftJob((string) Str::uuid(), forceDelivery: true))->forceDelivery)->toBeTrue();
});

function makeConcurrencyDeliveryDraft(
    array $clientRefs = [],
    array $siteOverrides = [],
    array $draftOverrides = [],
    array $contentOverrides = []
): Draft {
    $suffix = Str::lower(Str::random(6));

    $organization = Organization::create([
        'name' => 'Concurrency Org ' . $suffix,
        'slug' => 'concurrency-org-' . $suffix,
        'status' => 'active',
    ]);

    $workspace = Workspace::create([
        'name' => 'Concurrency Workspace ' . $suffix,
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create(array_merge([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Concurrency Site ' . $suffix,
        'site_url' => 'https://wp.example',
        'base_url' => 'https://wp.example',
        'allowed_domains' => ['wp.example'],
        'is_active' => true,
        'status' => 'connected',
    ], $siteOverrides));

    $content = Content::create(array_merge([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Concurrency Content ' . $suffix,
        'primary_keyword' => 'concurrency',
        'type' => 'article',
        'status' => 'brief',
        'source' => 'wp',
        'external_key' => 'concurrency-key-' . $suffix,
        'delivery_status' => 'pending',
        'generation_mode' => 'balanced',
    ], $contentOverrides));

    $brief = Brief::create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Concurrency Brief ' . $suffix,
        'primary_keyword' => 'concurrency',
        'language' => 'nl',
        'output_type' => 'kb_article',
    ]);

    return Draft::create(array_merge([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'ready_to_deliver',
        'delivery_status' => 'pending',
        'title' => 'Concurrency Draft ' . $suffix,
        'seo_title' => 'Concurrency SEO Title',
        'seo_meta_description' => 'Concurrency SEO description',
        'seo_h1' => 'Concurrency H1',
        'seo_canonical' => 'https://wp.example/concurrency-draft',
        'seo_og_title' => 'Concurrency OG title',
        'seo_og_description' => 'Concurrency OG description',
        'seo_og_image' => 'https://cdn.example.test/concurrency-og.png',
        'robots_index' => false,
        'robots_follow' => true,
        'schema_type' => 'Article',
        'output_type' => 'kb_article',
        'content_html' => '<p>Concurrency test content</p>',
        'meta' => [
            'client_refs' => array_merge([
                'draft_webhook_url' => 'https://wp.example/webhook',
                'draft_webhook_secret' => 'topsecret',
            ], $clientRefs),
        ],
    ], $draftOverrides));
}

function disableConcurrencyDraftWebhook(Draft $draft): void
{
    $meta = is_array($draft->meta) ? $draft->meta : [];
    $refs = is_array($meta['client_refs'] ?? null) ? $meta['client_refs'] : [];

    unset($refs['draft_webhook_url'], $refs['draft_webhook_secret']);

    $meta['client_refs'] = $refs;
    $draft->update(['meta' => $meta]);
}

function attachConcurrencySiteToken(Draft $draft, string $plainToken): void
{
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $draft->client_site_id,
        'workspace_id' => $draft->clientSite?->workspace_id,
        'name' => 'Concurrency Test Token',
        'token_hash' => hash('sha256', $plainToken),
        'token_encrypted' => Crypt::encryptString($plainToken),
        'key_prefix' => substr($plainToken, 0, 14),
        'scopes' => ['*'],
        'abilities' => ['*'],
        'revoked' => false,
        'revoked_at' => null,
    ]);
}

function seedConcurrencyPublication(Draft $draft, string $remoteId, ?string $remoteUrl = null): ContentPublication
{
    $publication = ContentPublication::resolveForDelivery(
        (string) $draft->content_id,
        $draft->content?->content_destination_id,
        (string) $draft->client_site_id
    );

    $publication->markDelivered($remoteId, $remoteUrl);

    return $publication->fresh();
}
