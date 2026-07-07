<?php

use App\Models\Content;
use App\Models\ContentImage;
use App\Models\Campaign;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\SocialPostVariant;
use App\Models\SocialPublication;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PublicBlog\PublicBlogPerformanceDataService;
use App\Services\Seo\SeoMetadataService;
use App\Support\SocialImageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('uploads a reusable content image asset with explicit usage flags', function (): void {
    Storage::fake('content_images');

    [$user, $content] = createContentImageAssetContext();

    $this->actingAs($user)
        ->post(route('app.content.images.upload', $content), [
            'image' => UploadedFile::fake()->image('meta-share.jpg', 1200, 630)->size(512),
            'alt_text' => 'Meta image alt',
            'use_as_meta_image' => '1',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $image = ContentImage::query()
        ->where('content_id', (string) $content->id)
        ->where('source', ContentImage::SOURCE_UPLOAD)
        ->firstOrFail();

    expect($image->use_as_meta_image)->toBeTrue()
        ->and($image->display_on_website)->toBeFalse()
        ->and($image->display_as_featured_image)->toBeFalse()
        ->and($image->width)->toBe(1200)
        ->and($image->height)->toBe(630)
        ->and($image->mime_type)->toBe('image/jpeg');

    expect($image->image_url)->toContain('/content-images/')
        ->and($image->image_url)->not->toContain('/storage/content-images/');

    Storage::disk('content_images')->assertExists((string) $image->image_path);
});

it('rejects non-image uploads for content image assets', function (): void {
    Storage::fake('content_images');

    [$user, $content] = createContentImageAssetContext();

    $this->actingAs($user)
        ->from(route('app.content.show', ['content' => $content, 'tab' => 'images']))
        ->post(route('app.content.images.upload', $content), [
            'image' => UploadedFile::fake()->create('notes.pdf', 32, 'application/pdf'),
            'use_as_meta_image' => '1',
        ])
        ->assertRedirect(route('app.content.show', ['content' => $content, 'tab' => 'images']))
        ->assertSessionHasErrors(['image']);

    expect(ContentImage::query()->where('content_id', (string) $content->id)->count())->toBe(0);
});

it('shows image asset usage metadata in the content editor', function (): void {
    [$user, $content] = createContentImageAssetContext();

    ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $content->workspace_id,
        'content_id' => (string) $content->id,
        'type' => 'social',
        'source' => ContentImage::SOURCE_GENERATED,
        'provider' => 'pl-renderer',
        'image_url' => 'https://cdn.example.test/generated-linkedin.jpg',
        'status' => 'ready',
        'is_active' => true,
        'use_as_social_image' => true,
        'use_for_linkedin' => true,
        'credit_cost' => 0,
    ]);

    $this->actingAs($user)
        ->get(route('app.content.show', ['content' => $content, 'tab' => 'images']))
        ->assertOk()
        ->assertSee('Website image')
        ->assertSee('Meta image')
        ->assertSee('LinkedIn image')
        ->assertSee('Available image assets')
        ->assertSee('Save usage targets')
        ->assertSee('name="image"', false)
        ->assertSee('name="display_on_website"', false)
        ->assertSee('name="use_as_meta_image"', false)
        ->assertSee('name="use_for_linkedin"', false)
        ->assertSee(route('app.content.images.usage.update', [
            'content' => $content,
            'imageVersion' => ContentImage::query()->firstOrFail(),
        ]), false);
});

it('persists usage flags when selecting an existing content image asset', function (): void {
    [$user, $content] = createContentImageAssetContext();

    $image = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $content->workspace_id,
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'source' => ContentImage::SOURCE_STOCK,
        'provider' => 'unsplash',
        'image_url' => 'https://images.unsplash.com/photo-selected',
        'status' => 'ready',
        'is_active' => true,
        'display_on_website' => true,
        'display_as_featured_image' => true,
        'credit_cost' => 0,
    ]);

    $this->actingAs($user)
        ->post(route('app.content.images.usage.update', ['content' => $content, 'imageVersion' => $image]), [
            'use_as_meta_image' => '1',
            'use_for_linkedin' => '1',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $image->refresh();

    expect($image->display_on_website)->toBeFalse()
        ->and($image->display_as_featured_image)->toBeFalse()
        ->and($image->use_as_meta_image)->toBeTrue()
        ->and($image->use_as_social_image)->toBeTrue()
        ->and($image->use_for_linkedin)->toBeTrue()
        ->and($image->type)->toBe('og');
});

