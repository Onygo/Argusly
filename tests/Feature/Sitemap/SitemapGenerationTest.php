<?php

use App\Models\Content;
use App\Models\ContentImage;
use App\Models\ContentVersion;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Sitemap\SitemapGenerator;
use App\Support\LocalizedMarketingUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('sitemap.enabled', true);
    config()->set('sitemap.include_static', true);
    config()->set('sitemap.chunk_size', 2);
    config()->set('cache.default', 'array');
    Cache::store()->flush();
});

function makeSitemapWorkspace(): Workspace
{
    $organization = Organization::query()->create([
        'name' => 'Sitemap Org',
        'slug' => 'sitemap-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Sitemap Workspace',
        'organization_id' => $organization->id,
    ]);

    config()->set('marketing.blog_source.mode', 'workspace');
    config()->set('marketing.blog_source.id', (string) $workspace->id);

    return $workspace;
}

function makePublishedArticle(Workspace $workspace, array $attributes = []): Content
{
    $title = (string) ($attributes['title'] ?? 'Published Article ' . Str::random(4));
    $slug = (string) ($attributes['slug'] ?? Str::slug($title));

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'title' => $title,
        'language' => (string) ($attributes['language'] ?? 'nl'),
        'translation_source_content_id' => $attributes['translation_source_content_id'] ?? null,
        'translation_source_version_id' => $attributes['translation_source_version_id'] ?? null,
        'translation_source_locale' => $attributes['translation_source_locale'] ?? null,
        'is_source_locale' => (bool) ($attributes['is_source_locale'] ?? false),
        'translation_generated_at' => $attributes['translation_generated_at'] ?? null,
        'translation_source_updated_at' => $attributes['translation_source_updated_at'] ?? null,
        'seo_canonical' => $attributes['seo_canonical'] ?? null,
        'publish_url_key' => $slug,
        'first_published_at' => $attributes['first_published_at'] ?? null,
        'public_blog_excerpt' => $attributes['public_blog_excerpt'] ?? null,
        'seo_title' => $attributes['seo_title'] ?? null,
        'seo_meta_description' => $attributes['seo_meta_description'] ?? null,
        'type' => 'article',
        'status' => $attributes['status'] ?? 'published',
        'publish_status' => $attributes['publish_status'] ?? 'published',
        'source' => 'manual',
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'revision',
        'body' => (string) ($attributes['body'] ?? '<p>Published body</p>'),
        'meta' => $attributes['meta'] ?? [
            'slug' => $slug,
            'published_at' => now()->subDay()->toIso8601String(),
        ],
        'source' => 'pl',
    ]);

    $content->update([
        'current_version_id' => (string) $version->id,
    ]);

    return $content->fresh(['currentVersion']);
}

function sitemapXml(string $content): SimpleXMLElement
{
    return simplexml_load_string($content);
}

function sitemapLocs(SimpleXMLElement $xml): array
{
    $nodes = $xml->xpath('//*[local-name()="loc"]');

    return collect($nodes ?: [])
        ->map(fn ($node): string => (string) $node)
        ->all();
}

it('returns a valid sitemap index xml document', function () {
    $workspace = makeSitemapWorkspace();
    makePublishedArticle($workspace, ['title' => 'Article One']);

    $response = $this->get('/sitemap.xml');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    $xml = sitemapXml($response->getContent());

    expect($xml->getName())->toBe('sitemapindex')
        ->and((string) $xml->sitemap[0]->loc)->toContain('/sitemaps/articles.xml')
        ->and((string) $xml->sitemap[1]->loc)->toContain('/sitemaps/static.xml');
});

