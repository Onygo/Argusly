<?php
use App\Jobs\GenerateMarketingBlogTranslationJob;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\MarketingBlogRedirect;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Marketing\MarketingBlogTranslationGenerator;
use App\Support\LocalizedMarketingUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('argusly_connector.public_blog.use_connector', false);
    config()->set('argusly_connector.public_blog.fallback_to_local', true);
    config()->set('cache.default', 'array');

    $organization = Organization::query()->create([
        'name' => 'Backfill Org',
        'slug' => 'backfill-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $this->workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Backfill Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => 'nl',
        'enabled_content_languages' => ['nl', 'en'],
    ]);

    config()->set('marketing.blog_source.mode', 'workspace');
    config()->set('marketing.blog_source.id', (string) $this->workspace->id);
});

function makeMarketingBlogContent(Workspace $workspace, array $attributes = []): Content
{
    $title = (string) ($attributes['title'] ?? 'Blog post ' . Str::random(4));
    $slug = (string) ($attributes['slug'] ?? Str::slug($title));
    $language = (string) ($attributes['language'] ?? 'nl');
    $status = (string) ($attributes['status'] ?? 'published');
    $publishStatus = (string) ($attributes['publish_status'] ?? 'published');
    $localeForUrl = (string) ($attributes['route_locale'] ?? $language);

    $translationSourceContentId = $attributes['translation_source_content_id'] ?? null;

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'title' => $title,
        'language' => $language,
        'translation_source_content_id' => $translationSourceContentId,
        'translation_source_version_id' => $attributes['translation_source_version_id'] ?? null,
        'translation_source_locale' => $attributes['translation_source_locale'] ?? ($translationSourceContentId ? 'nl' : $language),
        'is_source_locale' => (bool) ($attributes['is_source_locale'] ?? ! $translationSourceContentId),
        'translation_generated_at' => $attributes['translation_generated_at'] ?? null,
        'translation_source_updated_at' => $attributes['translation_source_updated_at'] ?? null,
        'source_content_updated_at_snapshot' => $attributes['source_content_updated_at_snapshot'] ?? null,
        'publish_url_key' => $slug,
        'seo_title' => $attributes['seo_title'] ?? null,
        'seo_meta_description' => $attributes['seo_meta_description'] ?? null,
        'seo_og_title' => $attributes['seo_og_title'] ?? null,
        'seo_og_description' => $attributes['seo_og_description'] ?? null,
        'type' => 'article',
        'status' => $status,
        'publish_status' => $publishStatus,
        'source' => 'manual',
        'published_url' => $attributes['published_url']
            ?? ($publishStatus === 'published' ? url("/{$localeForUrl}/blog/{$slug}") : null),
        'seo_canonical' => $attributes['seo_canonical']
            ?? url("/{$localeForUrl}/blog/{$slug}"),
        'created_at' => $attributes['created_at'] ?? now()->subDay(),
        'updated_at' => $attributes['updated_at'] ?? now(),
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'revision',
        'body' => (string) ($attributes['body'] ?? '<p>Body</p>'),
        'meta' => array_merge([
            'excerpt' => (string) ($attributes['excerpt'] ?? 'Excerpt'),
            'slug' => $slug,
            'published_at' => ($attributes['published_at'] ?? now()->subDay())->toIso8601String(),
            'categories' => ['Engineering'],
            'tags' => ['Laravel'],
        ], $attributes['version_meta'] ?? []),
        'source' => 'pl',
    ]);

    $content->forceFill([
        'current_version_id' => (string) $version->id,
    ])->save();

    return $content->fresh(['currentVersion']);
}

function fakeEnglishTranslation(?\Closure $configure = null): void
{
    test()->mock(MarketingBlogTranslationGenerator::class, function ($mock) use ($configure) {
        $expectation = $mock->shouldReceive('generate')->andReturn([
            'title' => 'Scalable Laravel SEO architecture',
            'excerpt' => 'An English translation of the original Dutch article.',
            'body_html' => '<p>This English translation explains how to design a scalable content platform.</p>',
            'seo_title' => 'Scalable Laravel SEO architecture',
            'meta_description' => 'English meta description for the translated article.',
            'seo_og_title' => 'Scalable Laravel SEO architecture',
            'seo_og_description' => 'English OG description for the translated article.',
            'slug' => 'scalable-laravel-seo-architecture',
            'primary_keyword' => 'laravel seo architecture',
            'secondary_keywords' => ['content platform architecture'],
        ]);

        if ($configure) {
            $configure($mock, $expectation);
        }
    });
}

