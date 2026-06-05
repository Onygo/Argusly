<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\ContentSeo;
use App\Models\ContentVersion;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\AiDiscovery\PublicLlmsService;
use App\Services\Content\ContentCacheInvalidationService;
use App\Services\PublicBlog\PublicBlogService;
use App\Support\LocalizedMarketingUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    config()->set('publishlayer.launch.soft_launch_mode', false);
    config()->set('publishlayer_connector.public_blog.use_connector', false);
    config()->set('publishlayer_connector.public_blog.fallback_to_local', true);
    config()->set('marketing.blog_source.mode', 'workspace');

    [$this->workspace, $this->site] = makePublicBlogCacheContext();

    config()->set('marketing.blog_source.id', (string) $this->workspace->id);
});

function makePublicBlogCacheContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Public Cache Org',
        'slug' => 'public-cache-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Public Cache Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => (string) $workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => 'Public Cache Site',
        'site_url' => 'https://public-cache.example.com',
        'base_url' => 'https://public-cache.example.com',
        'allowed_domains' => ['public-cache.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    return [$workspace, $site];
}

function makeBlogContent(Workspace $workspace, ?ClientSite $site = null, array $attributes = []): Content
{
    $locale = (string) ($attributes['language'] ?? 'nl');
    $title = (string) ($attributes['title'] ?? 'Cache test article');
    $slug = (string) ($attributes['slug'] ?? Str::slug($title));
    $status = (string) ($attributes['status'] ?? 'published');
    $publishStatus = (string) ($attributes['publish_status'] ?? ($status === 'published' ? 'published' : 'draft'));
    $publishedUrl = array_key_exists('published_url', $attributes)
        ? $attributes['published_url']
        : 'https://public-cache.example.com' . LocalizedMarketingUrl::route('public.blog.show', ['slug' => $slug], $locale, false);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => $site?->id,
        'title' => $title,
        'language' => $locale,
        'type' => 'article',
        'status' => $status,
        'publish_status' => $publishStatus,
        'delivery_status' => (string) ($attributes['delivery_status'] ?? ($status === 'published' ? 'delivered' : 'pending')),
        'publish_error' => $attributes['publish_error'] ?? null,
        'publish_url_key' => $attributes['publish_url_key'] ?? $slug,
        'published_url' => $status === 'published' ? $publishedUrl : ($attributes['published_url'] ?? null),
        'source' => (string) ($attributes['source'] ?? 'manual'),
        'is_source_locale' => (bool) ($attributes['is_source_locale'] ?? true),
        'seo_title' => $attributes['seo_title'] ?? null,
        'seo_meta_description' => $attributes['seo_meta_description'] ?? null,
        'scheduled_publish_at' => $attributes['scheduled_publish_at'] ?? null,
        'created_at' => $attributes['created_at'] ?? now()->subDay(),
        'updated_at' => $attributes['updated_at'] ?? now(),
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => ContentVersion::TYPE_REVISION,
        'body' => (string) ($attributes['body'] ?? '<p>Cache body</p>'),
        'meta' => array_merge([
            'title' => $title,
            'slug' => $slug,
            'excerpt' => (string) ($attributes['excerpt'] ?? 'Cache excerpt'),
            'published_at' => (($attributes['published_at'] ?? now()->subHour()) instanceof \Carbon\CarbonInterface)
                ? ($attributes['published_at'] ?? now()->subHour())->toIso8601String()
                : (string) ($attributes['published_at'] ?? now()->subHour()->toIso8601String()),
        ], (array) ($attributes['version_meta'] ?? [])),
        'source' => ContentVersion::SOURCE_PUBLISHLAYER,
    ]);

    $content->forceFill([
        'current_version_id' => (string) $version->id,
    ])->save();

    if (array_key_exists('legacy_seo', $attributes) && is_array($attributes['legacy_seo'])) {
        ContentSeo::query()->create(array_merge([
            'content_id' => (string) $content->id,
        ], $attributes['legacy_seo']));
    }

    return $content->fresh(['currentVersion', 'seo']);
}