it('renders canonical hreflang title meta description and article json ld for public articles', function () {
    $workspace = makeSitemapWorkspace();
    $source = makePublishedArticle($workspace, [
        'title' => 'Useful English SEO Foundation',
        'slug' => 'useful-english-seo-foundation',
        'language' => 'en',
        'seo_title' => 'Useful English SEO Foundation | PublishLayer',
        'seo_meta_description' => 'A practical description that explains why this article helps PublishLayer users make better SEO decisions.',
        'body' => '<p>This article gives a clear answer near the top for readers.</p><h2>Summary</h2><p>Use clean URLs and useful metadata.</p>',
        'is_source_locale' => true,
    ]);

    makePublishedArticle($workspace, [
        'title' => 'Nuttige Nederlandse SEO basis',
        'slug' => 'nuttige-nederlandse-seo-basis',
        'language' => 'nl',
        'translation_source_content_id' => (string) $source->id,
        'seo_title' => 'Nuttige Nederlandse SEO basis | PublishLayer',
        'seo_meta_description' => 'Een praktische beschrijving voor Nederlandstalige PublishLayer gebruikers met duidelijke SEO context.',
        'body' => '<p>Dit artikel geeft bovenaan een duidelijk antwoord.</p><h2>Samenvatting</h2><p>Gebruik heldere URL’s en metadata.</p>',
    ]);

    $response = $this->get('/en/blog/useful-english-seo-foundation');

    $response->assertOk()
        ->assertSee('<link rel="canonical" href="' . e(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'useful-english-seo-foundation'], 'en')) . '"', false)
        ->assertSee('hreflang="nl"', false)
        ->assertSee('Useful English SEO Foundation | PublishLayer', false)
        ->assertSee('A practical description that explains why this article helps PublishLayer users make better SEO decisions.', false)
        ->assertSee('"@type":"Article"', false)
        ->assertSee('"@type":"BreadcrumbList"', false);
});

it('adds noindex to duplicate blog taxonomy pages', function () {
    makeSitemapWorkspace();

    $this->get('/en/blog/tag/seo')
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex,follow"', false);
});

it('adds featured images to the article image sitemap with alt-derived title', function () {
    $workspace = makeSitemapWorkspace();
    $article = makePublishedArticle($workspace, [
        'title' => 'Article With Image',
        'slug' => 'article-with-image',
        'language' => 'en',
    ]);

    ContentImage::query()->create([
        'content_id' => (string) $article->id,
        'type' => 'featured',
        'image_url' => 'https://example.com/featured.jpg',
        'alt_text' => 'Dashboard showing PublishLayer SEO metadata',
        'width' => 1200,
        'height' => 630,
        'status' => 'ready',
        'is_active' => true,
    ]);

    $this->get('/sitemaps/articles.xml')
        ->assertOk()
        ->assertSee('https://example.com/featured.jpg', false)
        ->assertSee('Dashboard showing PublishLayer SEO metadata', false);
});

it('seo audit commands complete without crashing on an empty public dataset', function () {
    makeSitemapWorkspace();

    $this->artisan('seo:audit-canonicals')->assertExitCode(0);
    $this->artisan('content:seo-quality-audit --published-only')->assertExitCode(0);
    $this->artisan('seo:validate-structured-data')->assertExitCode(0);
});

it('returns a locale scoped sitemap index when requested from a locale prefix', function () {
    $workspace = makeSitemapWorkspace();
    makePublishedArticle($workspace, ['title' => 'Nederlands artikel', 'slug' => 'nederlands-artikel', 'language' => 'nl']);
    makePublishedArticle($workspace, ['title' => 'English article', 'slug' => 'english-article', 'language' => 'en']);

    $response = $this->get('/nl/sitemap.xml');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    $xml = sitemapXml($response->getContent());
    $locs = sitemapLocs($xml);

    expect($locs)->each->toContain('/nl/sitemaps/');
});

it('returns a valid article child sitemap and only includes published public content', function () {
    $workspace = makeSitemapWorkspace();
    $published = makePublishedArticle($workspace, ['title' => 'Public Article', 'slug' => 'public-article']);
    makePublishedArticle($workspace, [
        'title' => 'Draft Article',
        'slug' => 'draft-article',
        'status' => 'draft',
        'publish_status' => 'draft',
    ]);
    makePublishedArticle($workspace, [
        'title' => 'Unpublished Translation',
        'slug' => 'unpublished-translation',
        'status' => 'draft',
        'publish_status' => 'draft',
    ]);

    $response = $this->get('/sitemaps/articles.xml');

    $response->assertOk();

    $xml = sitemapXml($response->getContent());
    $urls = collect($xml->url)->map(fn ($url) => (string) $url->loc)->all();
    $publishedLoc = LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'public-article'], 'nl');

    expect($xml->getName())->toBe('urlset')
        ->and($urls)->toContain($publishedLoc)
        ->and($urls)->not->toContain(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'draft-article'], 'nl'))
        ->and($urls)->not->toContain(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'unpublished-translation'], 'nl'));
});

