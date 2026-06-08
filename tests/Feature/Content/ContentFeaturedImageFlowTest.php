<?php

use App\Jobs\GenerateContentFeaturedImageJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentImage;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SiteToken;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('requires credits and queues featured image generation', function () {
    Queue::fake();

    [$user, $content, $site] = createImageGenerationContext();

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 20,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['reason' => 'test_image_generation']
    );

    $this->actingAs($user)
        ->post(route('app.content.images.featured.generate', $content))
        ->assertRedirect();

    $image = ContentImage::query()
        ->where('content_id', $content->id)
        ->where('type', 'featured')
        ->latest('created_at')
        ->first();

    expect($image)->not->toBeNull()
        ->and($image->status)->toBe('queued');

    Queue::assertPushed(GenerateContentFeaturedImageJob::class, function (GenerateContentFeaturedImageJob $job) use ($image) {
        return (string) $job->contentImageId === (string) $image->id;
    });
});

it('shows a friendly error when featured image credits are insufficient', function () {
    [$user, $content] = createImageGenerationContext();

    $this->actingAs($user)
        ->from(route('app.content.show', ['content' => $content, 'tab' => 'images']))
        ->post(route('app.content.images.featured.generate', $content))
        ->assertRedirect(route('app.content.show', ['content' => $content, 'tab' => 'images']))
        ->assertSessionHasErrors(['image_generate']);
});

it('fails push action gracefully when site connector is missing', function () {
    [$user, $content] = createImageGenerationContext(withSite: false);

    ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'status' => 'ready',
        'is_active' => true,
        'image_path' => 'content-images/test.png',
        'image_url' => 'https://cdn.example.test/content-images/test.png',
        'credit_cost' => 3,
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

    $this->actingAs($user)
        ->from(route('app.content.show', ['content' => $content, 'tab' => 'images']))
        ->post(route('app.content.images.featured.push', $content))
        ->assertRedirect(route('app.content.show', ['content' => $content, 'tab' => 'images']))
        ->assertSessionHasErrors(['image_push']);
});