function makeDraftForContent(Content $content, ClientSite $site, array $attributes = []): Draft
{
    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => (string) $site->id,
        'content_id' => (string) $content->id,
        'status' => 'done',
        'progress' => 1,
        'title' => (string) ($attributes['brief_title'] ?? 'Cache publish brief'),
        'language' => (string) ($attributes['language'] ?? $content->localeCode()),
        'output_type' => 'kb_article',
    ]);

    return Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'content_id' => (string) $content->id,
        'client_site_id' => (string) $site->id,
        'status' => (string) ($attributes['status'] ?? 'ready_to_deliver'),
        'delivery_status' => (string) ($attributes['delivery_status'] ?? 'pending'),
        'title' => (string) ($attributes['title'] ?? $content->title),
        'output_type' => 'kb_article',
        'language' => (string) ($attributes['language'] ?? $content->localeCode()),
        'content_html' => (string) ($attributes['content_html'] ?? '<p>Draft cache body</p>'),
        'seo_canonical' => (string) ($attributes['seo_canonical']
            ?? ('https://public-cache.example.com' . LocalizedMarketingUrl::route('public.blog.show', ['slug' => (string) ($content->publish_url_key ?? Str::slug($content->title))], $content->localeCode(), false))),
    ]);
}

it('shows newly published blog posts without manual cache clearing', function () {
    $content = makeBlogContent($this->workspace, $this->site, [
        'title' => 'Nieuwe zichtbare blog',
        'slug' => 'nieuwe-zichtbare-blog',
        'language' => 'nl',
        'status' => 'draft',
        'publish_status' => 'draft',
        'published_url' => null,
    ]);

    $indexUrl = LocalizedMarketingUrl::route('public.blog.index', [], 'nl', false);

    $this->get($indexUrl)
        ->assertOk()
        ->assertDontSee('Nieuwe zichtbare blog');

    expect(app(PublicLlmsService::class)->render(false, 'nl'))
        ->not->toContain('Nieuwe zichtbare blog');

    $content->update([
        'status' => 'published',
        'publish_status' => 'published',
        'delivery_status' => 'delivered',
        'published_url' => 'https://public-cache.example.com' . LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'nieuwe-zichtbare-blog'], 'nl', false),
    ]);

    $this->get($indexUrl)
        ->assertOk()
        ->assertSee('Nieuwe zichtbare blog');

    expect(app(PublicLlmsService::class)->render(false, 'nl'))
        ->toContain('Nieuwe zichtbare blog');
});

it('shows posts immediately when their canonical Laravel publication is delivered', function () {
    $content = makeBlogContent($this->workspace, $this->site, [
        'title' => 'Publication visible article',
        'slug' => 'publication-visible-article',
        'language' => 'nl',
    ]);

    $publication = ContentPublication::query()->create([
        'content_id' => (string) $content->id,
        'destination_id' => null,
        'client_site_id' => (string) $this->site->id,
        'locale' => 'nl',
        'provider' => ContentPublication::PROVIDER_LARAVEL,
        'remote_status' => ContentPublication::REMOTE_DRAFT,
        'delivery_status' => ContentPublication::STATUS_PENDING,
    ]);

    $indexUrl = LocalizedMarketingUrl::route('public.blog.index', [], 'nl', false);

    $this->get($indexUrl)
        ->assertOk()
        ->assertDontSee('Publication visible article');

    $publication->forceFill([
        'remote_id' => (string) $content->id,
        'remote_url' => 'https://public-cache.example.com' . LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'publication-visible-article'], 'nl', false),
        'remote_status' => ContentPublication::REMOTE_PUBLISHED,
        'delivery_status' => ContentPublication::STATUS_DELIVERED,
        'last_delivered_at' => now(),
    ])->save();

    $this->get($indexUrl)
        ->assertOk()
        ->assertSee('Publication visible article');
});