it('filters localized article sitemaps by locale', function () {
    $workspace = makeSitemapWorkspace();
    makePublishedArticle($workspace, ['title' => 'Nederlands artikel', 'slug' => 'nederlands-artikel', 'language' => 'nl']);
    makePublishedArticle($workspace, ['title' => 'English article', 'slug' => 'english-article', 'language' => 'en']);

    $response = $this->get('/nl/sitemaps/articles.xml');

    $response->assertOk();

    $locs = sitemapLocs(sitemapXml($response->getContent()));

    expect($locs)->toContain(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'nederlands-artikel'], 'nl'))
        ->and($locs)->not->toContain(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'english-article'], 'en'));
});

it('excludes future scheduled content from article sitemaps', function () {
    $workspace = makeSitemapWorkspace();
    makePublishedArticle($workspace, ['title' => 'Live article', 'slug' => 'live-article']);
    makePublishedArticle($workspace, [
        'title' => 'Future article',
        'slug' => 'future-article',
        'status' => 'published',
        'publish_status' => 'published',
        'meta' => [
            'slug' => 'future-article',
            'published_at' => now()->addDay()->toIso8601String(),
        ],
    ]);

    $response = $this->get('/sitemaps/articles.xml');

    $response->assertOk()
        ->assertDontSee('future-article', false)
        ->assertSee('live-article', false);
});

it('ignores broken stored canonicals and keeps resolved locale routes in the sitemap', function () {
    $workspace = makeSitemapWorkspace();
    makePublishedArticle($workspace, [
        'title' => 'First English article',
        'slug' => 'first-slug',
        'language' => 'en',
        'seo_canonical' => 'http://localhost/en/blog/shared-canonical',
    ]);

    makePublishedArticle($workspace, [
        'title' => 'Second English article',
        'slug' => 'second-slug',
        'language' => 'en',
        'seo_canonical' => 'http://localhost/en/blog/shared-canonical',
    ]);

    $response = $this->get('/sitemaps/articles.xml');
    $locs = sitemapLocs(sitemapXml($response->getContent()));

    expect($locs)->toContain(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'first-slug'], 'en'))
        ->and($locs)->toContain(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'second-slug'], 'en'))
        ->and(array_values(array_filter($locs, fn (string $loc): bool => str_contains($loc, '/en/blog/shared-canonical'))))
        ->toHaveCount(0);
});

it('uses the resolved public locale route instead of a stale stored canonical in article sitemaps', function () {
    $workspace = makeSitemapWorkspace();
    makePublishedArticle($workspace, [
        'title' => 'English canonical route',
        'slug' => 'english-canonical-route',
        'language' => 'en',
        'seo_canonical' => 'http://localhost/nl/blog/verkeerde-route',
    ]);

    $response = $this->get('/sitemaps/articles.xml');
    $locs = sitemapLocs(sitemapXml($response->getContent()));

    expect($locs)->toContain(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'english-canonical-route'], 'en'))
        ->and($locs)->not->toContain('http://localhost/nl/blog/verkeerde-route');
});

it('invalidates sitemap cache when a published slug changes', function () {
    $workspace = makeSitemapWorkspace();
    $content = makePublishedArticle($workspace, ['title' => 'Slug test', 'slug' => 'oude-slug']);

    $this->get('/sitemaps/articles.xml')
        ->assertOk()
        ->assertSee('oude-slug', false);

    $meta = $content->currentVersion->meta ?? [];
    $meta['slug'] = 'nieuwe-slug';
    $content->currentVersion->update(['meta' => $meta]);
    $content->update(['publish_url_key' => 'nieuwe-slug']);

    $this->get('/sitemaps/articles.xml')
        ->assertOk()
        ->assertSee('nieuwe-slug', false)
        ->assertDontSee('oude-slug', false);
});