it('allows an active featured image asset to be disabled for content', function (): void {
    [$user, $content] = createContentImageAssetContext();

    $image = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $content->workspace_id,
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'source' => ContentImage::SOURCE_GENERATED,
        'provider' => 'openai',
        'image_url' => 'https://cdn.example.test/featured.png',
        'status' => 'ready',
        'is_active' => true,
        'display_on_website' => true,
        'display_as_featured_image' => true,
        'credit_cost' => 0,
    ]);

    $this->actingAs($user)
        ->post(route('app.content.images.usage.update', ['content' => $content, 'imageVersion' => $image]), [])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $image->refresh();

    expect($image->display_on_website)->toBeFalse()
        ->and($image->display_as_featured_image)->toBeFalse()
        ->and($image->is_active)->toBeFalse()
        ->and($content->refresh()->featuredImage)->toBeNull();
});

it('shows a disable featured image control for active website images', function (): void {
    [$user, $content] = createContentImageAssetContext();

    $image = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $content->workspace_id,
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'source' => ContentImage::SOURCE_GENERATED,
        'provider' => 'openai',
        'image_url' => 'https://cdn.example.test/featured.png',
        'status' => 'ready',
        'is_active' => true,
        'display_on_website' => true,
        'display_as_featured_image' => true,
        'credit_cost' => 0,
    ]);

    $this->actingAs($user)
        ->get(route('app.content.show', ['content' => $content, 'tab' => 'images']))
        ->assertOk()
        ->assertSee('Disable featured image')
        ->assertSee(route('app.content.images.usage.update', ['content' => $content, 'imageVersion' => $image]), false);
});

it('uploads campaign image assets with persisted usage flags', function (): void {
    Storage::fake('content_images');

    [$user, , $workspace] = createContentImageAssetContext();
    $campaign = Campaign::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
    ]);

    $this->actingAs($user)
        ->post(route('app.campaigns.images.upload', $campaign), [
            'image' => UploadedFile::fake()->image('campaign-linkedin.jpg', 1200, 627)->size(600),
            'alt_text' => 'Campaign LinkedIn image',
            'use_for_linkedin' => '1',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $image = ContentImage::query()
        ->where('campaign_id', (string) $campaign->id)
        ->firstOrFail();

    expect($image->use_for_linkedin)->toBeTrue()
        ->and($image->use_as_social_image)->toBeTrue()
        ->and($image->display_on_website)->toBeFalse()
        ->and($image->source)->toBe(ContentImage::SOURCE_UPLOAD)
        ->and($image->alt_text)->toBe('Campaign LinkedIn image');
});

it('persists usage flags when selecting an existing campaign image asset', function (): void {
    [$user, , $workspace] = createContentImageAssetContext();
    $campaign = Campaign::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
    ]);

    $image = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'campaign_id' => (string) $campaign->id,
        'type' => 'featured',
        'source' => ContentImage::SOURCE_STOCK,
        'provider' => 'unsplash',
        'image_url' => 'https://images.unsplash.com/campaign',
        'status' => 'ready',
        'is_active' => true,
        'display_on_website' => true,
        'display_as_featured_image' => true,
        'credit_cost' => 0,
    ]);

    $this->actingAs($user)
        ->post(route('app.campaigns.images.usage.update', ['campaign' => $campaign, 'imageVersion' => $image]), [
            'use_as_social_image' => '1',
            'use_for_linkedin' => '1',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $image->refresh();

    expect($image->display_on_website)->toBeFalse()
        ->and($image->display_as_featured_image)->toBeFalse()
        ->and($image->use_as_social_image)->toBeTrue()
        ->and($image->use_for_linkedin)->toBeTrue()
        ->and($image->type)->toBe('social');
});