it('invalidates cached detail pages when the active published body changes', function () {
    $content = makeBlogContent($this->workspace, $this->site, [
        'title' => 'Detail cache test',
        'slug' => 'detail-cache-test',
        'language' => 'nl',
        'body' => '<p>Oude detail body</p>',
    ]);

    $showUrl = LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'detail-cache-test'], 'nl', false);

    $this->get($showUrl)
        ->assertOk()
        ->assertSee('Oude detail body');

    $content->currentVersion->update([
        'body' => '<p>Nieuwe detail body</p>',
    ]);

    $this->get($showUrl)
        ->assertOk()
        ->assertSee('Nieuwe detail body')
        ->assertDontSee('Oude detail body');
});

it('invalidates cached post payloads when legacy content seo changes', function () {
    $content = makeBlogContent($this->workspace, $this->site, [
        'title' => 'SEO cache test',
        'slug' => 'seo-cache-test',
        'language' => 'nl',
        'seo_title' => null,
        'seo_meta_description' => null,
        'legacy_seo' => [
            'meta_title' => 'Oude SEO titel',
            'meta_description' => 'Oude SEO omschrijving',
            'robots_index' => true,
            'robots_follow' => true,
        ],
    ]);

    $blog = app(PublicBlogService::class);

    expect($blog->getPostBySlug('seo-cache-test', 'nl'))
        ->toMatchArray([
            'seo_title' => 'Oude SEO titel',
            'seo_meta_description' => 'Oude SEO omschrijving',
        ]);

    $seo = ContentSeo::query()
        ->where('content_id', (string) $content->id)
        ->firstOrFail();

    $seo->update([
        'meta_title' => 'Nieuwe SEO titel',
        'meta_description' => 'Nieuwe SEO omschrijving',
    ]);

    expect($blog->getPostBySlug('seo-cache-test', 'nl'))
        ->toMatchArray([
            'seo_title' => 'Nieuwe SEO titel',
            'seo_meta_description' => 'Nieuwe SEO omschrijving',
        ]);
});

it('invalidates old detail cache entries after a slug change', function () {
    $content = makeBlogContent($this->workspace, $this->site, [
        'title' => 'Slug cache test',
        'slug' => 'oude-slug',
        'language' => 'nl',
    ]);

    $oldUrl = LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'oude-slug'], 'nl', false);
    $newUrl = LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'nieuwe-slug'], 'nl', false);

    $this->get($oldUrl)
        ->assertOk()
        ->assertSee('Slug cache test');

    $meta = $content->currentVersion->meta ?? [];
    $meta['slug'] = 'nieuwe-slug';
    $content->currentVersion->update(['meta' => $meta]);

    $content->update([
        'publish_url_key' => 'nieuwe-slug',
        'published_url' => 'https://public-cache.example.com' . $newUrl,
    ]);

    $this->get($oldUrl)->assertNotFound();

    $this->get($newUrl)
        ->assertOk()
        ->assertSee('Slug cache test');
});

it('removes unpublished posts from cached listings', function () {
    $content = makeBlogContent($this->workspace, $this->site, [
        'title' => 'Tijdelijk gepubliceerd',
        'slug' => 'tijdelijk-gepubliceerd',
        'language' => 'nl',
    ]);

    $indexUrl = LocalizedMarketingUrl::route('public.blog.index', [], 'nl', false);

    $this->get($indexUrl)
        ->assertOk()
        ->assertSee('Tijdelijk gepubliceerd');

    $content->update([
        'status' => 'draft',
        'publish_status' => 'draft',
        'published_url' => null,
    ]);

    $this->get($indexUrl)
        ->assertOk()
        ->assertDontSee('Tijdelijk gepubliceerd');
});

