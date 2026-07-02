<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentImage;
use App\Models\ContentVersion;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PublicBlog\ConnectorSynchronizedBlogSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('restores a previous image version as active', function () {
    [$user, $content] = createImageHistoryContext();

    $older = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'status' => 'ready',
        'image_url' => 'https://cdn.example.test/old.png',
        'is_active' => false,
    ]);

    $newer = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'status' => 'ready',
        'image_url' => 'https://cdn.example.test/new.png',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('app.content.images.versions.restore', ['content' => $content, 'imageVersion' => $older]), [
            'image_type' => 'featured',
        ])
        ->assertRedirect();

    expect($older->fresh()->is_active)->toBeTrue()
        ->and($newer->fresh()->is_active)->toBeFalse();
});

it('featuredImage relationship returns restored older version', function () {
    [$user, $content] = createImageHistoryContext();

    // Create older image first (will have earlier created_at)
    $older = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'status' => 'ready',
        'image_url' => 'https://cdn.example.test/old.png',
        'is_active' => false,
        'created_at' => now()->subHour(),
    ]);

    // Create newer image (will have later created_at)
    $newer = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'status' => 'ready',
        'image_url' => 'https://cdn.example.test/new.png',
        'is_active' => true,
        'created_at' => now(),
    ]);

    // Before restore: relationship should return the newer (active) image
    $content->refresh();
    expect($content->featuredImage->id)->toBe($newer->id);

    // Restore the older image
    $this->actingAs($user)
        ->post(route('app.content.images.versions.restore', ['content' => $content, 'imageVersion' => $older]), [
            'image_type' => 'featured',
        ])
        ->assertRedirect();

    // After restore: relationship should return the older image (now active)
    $content->refresh();
    expect($content->featuredImage)->not->toBeNull()
        ->and($content->featuredImage->id)->toBe($older->id)
        ->and($content->featuredImage->image_url)->toBe('https://cdn.example.test/old.png');
});

it('cannot restore image version from another content', function () {
    [$user, $content] = createImageHistoryContext();
    [, $otherContent] = createImageHistoryContext();

    $foreignVersion = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $otherContent->id,
        'type' => 'featured',
        'status' => 'ready',
        'image_url' => 'https://cdn.example.test/foreign.png',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('app.content.images.versions.restore', ['content' => $content, 'imageVersion' => $foreignVersion]), [
            'image_type' => 'featured',
        ])
        ->assertForbidden();
});

it('does not restore image version when restore type does not match', function () {
    [$user, $content] = createImageHistoryContext();

    $older = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'status' => 'ready',
        'image_url' => 'https://cdn.example.test/mismatch-old.png',
        'is_active' => false,
    ]);

    $newer = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'status' => 'ready',
        'image_url' => 'https://cdn.example.test/mismatch-new.png',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->from(route('app.content.show', ['content' => $content, 'tab' => 'images']))
        ->post(route('app.content.images.versions.restore', ['content' => $content, 'imageVersion' => $older]), [
            'image_type' => 'og',
        ])
        ->assertRedirect(route('app.content.show', ['content' => $content, 'tab' => 'images']))
        ->assertSessionHasErrors(['image_restore']);

    expect($older->fresh()->is_active)->toBeFalse()
        ->and($newer->fresh()->is_active)->toBeTrue();
});

it('ogImage relationship returns restored older version', function () {
    [$user, $content] = createImageHistoryContext();

    // Create older OG image first
    $older = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'og',
        'status' => 'ready',
        'image_url' => 'https://cdn.example.test/old-og.png',
        'is_active' => false,
        'created_at' => now()->subHour(),
    ]);

    // Create newer OG image
    $newer = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'og',
        'status' => 'ready',
        'image_url' => 'https://cdn.example.test/new-og.png',
        'is_active' => true,
        'created_at' => now(),
    ]);

    // Before restore: relationship should return the newer (active) image
    $content->refresh();
    expect($content->ogImage->id)->toBe($newer->id);

    // Restore the older image
    $this->actingAs($user)
        ->post(route('app.content.images.versions.restore', ['content' => $content, 'imageVersion' => $older]), [
            'image_type' => 'og',
        ])
        ->assertRedirect();

    // After restore: relationship should return the older image (now active)
    $content->refresh();
    expect($content->ogImage)->not->toBeNull()
        ->and($content->ogImage->id)->toBe($older->id)
        ->and($content->ogImage->image_url)->toBe('https://cdn.example.test/old-og.png');
});