it('normalizes a misplaced en article to a published nl source with the same slug', function () {
    $content = makeMarketingBlogContent($this->workspace, [
        'title' => 'Laravel SEO architectuur: zo ontwerp je een schaalbaar contentplatform',
        'slug' => 'argusly-laravel-seo-architectuur',
        'language' => 'en',
        'route_locale' => 'en',
        'is_source_locale' => true,
        'body' => '<p>Dit artikel legt uit hoe je een schaalbaar contentplatform ontwerpt.</p>',
        'excerpt' => 'Nederlandse uitleg over Laravel SEO architectuur.',
    ]);

    $this->artisan('marketing:backfill-blog-locales', ['--only-misplaced-en' => true])
        ->assertSuccessful();

    $content->refresh();

    expect($content->localeCode())->toBe('nl')
        ->and((bool) $content->is_source_locale)->toBeTrue()
        ->and($content->translation_source_content_id)->toBeNull()
        ->and((string) data_get($content->currentVersion?->meta, 'slug'))->toBe('argusly-laravel-seo-architectuur')
        ->and((string) $content->published_url)->toBe(url('/nl/blog/argusly-laravel-seo-architectuur'))
        ->and((string) $content->seo_canonical)->toBe(url('/nl/blog/argusly-laravel-seo-architectuur'));
});

it('redirects the old en blog url to the new nl route and preserves query strings', function () {
    makeMarketingBlogContent($this->workspace, [
        'title' => 'Laravel SEO architectuur: zo ontwerp je een schaalbaar contentplatform',
        'slug' => 'argusly-laravel-seo-architectuur',
        'language' => 'en',
        'route_locale' => 'en',
        'is_source_locale' => true,
        'body' => '<p>Dit artikel legt uit hoe je een schaalbaar contentplatform ontwerpt.</p>',
        'excerpt' => 'Nederlandse uitleg over Laravel SEO architectuur.',
    ]);

    $this->artisan('marketing:backfill-blog-locales', ['--only-misplaced-en' => true])
        ->assertSuccessful();

    $this->get('/en/blog/argusly-laravel-seo-architectuur?utm_source=test')
        ->assertStatus(301)
        ->assertRedirect('/nl/blog/argusly-laravel-seo-architectuur?utm_source=test');

    $this->assertDatabaseHas('marketing_blog_redirects', [
        'source_path' => '/en/blog/argusly-laravel-seo-architectuur',
        'target_path' => '/nl/blog/argusly-laravel-seo-architectuur',
        'source_locale' => 'en',
        'target_locale' => 'nl',
    ]);
});

it('generates a linked english draft variant by default', function () {
    fakeEnglishTranslation();

    $source = makeMarketingBlogContent($this->workspace, [
        'title' => 'Laravel SEO architectuur: zo ontwerp je een schaalbaar contentplatform',
        'slug' => 'argusly-laravel-seo-architectuur',
        'language' => 'en',
        'route_locale' => 'en',
        'is_source_locale' => true,
        'body' => '<p>Dit artikel legt uit hoe je een schaalbaar contentplatform ontwerpt.</p>',
        'excerpt' => 'Nederlandse uitleg over Laravel SEO architectuur.',
    ]);

    $this->artisan('marketing:backfill-blog-locales', [
        '--only-misplaced-en' => true,
        '--generate-en' => true,
    ])->assertSuccessful();

    $source->refresh();
    $translated = Content::query()
        ->where('translation_source_content_id', $source->id)
        ->where('language', 'en')
        ->first();

    expect($source->localeCode())->toBe('nl')
        ->and($translated)->not->toBeNull()
        ->and((string) $translated->publish_status)->toBe('draft')
        ->and((string) $translated->status)->toBe('draft')
        ->and((string) data_get($translated->currentVersion?->meta, 'slug'))->toBe('scalable-laravel-seo-architecture')
        ->and((string) $translated->translation_source_locale)->toBe('nl')
        ->and($translated->translation_source_content_id)->toBe((string) $source->id)
        ->and($translated->source_content_updated_at_snapshot)->not->toBeNull()
        ->and((string) $translated->currentVersion?->body)->toContain('This English translation explains')
        ->and((string) data_get($translated->currentVersion?->meta, 'excerpt'))->toBe('An English translation of the original Dutch article.');
});