it('requires at least one usage target when uploading an image asset', function (): void {
    Storage::fake('content_images');

    [$user, $content] = createContentImageAssetContext();

    $this->actingAs($user)
        ->from(route('app.content.show', ['content' => $content, 'tab' => 'images']))
        ->post(route('app.content.images.upload', $content), [
            'image' => UploadedFile::fake()->image('untargeted.jpg', 1200, 630)->size(512),
        ])
        ->assertRedirect(route('app.content.show', ['content' => $content, 'tab' => 'images']))
        ->assertSessionHasErrors(['image_upload']);

    expect(ContentImage::query()->where('content_id', (string) $content->id)->count())->toBe(0);
});

it('keeps meta-only uploaded assets out of public blog display while using them for SEO', function (): void {
    Storage::fake('content_images');

    [, $content] = createContentImageAssetContext();

    $metaImage = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $content->workspace_id,
        'content_id' => (string) $content->id,
        'type' => 'og',
        'source' => ContentImage::SOURCE_UPLOAD,
        'provider' => 'upload',
        'image_path' => 'content-images/uploads/meta-only.jpg',
        'image_url' => 'https://cdn.example.test/meta-only.jpg',
        'original_path' => 'content-images/uploads/meta-only.jpg',
        'status' => 'ready',
        'is_active' => true,
        'use_as_meta_image' => true,
        'display_on_website' => false,
        'display_as_featured_image' => false,
        'credit_cost' => 0,
    ]);

    $blogPayload = app(PublicBlogPerformanceDataService::class)->syncContent($content->fresh(), persist: false);
    $seo = app(SeoMetadataService::class)->forContent($content->fresh());

    expect($blogPayload['public_blog_featured_image_url'])->toBeNull()
        ->and($seo['og_image'])->toBe($metaImage->original_ui_url);
});

it('renders content image asset src values from public content images instead of storage symlinks', function (): void {
    Storage::fake('content_images');

    [$user, $content] = createContentImageAssetContext();

    Storage::disk('content_images')->put('content-images/rendered-medium.jpg', 'medium-image');

    ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $content->workspace_id,
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'source' => ContentImage::SOURCE_GENERATED,
        'provider' => 'openai',
        'image_path' => 'content-images/rendered-original.jpg',
        'image_url' => 'http://localhost/storage/content-images/rendered-original.jpg',
        'original_path' => 'storage/content-images/rendered-original.jpg',
        'medium_path' => 'content-images/rendered-medium.jpg',
        'status' => 'ready',
        'is_active' => true,
        'display_on_website' => true,
        'display_as_featured_image' => true,
        'credit_cost' => 0,
    ]);

    $this->actingAs($user)
        ->get(route('app.content.show', ['content' => $content, 'tab' => 'images']))
        ->assertOk()
        ->assertSee('src="'.asset('content-images/rendered-medium.jpg'), false)
        ->assertDontSee('/storage/content-images/rendered-medium.jpg', false)
        ->assertDontSee('/storage/content-images/rendered-original.jpg', false);
});

it('serves content image URLs from persistent public storage when the public symlink is missing', function (): void {
    Storage::fake('public');
    config()->set('domains.base', 'argusly.local');

    Storage::disk('public')->put('content-images/fallback/featured.png', 'image-bytes');

    $response = $this->get('https://argusly.local/content-images/fallback/featured.png')
        ->assertOk()
        ->assertContent('image-bytes');

    expect($response->headers->get('Cache-Control'))
        ->toContain('public')
        ->toContain('max-age=31536000')
        ->toContain('immutable');
});

it('serves content image URLs from the configured image disk', function (): void {
    Storage::fake('content_images');
    Storage::fake('public');
    config()->set('argusly.images.disk', 'content_images');
    config()->set('domains.base', 'argusly.local');

    Storage::disk('content_images')->put('content-images/generated/featured.png', 'image-bytes');

    $this->get('https://app.argusly.local/content-images/generated/featured.png')
        ->assertOk()
        ->assertContent('image-bytes');
});