it('searches unsplash images from the images tab', function () {
    config(['argusly.stock_images.unsplash.access_key' => 'unsplash-test-key']);

    [$user, $content] = createImageGenerationContext();

    Http::fake([
        'https://api.unsplash.com/search/photos*' => Http::response([
            'results' => [
                [
                    'id' => 'unsplash-photo-1',
                    'width' => 1600,
                    'height' => 900,
                    'alt_description' => 'Marketing team planning content',
                    'urls' => [
                        'regular' => 'https://images.unsplash.com/photo-1?w=1080',
                        'small' => 'https://images.unsplash.com/photo-1?w=400',
                    ],
                    'links' => [
                        'html' => 'https://unsplash.com/photos/photo-1',
                        'download_location' => 'https://api.unsplash.com/photos/photo-1/download',
                    ],
                    'user' => [
                        'name' => 'Jane Creator',
                        'links' => ['html' => 'https://unsplash.com/@janecreator'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $this->actingAs($user)
        ->get(route('app.content.show', [
            'content' => $content,
            'tab' => 'images',
            'stock_image_query' => 'marketing',
        ]))
        ->assertOk()
        ->assertSee('Jane Creator')
        ->assertSee('Use photo');

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return str_starts_with($request->url(), 'https://api.unsplash.com/search/photos')
            && $request['query'] === 'marketing'
            && $request->hasHeader('Accept-Version', 'v1');
    });
});

it('uses an unsplash photo as active featured image with attribution and download tracking', function () {
    config(['argusly.stock_images.unsplash.access_key' => 'unsplash-test-key']);

    [$user, $content] = createImageGenerationContext();

    ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'status' => 'ready',
        'is_active' => true,
        'image_url' => 'https://cdn.example.test/old.png',
        'credit_cost' => 3,
    ]);

    Http::fake([
        'https://api.unsplash.com/photos/photo-1/download' => Http::response([
            'url' => 'https://images.unsplash.com/photo-1?tracked=true',
        ], 200),
    ]);

    $this->actingAs($user)
        ->post(route('app.content.images.featured.unsplash', $content), [
            'photo' => [
                'id' => 'unsplash-photo-1',
                'query' => 'marketing',
                'urls' => [
                    'regular' => 'https://images.unsplash.com/photo-1?w=1080',
                    'small' => 'https://images.unsplash.com/photo-1?w=400',
                ],
                'links' => [
                    'html' => 'https://unsplash.com/photos/photo-1',
                    'download_location' => 'https://api.unsplash.com/photos/photo-1/download',
                ],
                'user' => [
                    'name' => 'Jane Creator',
                    'links' => ['html' => 'https://unsplash.com/@janecreator'],
                ],
                'alt_description' => 'Marketing team planning content',
                'width' => 1600,
                'height' => 900,
            ],
        ])
        ->assertRedirect(route('app.content.show', ['content' => $content, 'tab' => 'images']))
        ->assertSessionHasNoErrors();

    $image = ContentImage::query()
        ->where('content_id', (string) $content->id)
        ->where('provider', 'unsplash')
        ->firstOrFail();

    expect($image->is_active)->toBeTrue()
        ->and($image->credit_cost)->toBe(0)
        ->and($image->image_url)->toBe('https://images.unsplash.com/photo-1?w=1080')
        ->and(data_get($image->metadata, 'attribution.photographer_name'))->toBe('Jane Creator')
        ->and(data_get($image->metadata, 'attribution.provider_name'))->toBe('Unsplash')
        ->and(data_get($image->metadata, 'download_location'))->toBe('https://api.unsplash.com/photos/photo-1/download');

    expect(ContentImage::query()
        ->where('content_id', (string) $content->id)
        ->where('provider', '!=', 'unsplash')
        ->where('is_active', true)
        ->exists())->toBeFalse();

    Http::assertSent(fn (\Illuminate\Http\Client\Request $request): bool => $request->url() === 'https://api.unsplash.com/photos/photo-1/download');
});

it('rejects tampered unsplash image urls before download tracking', function () {
    config(['argusly.stock_images.unsplash.access_key' => 'unsplash-test-key']);

    [$user, $content] = createImageGenerationContext();

    Http::fake();

    $this->actingAs($user)
        ->from(route('app.content.show', ['content' => $content, 'tab' => 'images']))
        ->post(route('app.content.images.featured.unsplash', $content), [
            'photo' => [
                'id' => 'unsplash-photo-1',
                'urls' => [
                    'regular' => 'https://evil.example.test/photo.jpg',
                ],
                'links' => [
                    'html' => 'https://unsplash.com/photos/photo-1',
                    'download_location' => 'https://api.unsplash.com/photos/photo-1/download',
                ],
                'user' => [
                    'name' => 'Jane Creator',
                    'links' => ['html' => 'https://unsplash.com/@janecreator'],
                ],
            ],
        ])
        ->assertRedirect(route('app.content.show', ['content' => $content, 'tab' => 'images']))
        ->assertSessionHasErrors(['stock_image']);

    expect(ContentImage::query()->where('content_id', (string) $content->id)->where('provider', 'unsplash')->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('pushes featured image payload with draft_id to wordpress webhook', function () {
    [$user, $content, $site, $draft] = createImageGenerationContext(withSite: true, withDraftConnectorRefs: true);

    ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'status' => 'ready',
        'is_active' => true,
        'image_path' => 'content-images/test.png',
        'image_url' => 'https://cdn.example.test/content-images/test.png',
        'credit_cost' => 3,
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

    Http::fake([
        'https://wp.example.com/wp-json/argusly/v1/posts/987/featured-image' => Http::response([
            'ok' => true,
            'attachment_id' => 4321,
            'featured_image_id' => 4321,
            'featured_image_url' => 'https://wp.example.com/wp-content/uploads/test.png',
        ], 200),
    ]);

    $this->actingAs($user)
        ->post(route('app.content.images.featured.push', $content))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($content, $draft) {
        if ($request->url() !== 'https://wp.example.com/wp-json/argusly/v1/posts/987/featured-image') {
            return false;
        }

        $payload = json_decode((string) $request->body(), true);

        return data_get($payload, 'image_url') === 'https://cdn.example.test/content-images/test.png'
            && data_get($payload, 'content_id') === (string) $content->id
            && data_get($payload, 'draft_id') === (string) ($draft?->id ?? '')
            && data_get($payload, 'wp_post_id') === '987'
            && data_get($payload, 'featured_image_attribution') === 'Photo by Jane Creator on Unsplash'
            && str_starts_with((string) data_get($payload, 'image_attribution.photographer_url'), 'https://unsplash.com/@janecreator')
            && str_contains((string) data_get($payload, 'image_attribution.photographer_url'), 'utm_source=argusly');
    });

    $image = ContentImage::query()->where('content_id', $content->id)->where('type', 'featured')->firstOrFail();
    expect(data_get($image->metadata, 'wp.attachment_id'))->toBe('4321');
});

it('ensures wp_post_id before pushing featured image when mapping is missing', function () {
    [$user, $content, $site, $draft] = createImageGenerationContext(withSite: true, withDraftConnectorRefs: false);

    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'workspace_id' => $site->workspace_id,
        'name' => 'test key',
        'token_hash' => hash('sha256', 'pl_site_testtoken_ensure'),
        'token_encrypted' => Crypt::encryptString('pl_site_testtoken_ensure'),
        'key_prefix' => 'pl_site_testtok',
        'scopes' => ['*'],
        'abilities' => ['*'],
        'revoked' => false,
        'revoked_at' => null,
    ]);

    Draft::query()
        ->where('content_id', $content->id)
        ->delete();

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => (string) $site->id,
        'content_id' => (string) $content->id,
        'status' => 'queued',
        'progress' => 0,
        'title' => 'Featured Brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'content_id' => (string) $content->id,
        'client_site_id' => (string) $site->id,
        'status' => 'ready_to_deliver',
        'delivery_status' => 'failed',
        'title' => 'Draft',
        'output_type' => 'kb_article',
        'content_html' => '<p>Draft</p>',
        'meta' => [
            'client_refs' => [],
        ],
        'links' => [],
        'credit_cost' => 6,
    ]);

    ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'status' => 'ready',
        'is_active' => true,
        'image_path' => 'content-images/test.png',
        'image_url' => 'https://cdn.example.test/content-images/test.png',
        'credit_cost' => 3,
    ]);

    Http::fake([
        'https://images.example.com/wp-json/argusly/v1/posts' => Http::response([
            'ok' => true,
            'post_id' => '321',
            'wp_post_id' => '321',
            'url' => 'https://images.example.com/?p=321',
        ], 200),
        'https://images.example.com/wp-json/argusly/v1/posts/321/featured-image' => Http::response([
            'ok' => true,
            'attachment_id' => '777',
            'featured_image_id' => '777',
        ], 200),
    ]);

    $this->actingAs($user)
        ->post(route('app.content.images.featured.push', $content))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        return $request->url() === 'https://images.example.com/wp-json/argusly/v1/posts';
    });

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($content, $draft) {
        if ($request->url() !== 'https://images.example.com/wp-json/argusly/v1/posts/321/featured-image') {
            return false;
        }

        $payload = json_decode((string) $request->body(), true);

        return data_get($payload, 'content_id') === (string) $content->id
            && data_get($payload, 'draft_id') === (string) $draft->id
            && data_get($payload, 'wp_post_id') === '321';
    });

    expect((string) $content->fresh()->wp_post_id)->toBe('321');
    expect((string) data_get($draft->fresh()->meta, 'client_refs.wp_post_id'))->toBe('321');

    $this->assertDatabaseHas('content_publish_targets', [
        'content_id' => (string) $content->id,
        'client_site_id' => (string) $site->id,
        'target_type' => 'wp',
        'wp_post_id' => '321',
        'wp_featured_media_id' => '777',
    ]);
});