it('removes posts from cached listings when the Laravel publication is unpublished', function () {
    $content = makeBlogContent($this->workspace, $this->site, [
        'title' => 'Publication unpublished article',
        'slug' => 'publication-unpublished-article',
        'language' => 'nl',
    ]);

    $publication = ContentPublication::query()->create([
        'content_id' => (string) $content->id,
        'destination_id' => null,
        'client_site_id' => (string) $this->site->id,
        'locale' => 'nl',
        'provider' => ContentPublication::PROVIDER_LARAVEL,
        'remote_id' => (string) $content->id,
        'remote_url' => 'https://public-cache.example.com' . LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'publication-unpublished-article'], 'nl', false),
        'remote_status' => ContentPublication::REMOTE_PUBLISHED,
        'delivery_status' => ContentPublication::STATUS_DELIVERED,
        'last_delivered_at' => now()->subMinute(),
    ]);

    $indexUrl = LocalizedMarketingUrl::route('public.blog.index', [], 'nl', false);

    $this->get($indexUrl)
        ->assertOk()
        ->assertSee('Publication unpublished article');

    $publication->forceFill([
        'remote_status' => 'deleted',
        'last_delivered_at' => now(),
    ])->save();

    $this->get($indexUrl)
        ->assertOk()
        ->assertDontSee('Publication unpublished article');
});

it('invalidates cached listings when scheduled publication becomes live', function () {
    $content = makeBlogContent($this->workspace, $this->site, [
        'title' => 'Scheduled cache article',
        'slug' => 'scheduled-cache-article',
        'language' => 'en',
        'status' => 'draft',
        'publish_status' => 'scheduled',
        'scheduled_publish_at' => now()->subMinute(),
        'published_url' => null,
    ]);

    makeDraftForContent($content, $this->site, [
        'language' => 'en',
        'seo_canonical' => 'https://public-cache.example.com' . LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'scheduled-cache-article'], 'en', false),
    ]);

    $indexUrl = LocalizedMarketingUrl::route('public.blog.index', [], 'en', false);

    $this->get($indexUrl)
        ->assertOk()
        ->assertDontSee('Scheduled cache article');

    $this->artisan('content:dispatch-scheduled-publishes --limit=10 --stale-after-minutes=15')
        ->assertExitCode(0);

    $this->get($indexUrl)
        ->assertOk()
        ->assertSee('Scheduled cache article');
});

it('bumps only the affected locale cache version for locale scoped updates', function () {
    $scopeSegment = 'workspace_' . md5((string) $this->workspace->id);

    $nlContent = makeBlogContent($this->workspace, $this->site, [
        'title' => 'Nederlandse cache post',
        'slug' => 'nederlandse-cache-post',
        'language' => 'nl',
    ]);

    makeBlogContent($this->workspace, $this->site, [
        'title' => 'English cache post',
        'slug' => 'english-cache-post',
        'language' => 'en',
    ]);

    Cache::flush();

    $this->get(LocalizedMarketingUrl::route('public.blog.index', [], 'nl', false))->assertOk();
    $this->get(LocalizedMarketingUrl::route('public.blog.index', [], 'en', false))->assertOk();

    $meta = $nlContent->currentVersion->meta ?? [];
    $meta['title'] = 'Nederlandse cache post bijgewerkt';
    $nlContent->currentVersion->update(['meta' => $meta]);

    expect(Cache::get(ContentCacheInvalidationService::publicBlogScopeVersionKey($scopeSegment)))
        ->toBe(2)
        ->and(Cache::get(ContentCacheInvalidationService::publicBlogLocaleVersionKey($scopeSegment, 'nl')))
        ->toBe(2)
        ->and(Cache::get(ContentCacheInvalidationService::publicBlogLocaleVersionKey($scopeSegment, 'en')))
        ->toBeNull();

    $this->get(LocalizedMarketingUrl::route('public.blog.index', [], 'nl', false))
        ->assertOk()
        ->assertSee('Nederlandse cache post bijgewerkt');

    $this->get(LocalizedMarketingUrl::route('public.blog.index', [], 'en', false))
        ->assertOk()
        ->assertSee('English cache post');
});