it('push uses active featured image version', function () {
    [$user, $content] = createImageHistoryContext(withConnectorDraft: true);

    ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'status' => 'ready',
        'image_url' => 'https://cdn.example.test/inactive.png',
        'is_active' => false,
    ]);

    $active = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'status' => 'ready',
        'image_url' => 'https://cdn.example.test/active.png',
        'is_active' => true,
    ]);

    Http::fake([
        'https://wp.example.com/wp-json/argusly/v1/posts/987/featured-image' => Http::response(['ok' => true], 200),
    ]);

    $this->actingAs($user)
        ->post(route('app.content.images.featured.push', $content))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($active) {
        $payload = json_decode((string) $request->body(), true);

        return data_get($payload, 'featured_image_url') === (string) $active->image_url;
    });
});

it('marketing blog source prefers active restored featured image over stale version meta', function () {
    [$user, $content] = createImageHistoryContext();

    $content->update([
        'status' => 'published',
        'publish_status' => 'published',
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'revision',
        'body' => '<p>Published body for marketing source.</p>',
        'meta' => [
            'excerpt' => 'Marketing excerpt',
            // Stale meta value should not override active featured image selection.
            'featured_image' => 'https://cdn.example.test/stale-meta-featured.png',
        ],
        'source' => 'pl',
    ]);
    $content->update(['current_version_id' => (string) $version->id]);

    config()->set('marketing.blog_source.mode', 'workspace');
    config()->set('marketing.blog_source.id', (string) $content->workspace_id);

    $older = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'status' => 'ready',
        'image_url' => 'https://cdn.example.test/featured-old.png',
        'is_active' => false,
        'created_at' => now()->subHour(),
    ]);

    $newer = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'status' => 'ready',
        'image_url' => 'https://cdn.example.test/featured-new.png',
        'is_active' => true,
        'created_at' => now(),
    ]);

    $source = app(ConnectorSynchronizedBlogSource::class);

    $before = $source->fetchPublishedPosts();
    expect($before)->toHaveCount(1)
        ->and($before[0]['featured_image'])->toBe('https://cdn.example.test/featured-new.png');

    $this->actingAs($user)
        ->post(route('app.content.images.versions.restore', ['content' => $content, 'imageVersion' => $older]), [
            'image_type' => 'featured',
        ])
        ->assertRedirect();

    expect($older->fresh()->is_active)->toBeTrue()
        ->and($newer->fresh()->is_active)->toBeFalse();

    $after = $source->fetchPublishedPosts();
    expect($after)->toHaveCount(1)
        ->and($after[0]['featured_image'])->toBe('https://cdn.example.test/featured-old.png');
});

it('soft deletes inactive version and preserves file', function () {
    Storage::fake('content_images');

    [$user, $content] = createImageHistoryContext();
    $path = 'content-images/history/test-delete.png';
    Storage::disk('content_images')->put($path, 'fake-image-binary');

    $version = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'status' => 'ready',
        'image_path' => $path,
        'image_url' => '/'.$path,
        'is_active' => false,
    ]);

    $this->actingAs($user)
        ->delete(route('app.content.images.versions.delete', ['content' => $content, 'imageVersion' => $version]))
        ->assertRedirect();

    expect(ContentImage::withTrashed()->find($version->id)?->trashed())->toBeTrue();
    Storage::disk('content_images')->assertExists($path);
});

/**
 * @return array{0:User,1:Content}
 */
function createImageHistoryContext(bool $withConnectorDraft = false): array
{
    $organization = Organization::query()->create([
        'name' => 'Image History Org '.Str::random(4),
        'slug' => 'image-history-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Image History Org BV',
        'billing_address_line1' => 'Damrak 1',
        'billing_postal_code' => '1000AA',
        'billing_city' => 'Amsterdam',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Image History Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Image History Site',
        'site_url' => 'https://images.example.test',
        'allowed_domains' => ['images.example.test'],
        'is_active' => true,
    ]);

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

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'History Post',
        'primary_keyword' => 'history',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
        'wp_post_id' => '987',
    ]);

    if ($withConnectorDraft) {
        $brief = Brief::query()->create([
            'id' => (string) Str::uuid(),
            'client_site_id' => (string) $site->id,
            'content_id' => (string) $content->id,
            'status' => 'queued',
            'progress' => 0,
            'title' => 'History Brief',
            'language' => 'en',
            'output_type' => 'kb_article',
        ]);

        Draft::query()->create([
            'id' => (string) Str::uuid(),
            'brief_id' => (string) $brief->id,
            'content_id' => (string) $content->id,
            'client_site_id' => (string) $site->id,
            'status' => 'ready',
            'title' => 'History Draft',
            'output_type' => 'kb_article',
            'content_html' => '<p>History draft</p>',
            'meta' => [
                'client_refs' => [
                    'draft_webhook_url' => 'https://wp.example.com/pl-webhook',
                    'draft_webhook_secret' => 'supersecret',
                    'wp_post_id' => '987',
                ],
            ],
        ]);
    }

    $user = User::query()->create([
        'name' => 'Image History Owner',
        'email' => 'image-history-'.Str::random(6).'@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    return [$user, $content];
}