it('allows image push action for laravel site type when connector is configured', function () {
    [$user, $content, $site, $draft] = createImageGenerationContext(withSite: true, withDraftConnectorRefs: true);
    $site->update(['type' => 'laravel']);

    ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'status' => 'ready',
        'is_active' => true,
        'image_path' => 'content-images/test.png',
        'image_url' => 'https://cdn.example.test/content-images/test.png',
        'credit_cost' => 3,
    ]);

    Http::fake([
        'https://wp.example.com/wp-json/argusly/v1/posts/987/featured-image' => Http::response(['ok' => true], 200),
    ]);

    $this->actingAs($user)
        ->post(route('app.content.images.featured.push', $content))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($content, $draft) {
        if ($request->url() !== 'https://wp.example.com/wp-json/argusly/v1/posts/987/featured-image') {
            return false;
        }

        $payload = json_decode((string) $request->body(), true);

        return data_get($payload, 'event') === 'content.featured_image'
            && data_get($payload, 'content_id') === (string) $content->id
            && data_get($payload, 'draft_id') === (string) ($draft?->id ?? '');
    });
});

it('uses medium non-webp path by default for wordpress payload', function () {
    Storage::fake('public');
    Storage::disk('public')->put('content-images/test-medium.jpg', 'test-medium');

    [$user, $content, $site] = createImageGenerationContext(withSite: true, withDraftConnectorRefs: true);
    $site->update(['capabilities' => ['image_formats' => ['webp' => false]]]);

    ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'status' => 'ready',
        'is_active' => true,
        'image_path' => 'content-images/test-original.jpg',
        'original_path' => 'content-images/test-original.jpg',
        'medium_path' => 'content-images/test-medium.jpg',
        'medium_webp_path' => 'content-images/test-medium.webp',
        'image_url' => 'https://cdn.example.test/content-images/test-original.jpg',
        'credit_cost' => 3,
    ]);

    Http::fake([
        'https://wp.example.com/wp-json/argusly/v1/posts/987/featured-image' => Http::response(['ok' => true], 200),
    ]);

    $this->actingAs($user)
        ->post(route('app.content.images.featured.push', $content))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        $payload = json_decode((string) $request->body(), true);

        return data_get($payload, 'featured_image_path') === 'content-images/test-medium.jpg'
            && data_get($payload, 'featured_image_mime') === 'image/jpeg'
            && str_ends_with((string) data_get($payload, 'featured_image_url'), '/storage/content-images/test-medium.jpg');
    });
});