it('shows reusable image assets from linked locale variants', function (): void {
    [$user, $source] = createContentImageAssetContext();
    $target = createLinkedLocaleContentVariant($source, 'nl');

    $sourceImage = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $source->workspace_id,
        'content_id' => (string) $source->id,
        'type' => 'og',
        'source' => ContentImage::SOURCE_GENERATED,
        'provider' => 'pl-renderer',
        'image_url' => 'https://cdn.example.test/en-og.jpg',
        'status' => 'ready',
        'is_active' => true,
        'use_as_meta_image' => true,
        'width' => 1200,
        'height' => 630,
        'credit_cost' => 0,
    ]);

    $this->actingAs($user)
        ->get(route('app.content.show', ['content' => $target, 'tab' => 'images']))
        ->assertOk()
        ->assertSee('Linked locale images')
        ->assertSee('EN · og')
        ->assertSee(route('app.content.images.reuse', ['content' => $target, 'imageVersion' => $sourceImage]), false)
        ->assertSee('Use for this content');
});

it('copies a linked locale image asset onto the current content item', function (): void {
    [$user, $source] = createContentImageAssetContext();
    $target = createLinkedLocaleContentVariant($source, 'nl');

    $sourceImage = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $source->workspace_id,
        'content_id' => (string) $source->id,
        'type' => 'og',
        'source' => ContentImage::SOURCE_GENERATED,
        'provider' => 'pl-renderer',
        'image_path' => 'content-images/en-og.png',
        'image_url' => 'https://cdn.example.test/en-og.png',
        'original_path' => 'content-images/en-og.png',
        'medium_path' => 'content-images/en-og-medium.png',
        'status' => 'ready',
        'is_active' => true,
        'use_as_meta_image' => true,
        'width' => 1200,
        'height' => 630,
        'credit_cost' => 0,
    ]);

    $this->actingAs($user)
        ->post(route('app.content.images.reuse', ['content' => $target, 'imageVersion' => $sourceImage]), [
            'use_as_meta_image' => '1',
            'use_for_linkedin' => '1',
        ])
        ->assertRedirect(route('app.content.show', ['content' => $target, 'tab' => 'images']))
        ->assertSessionHasNoErrors();

    $copy = ContentImage::query()
        ->where('content_id', (string) $target->id)
        ->where('image_url', 'https://cdn.example.test/en-og.png')
        ->firstOrFail();

    expect((string) $copy->id)->not->toBe((string) $sourceImage->id)
        ->and((string) $sourceImage->fresh()->content_id)->toBe((string) $source->id)
        ->and($copy->use_as_meta_image)->toBeTrue()
        ->and($copy->use_as_social_image)->toBeTrue()
        ->and($copy->use_for_linkedin)->toBeTrue()
        ->and($copy->type)->toBe('og')
        ->and(data_get($copy->metadata, 'reused_from.image_id'))->toBe((string) $sourceImage->id)
        ->and(data_get($copy->metadata, 'reused_from.locale'))->toBe('en');
});

it('rejects image asset reuse from unrelated content', function (): void {
    [$user, $target, $workspace] = createContentImageAssetContext();

    $unrelated = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'title' => 'Unrelated Content',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'source' => 'manual',
        'publish_status' => 'published',
    ]);

    $image = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'content_id' => (string) $unrelated->id,
        'type' => 'og',
        'source' => ContentImage::SOURCE_GENERATED,
        'provider' => 'pl-renderer',
        'image_url' => 'https://cdn.example.test/unrelated.png',
        'status' => 'ready',
        'is_active' => true,
        'use_as_meta_image' => true,
        'credit_cost' => 0,
    ]);

    $this->actingAs($user)
        ->from(route('app.content.show', ['content' => $target, 'tab' => 'images']))
        ->post(route('app.content.images.reuse', ['content' => $target, 'imageVersion' => $image]), [
            'use_as_meta_image' => '1',
        ])
        ->assertRedirect(route('app.content.show', ['content' => $target, 'tab' => 'images']))
        ->assertSessionHasErrors(['image_reuse']);

    expect(ContentImage::query()->where('content_id', (string) $target->id)->count())->toBe(0);
});