it('publishes the generated english variant when publish-en is set', function () {
    fakeEnglishTranslation();

    $source = makeMarketingBlogContent($this->workspace, [
        'title' => 'Laravel SEO architectuur: zo ontwerp je een schaalbaar contentplatform',
        'slug' => 'argusly-laravel-seo-architectuur',
        'language' => 'en',
        'route_locale' => 'en',
        'is_source_locale' => true,
        'body' => '<p>Dit artikel legt uit hoe je een schaalbaar contentplatform ontwerpt.</p>',
        'excerpt' => 'Nederlandse uitleg over Laravel SEO architectuur.',
    ]);

    $this->artisan('marketing:backfill-blog-locales', [
        '--only-misplaced-en' => true,
        '--generate-en' => true,
        '--publish-en' => true,
    ])->assertSuccessful();

    $source->refresh();
    $translated = Content::query()
        ->where('translation_source_content_id', $source->id)
        ->where('language', 'en')
        ->firstOrFail();

    expect((string) $translated->publish_status)->toBe('published')
        ->and((string) $translated->status)->toBe('published')
        ->and((string) $translated->published_url)->toBe(url('/en/blog/scalable-laravel-seo-architecture'));

    $nlUrl = LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'argusly-laravel-seo-architectuur'], 'nl', false);
    $enUrl = LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'scalable-laravel-seo-architecture'], 'en', false);

    $this->get($nlUrl)
        ->assertOk()
        ->assertSee('hreflang="en"', false);

    $this->get($enUrl)
        ->assertOk()
        ->assertSee('rel="canonical" href="' . url($enUrl) . '"', false)
        ->assertSee('rel="alternate" hreflang="nl" href="' . url($nlUrl) . '"', false)
        ->assertSee('rel="alternate" hreflang="en" href="' . url($enUrl) . '"', false);
});

it('skips english generation when an english variant already exists and skip-if-en-exists is used', function () {
    $source = makeMarketingBlogContent($this->workspace, [
        'title' => 'Nederlandse bron',
        'slug' => 'nederlandse-bron',
        'language' => 'nl',
        'route_locale' => 'nl',
        'is_source_locale' => true,
        'body' => '<p>Dit is de Nederlandse bron.</p>',
        'excerpt' => 'Nederlandse excerpt.',
    ]);

    makeMarketingBlogContent($this->workspace, [
        'title' => 'Existing English variant',
        'slug' => 'existing-english-variant',
        'language' => 'en',
        'route_locale' => 'en',
        'translation_source_content_id' => (string) $source->id,
        'translation_source_version_id' => (string) $source->current_version_id,
        'translation_source_locale' => 'nl',
        'is_source_locale' => false,
        'status' => 'draft',
        'publish_status' => 'draft',
        'body' => '<p>Existing translation.</p>',
        'excerpt' => 'Existing EN excerpt.',
    ]);

    $this->mock(MarketingBlogTranslationGenerator::class, function ($mock) {
        $mock->shouldNotReceive('generate');
    });

    $this->artisan('marketing:backfill-blog-locales', [
        '--article-id' => (string) $source->id,
        '--generate-en' => true,
        '--skip-if-en-exists' => true,
    ])->assertSuccessful();

    expect(Content::query()
        ->where('translation_source_content_id', $source->id)
        ->where('language', 'en')
        ->count())->toBe(1);

    $existing = Content::query()
        ->where('translation_source_content_id', $source->id)
        ->where('language', 'en')
        ->firstOrFail();

    expect((string) $existing->title)->toBe('Existing English variant');
});

it('refreshes an existing english variant when refresh-existing-en is used', function () {
    fakeEnglishTranslation();

    $source = makeMarketingBlogContent($this->workspace, [
        'title' => 'Nederlandse bron',
        'slug' => 'nederlandse-bron',
        'language' => 'nl',
        'route_locale' => 'nl',
        'is_source_locale' => true,
        'body' => '<p>Dit is de Nederlandse bron.</p>',
        'excerpt' => 'Nederlandse excerpt.',
    ]);

    $existing = makeMarketingBlogContent($this->workspace, [
        'title' => 'Outdated English variant',
        'slug' => 'old-english-variant',
        'language' => 'en',
        'route_locale' => 'en',
        'translation_source_content_id' => (string) $source->id,
        'translation_source_version_id' => (string) $source->current_version_id,
        'translation_source_locale' => 'nl',
        'is_source_locale' => false,
        'status' => 'draft',
        'publish_status' => 'draft',
        'body' => '<p>Old translation.</p>',
        'excerpt' => 'Old EN excerpt.',
    ]);

    $this->artisan('marketing:backfill-blog-locales', [
        '--article-id' => (string) $source->id,
        '--generate-en' => true,
        '--refresh-existing-en' => true,
    ])->assertSuccessful();

    $existing->refresh();

    expect((string) $existing->title)->toBe('Scalable Laravel SEO architecture')
        ->and((string) data_get($existing->currentVersion?->meta, 'slug'))->toBe('scalable-laravel-seo-architecture')
        ->and((string) $existing->currentVersion?->body)->toContain('This English translation explains');
});