it('chunks article sitemaps when the configured limit is exceeded', function () {
    $workspace = makeSitemapWorkspace();

    makePublishedArticle($workspace, ['title' => 'Chunk Article One']);
    makePublishedArticle($workspace, ['title' => 'Chunk Article Two']);
    makePublishedArticle($workspace, ['title' => 'Chunk Article Three']);

    $manifest = app(SitemapGenerator::class)->generate('default', true)['manifest'];
    $names = collect($manifest)->pluck('name')->all();

    expect($names)->toContain('articles-1')
        ->and($names)->toContain('articles-2');

    $firstChunk = sitemapXml($this->get('/sitemaps/articles-1.xml')->getContent());
    $secondChunk = sitemapXml($this->get('/sitemaps/articles-2.xml')->getContent());

    expect(count($firstChunk->url))->toBe(2)
        ->and(count($secondChunk->url))->toBe(1);
});

it('returns the static sitemap when enabled', function () {
    makeSitemapWorkspace();

    $response = $this->get('/sitemaps/static.xml');

    $response->assertOk();

    $xml = sitemapXml($response->getContent());
    $paths = collect(sitemapLocs($xml))
        ->map(fn (string $loc): string => (string) parse_url($loc, PHP_URL_PATH))
        ->all();

    expect(count($paths))->toBeGreaterThan(1)
        ->and($paths)->toContain('/en/legal/privacy')
        ->and($paths)->toContain('/nl/juridisch/privacy');
});

it('includes localized marketing topic pages in the marketing sitemap', function () {
    makeSitemapWorkspace();
    $this->seed(\Database\Seeders\MarketingPageSeeder::class);

    $response = $this->get('/sitemaps/marketing-pages.xml');

    $response->assertOk()
        ->assertSee(LocalizedMarketingUrl::page('ai_search', 'en'), false)
        ->assertSee(LocalizedMarketingUrl::page('ai_search', 'nl'), false)
        ->assertSee(LocalizedMarketingUrl::page('seo', 'en'), false)
        ->assertSee(LocalizedMarketingUrl::page('seo', 'nl'), false)
        ->assertSee(LocalizedMarketingUrl::page('geo', 'en'), false)
        ->assertSee(LocalizedMarketingUrl::page('geo', 'nl'), false)
        ->assertSee(LocalizedMarketingUrl::page('llm_visibility', 'en'), false)
        ->assertSee(LocalizedMarketingUrl::page('llm_visibility', 'nl'), false)
        ->assertSee(LocalizedMarketingUrl::page('ai_visibility_score', 'en'), false)
        ->assertSee(LocalizedMarketingUrl::page('ai_visibility_score', 'nl'), false)
        ->assertSee(LocalizedMarketingUrl::page('seo_vs_geo', 'en'), false)
        ->assertSee(LocalizedMarketingUrl::page('seo_vs_geo', 'nl'), false)
        ->assertSee(LocalizedMarketingUrl::page('ai_search_optimization', 'en'), false)
        ->assertSee(LocalizedMarketingUrl::page('ai_search_optimization', 'nl'), false)
        ->assertSee('hreflang="en"', false)
        ->assertSee('hreflang="nl"', false);
});

it('includes hreflang alternates for published blog locale variants', function () {
    $workspace = makeSitemapWorkspace();
    $source = makePublishedArticle($workspace, [
        'title' => 'Original',
        'slug' => 'origineel',
        'language' => 'nl',
        'is_source_locale' => true,
    ]);
    makePublishedArticle($workspace, [
        'title' => 'Duplicate',
        'slug' => 'translated',
        'language' => 'en',
        'translation_source_content_id' => (string) $source->id,
        'translation_source_version_id' => (string) $source->current_version_id,
        'translation_source_locale' => 'nl',
        'is_source_locale' => false,
        'translation_generated_at' => now()->subHour(),
        'translation_source_updated_at' => now()->subHours(2),
    ]);

    $response = $this->get('/sitemaps/articles.xml');

    $response->assertOk()
        ->assertSee('hreflang="nl"', false)
        ->assertSee('hreflang="en"', false)
        ->assertSee(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'origineel'], 'nl'), false)
        ->assertSee(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'translated'], 'en'), false);
});