it('does not use uploaded website-only assets for LinkedIn social publishing', function (): void {
    [$user, $content, $workspace] = createContentImageAssetContext();
    $publication = createLinkedInPublicationForContent($workspace, $content);

    ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'source' => ContentImage::SOURCE_UPLOAD,
        'provider' => 'upload',
        'image_url' => 'https://cdn.example.test/display-only.jpg',
        'status' => 'ready',
        'is_active' => true,
        'display_on_website' => true,
        'display_as_featured_image' => true,
        'use_as_social_image' => false,
        'use_for_linkedin' => false,
        'credit_cost' => 0,
        'created_by' => (string) $user->id,
    ]);

    $resolved = app(SocialImageResolver::class)->resolveForPublication($publication);

    expect($resolved['url'])->not->toBe('https://cdn.example.test/display-only.jpg');
});

it('prefers social-publication LinkedIn assets over content fallbacks', function (): void {
    [, $content, $workspace] = createContentImageAssetContext();
    $publication = createLinkedInPublicationForContent($workspace, $content);

    ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'social_publication_id' => (string) $publication->id,
        'social_post_variant_id' => (string) $publication->social_post_variant_id,
        'type' => 'social',
        'source' => ContentImage::SOURCE_UPLOAD,
        'provider' => 'upload',
        'image_url' => 'https://cdn.example.test/linkedin-publication.jpg',
        'status' => 'ready',
        'is_active' => true,
        'use_as_social_image' => true,
        'use_for_linkedin' => true,
        'credit_cost' => 0,
    ]);

    $resolved = app(SocialImageResolver::class)->resolveForPublication($publication);

    expect($resolved)->toMatchArray([
        'url' => 'https://cdn.example.test/linkedin-publication.jpg',
        'source' => 'publication_linkedin_asset',
    ]);
});

/**
 * @return array{0:User,1:Content,2:Workspace}
 */
function createContentImageAssetContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Asset Org',
        'slug' => 'asset-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Asset Org BV',
        'billing_address_line1' => 'Damrak 1',
        'billing_postal_code' => '1000AA',
        'billing_city' => 'Amsterdam',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Asset Workspace',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'asset-test-plan'],
        [
            'name' => 'Asset Test Plan',
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

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'title' => 'Uploadable Image Assets',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'source' => 'manual',
        'publish_status' => 'published',
        'first_published_at' => now()->subDay(),
        'publish_url_key' => 'uploadable-image-assets',
    ]);
    $content->forceFill(['family_id' => (string) $content->id])->save();

    $user = User::query()->create([
        'name' => 'Asset Owner',
        'email' => 'asset-owner-'.Str::random(6).'@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    return [$user, $content, $workspace];
}

function createLinkedLocaleContentVariant(Content $source, string $locale): Content
{
    return Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $source->workspace_id,
        'family_id' => (string) ($source->family_id ?: $source->id),
        'translation_source_content_id' => (string) $source->id,
        'translation_source_locale' => $source->localeCode(),
        'title' => strtoupper($locale).' linked image variant',
        'language' => $locale,
        'type' => 'article',
        'status' => 'published',
        'source' => 'manual',
        'publish_status' => 'published',
        'first_published_at' => now()->subDay(),
        'publish_url_key' => $locale.'-linked-image-variant',
    ]);
}

function createLinkedInPublicationForContent(Workspace $workspace, Content $content): SocialPublication
{
    $account = SocialAccount::factory()->connected()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
    ]);

    $variant = SocialPostVariant::factory()->approved()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'content_id' => $content->id,
        'social_account_id' => $account->id,
        'campaign_id' => null,
    ]);

    return SocialPublication::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_post_variant_id' => $variant->id,
        'campaign_id' => null,
        'platform' => 'linkedin',
        'payload_snapshot' => [],
    ]);
}