it('uses medium webp path for wordpress payload when site supports webp', function () {
    Storage::fake('public');
    Storage::disk('public')->put('content-images/test-medium.webp', 'test-medium-webp');

    [$user, $content, $site] = createImageGenerationContext(withSite: true, withDraftConnectorRefs: true);
    $site->update(['capabilities' => ['image_formats' => ['webp' => true]]]);

    ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'status' => 'ready',
        'is_active' => true,
        'image_path' => 'content-images/test-original.jpg',
        'original_path' => 'content-images/test-original.jpg',
        'medium_path' => 'content-images/test-medium.jpg',
        'medium_webp_path' => 'content-images/test-medium.webp',
        'image_url' => 'https://cdn.example.test/content-images/test-original.jpg',
        'credit_cost' => 3,
    ]);

    Http::fake([
        'https://wp.example.com/wp-json/argusly/v1/posts/987/featured-image' => Http::response(['ok' => true], 200),
    ]);

    $this->actingAs($user)
        ->post(route('app.content.images.featured.push', $content))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        $payload = json_decode((string) $request->body(), true);

        return data_get($payload, 'featured_image_path') === 'content-images/test-medium.webp'
            && data_get($payload, 'featured_image_mime') === 'image/webp'
            && str_ends_with((string) data_get($payload, 'featured_image_url'), '/storage/content-images/test-medium.webp');
    });
});

/**
 * @return array{0:User,1:Content,2:?ClientSite,3:?Draft}
 */
function createImageGenerationContext(bool $withSite = true, bool $withDraftConnectorRefs = false): array
{
    $organization = Organization::query()->create([
        'name' => 'Image Org',
        'slug' => 'image-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Image Org BV',
        'billing_address_line1' => 'Damrak 1',
        'billing_postal_code' => '1000AA',
        'billing_city' => 'Amsterdam',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Image Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = null;
    if ($withSite) {
        $site = ClientSite::query()->create([
            'workspace_id' => $workspace->id,
            'type' => 'wordpress',
            'name' => 'Image Site',
            'site_url' => 'https://images.example.com',
            'allowed_domains' => ['images.example.com'],
            'is_active' => true,
        ]);
    }

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'test-plan'],
        [
            'name' => 'Test Plan',
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
        'client_site_id' => $site?->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => $site?->id,
        'title' => 'AI Image Generation for Blog',
        'primary_keyword' => 'ai image generation',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
    ]);

    $user = User::query()->create([
        'name' => 'Image Owner',
        'email' => 'image-owner-'.Str::random(6).'@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $draft = null;
    if ($withSite && $withDraftConnectorRefs && $site) {
        $brief = Brief::query()->create([
            'id' => (string) Str::uuid(),
            'client_site_id' => (string) $site->id,
            'content_id' => (string) $content->id,
            'status' => 'queued',
            'progress' => 0,
            'title' => 'Featured Brief',
            'language' => 'en',
            'output_type' => 'kb_article',
        ]);

        $draft = Draft::query()->create([
            'id' => (string) Str::uuid(),
            'brief_id' => (string) $brief->id,
            'content_id' => (string) $content->id,
            'client_site_id' => (string) $site->id,
            'status' => 'ready',
            'title' => 'Draft',
            'output_type' => 'kb_article',
            'content_html' => '<p>Draft</p>',
            'meta' => [
                'client_refs' => [
                    'draft_webhook_url' => 'https://wp.example.com/pl-webhook',
                    'draft_webhook_secret' => 'supersecret',
                    'wp_post_id' => '987',
                ],
            ],
            'links' => [],
            'credit_cost' => 6,
        ]);
    }

    return [$user, $content, $site, $draft];
}