it('is idempotent when the backfill command is run twice', function () {
    fakeEnglishTranslation();

    $source = makeMarketingBlogContent($this->workspace, [
        'title' => 'Laravel SEO architectuur: zo ontwerp je een schaalbaar contentplatform',
        'slug' => 'argusly-laravel-seo-architectuur',
        'language' => 'en',
        'route_locale' => 'en',
        'is_source_locale' => true,
        'body' => '<p>Dit artikel legt uit hoe je een schaalbaar contentplatform ontwerpt.</p>',
        'excerpt' => 'Nederlandse uitleg over Laravel SEO architectuur.',
    ]);

    $first = [
        '--only-misplaced-en' => true,
        '--generate-en' => true,
        '--skip-if-en-exists' => true,
    ];

    $this->artisan('marketing:backfill-blog-locales', $first)->assertSuccessful();
    $this->artisan('marketing:backfill-blog-locales', $first)->assertSuccessful();

    $source->refresh();

    expect(Content::query()->where('workspace_id', $this->workspace->id)->count())->toBe(2)
        ->and(MarketingBlogRedirect::query()->count())->toBe(1)
        ->and(Content::query()
            ->where('translation_source_content_id', $source->id)
            ->where('language', 'en')
            ->count())->toBe(1);
});

it('queues english generation jobs when the queue flag is used', function () {
    Queue::fake();

    $source = makeMarketingBlogContent($this->workspace, [
        'title' => 'Nederlandse bron',
        'slug' => 'nederlandse-bron',
        'language' => 'nl',
        'route_locale' => 'nl',
        'is_source_locale' => true,
        'body' => '<p>Dit is de Nederlandse bron.</p>',
        'excerpt' => 'Nederlandse excerpt.',
    ]);

    $this->artisan('marketing:backfill-blog-locales', [
        '--article-id' => (string) $source->id,
        '--generate-en' => true,
        '--queue' => true,
        '--publish-en' => true,
    ])->assertSuccessful();

    Queue::assertPushed(GenerateMarketingBlogTranslationJob::class, function ($job) use ($source) {
        return $job->sourceContentId === (string) $source->id
            && $job->publish === true
            && $job->refreshExisting === false;
    });

    expect(Content::query()
        ->where('translation_source_content_id', $source->id)
        ->where('language', 'en')
        ->count())->toBe(0);
});

it('keeps english drafts out of the sitemap', function () {
    fakeEnglishTranslation();

    makeMarketingBlogContent($this->workspace, [
        'title' => 'Laravel SEO architectuur: zo ontwerp je een schaalbaar contentplatform',
        'slug' => 'argusly-laravel-seo-architectuur',
        'language' => 'en',
        'route_locale' => 'en',
        'is_source_locale' => true,
        'body' => '<p>Dit artikel legt uit hoe je een schaalbaar contentplatform ontwerpt.</p>',
        'excerpt' => 'Nederlandse uitleg over Laravel SEO architectuur.',
    ]);

    $this->artisan('marketing:backfill-blog-locales', [
        '--only-misplaced-en' => true,
        '--generate-en' => true,
    ])->assertSuccessful();

    $response = $this->get('/sitemaps/articles.xml');

    $response->assertOk()
        ->assertSee(url('/nl/blog/argusly-laravel-seo-architectuur'), false)
        ->assertDontSee(url('/en/blog/scalable-laravel-seo-architecture'), false);
});

it('includes published english variants in the sitemap', function () {
    fakeEnglishTranslation();

    makeMarketingBlogContent($this->workspace, [
        'title' => 'Laravel SEO architectuur: zo ontwerp je een schaalbaar contentplatform',
        'slug' => 'argusly-laravel-seo-architectuur',
        'language' => 'en',
        'route_locale' => 'en',
        'is_source_locale' => true,
        'body' => '<p>Dit artikel legt uit hoe je een schaalbaar contentplatform ontwerpt.</p>',
        'excerpt' => 'Nederlandse uitleg over Laravel SEO architectuur.',
    ]);

    $this->artisan('marketing:backfill-blog-locales', [
        '--only-misplaced-en' => true,
        '--generate-en' => true,
        '--publish-en' => true,
    ])->assertSuccessful();

    $response = $this->get('/sitemaps/articles.xml');

    $response->assertOk()
        ->assertSee(url('/nl/blog/argusly-laravel-seo-architectuur'), false)
        ->assertSee(url('/en/blog/scalable-laravel-seo-architecture'), false)
        ->assertSee('hreflang="en"', false);
});
