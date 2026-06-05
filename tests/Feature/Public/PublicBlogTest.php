<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentImage;
use App\Models\ContentPublication;
use App\Models\ContentVersion;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\StructuredAnswerBlock;
use App\Models\Workspace;
use App\Services\Content\ContentLifecycleService;
use App\Services\InternalLinking\InternalLinkInjector;
use App\Support\LocalizedMarketingUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    config()->set('publishlayer_connector.public_blog.use_connector', false);
    config()->set('publishlayer_connector.public_blog.fallback_to_local', true);

    $organization = Organization::query()->create([
        'name' => 'Localized Blog Org',
        'slug' => 'localized-blog-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $this->workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Localized Blog Workspace',
        'organization_id' => $organization->id,
    ]);

    config()->set('marketing.blog_source.mode', 'workspace');
    config()->set('marketing.blog_source.id', (string) $this->workspace->id);
});

function makePublishedBlog(Workspace $workspace, array $attributes = []): Content
{
    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'title' => (string) ($attributes['title'] ?? 'Blog post'),
        'language' => (string) ($attributes['language'] ?? 'nl'),
        'translation_source_content_id' => $attributes['translation_source_content_id'] ?? null,
        'translation_source_version_id' => $attributes['translation_source_version_id'] ?? null,
        'translation_source_locale' => $attributes['translation_source_locale'] ?? null,
        'is_source_locale' => (bool) ($attributes['is_source_locale'] ?? false),
        'translation_generated_at' => $attributes['translation_generated_at'] ?? null,
        'translation_source_updated_at' => $attributes['translation_source_updated_at'] ?? null,
        'publish_url_key' => $attributes['publish_url_key'] ?? null,
        'seo_title' => $attributes['seo_title'] ?? null,
        'seo_meta_description' => $attributes['seo_meta_description'] ?? null,
        'seo_og_title' => $attributes['seo_og_title'] ?? null,
        'seo_og_description' => $attributes['seo_og_description'] ?? null,
        'seo_twitter_title' => $attributes['seo_twitter_title'] ?? null,
        'seo_twitter_description' => $attributes['seo_twitter_description'] ?? null,
        'type' => 'article',
        'status' => (string) ($attributes['status'] ?? 'published'),
        'publish_status' => (string) ($attributes['publish_status'] ?? 'published'),
        'source' => 'manual',
        'updated_at' => $attributes['updated_at'] ?? now(),
        'created_at' => $attributes['created_at'] ?? now()->subDay(),
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'revision',
        'body' => (string) ($attributes['body'] ?? '<p>Body</p>'),
        'meta' => [
            'excerpt' => (string) ($attributes['excerpt'] ?? 'Excerpt'),
            'slug' => (string) ($attributes['version_slug'] ?? ''),
            'published_at' => (string) (($attributes['published_at'] ?? now()->subDay())->toIso8601String()),
        ],
        'source' => 'pl',
    ]);

    $content->forceFill([
        'current_version_id' => (string) $version->id,
    ])->save();

    return $content->fresh(['currentVersion']);
}

function markLaravelPublicationDelivered(Content $content, ?string $slug = null): ContentPublication
{
    $slug ??= (string) $content->publish_url_key;

    return ContentPublication::query()->create([
        'content_id' => (string) $content->id,
        'client_site_id' => $content->client_site_id,
        'locale' => $content->localeCode(),
        'provider' => ContentPublication::PROVIDER_LARAVEL,
        'remote_id' => (string) $content->id,
        'remote_type' => 'article',
        'remote_url' => url('/'.$content->localeCode().'/blog/'.$slug),
        'remote_status' => ContentPublication::REMOTE_PUBLISHED,
        'delivery_status' => ContentPublication::STATUS_DELIVERED,
        'last_delivered_at' => now(),
    ]);
}

it('renders blog indexes per locale without mixing variants', function () {
    $source = makePublishedBlog($this->workspace, [
        'title' => 'Nederlandse bron',
        'language' => 'nl',
        'publish_url_key' => 'nederlandse-bron',
        'is_source_locale' => true,
        'seo_title' => 'Nederlandse SEO titel',
    ]);

    makePublishedBlog($this->workspace, [
        'title' => 'English translation',
        'language' => 'en',
        'publish_url_key' => 'english-translation',
        'translation_source_content_id' => (string) $source->id,
        'translation_source_version_id' => (string) $source->current_version_id,
        'translation_source_locale' => 'nl',
        'is_source_locale' => false,
        'translation_generated_at' => now()->subHours(2),
        'translation_source_updated_at' => now()->subHours(3),
        'seo_title' => 'English SEO title',
    ]);

    $this->get(LocalizedMarketingUrl::route('public.blog.index', [], 'nl', false))
        ->assertOk()
        ->assertSee('Nederlandse bron')
        ->assertDontSee('English translation');

    $this->get(LocalizedMarketingUrl::route('public.blog.index', [], 'en', false))
        ->assertOk()
        ->assertSee('English translation')
        ->assertDontSee('Nederlandse bron');
});

it('loads local blog surfaces with featured images without ambiguous content image selects', function () {
    $publishedAt = now()->subHour();

    $nl = makePublishedBlog($this->workspace, [
        'title' => 'Nederlandse featured blog',
        'language' => 'nl',
        'publish_url_key' => 'nederlandse-featured-blog',
        'is_source_locale' => true,
    ]);

    $en = makePublishedBlog($this->workspace, [
        'title' => 'English featured blog',
        'language' => 'en',
        'publish_url_key' => 'english-featured-blog',
        'is_source_locale' => true,
    ]);

    foreach ([$nl, $en] as $content) {
        $content->forceFill([
            'first_published_at' => $publishedAt,
            'public_blog_excerpt' => 'Featured excerpt',
            'public_blog_reading_time_minutes' => 2,
            'public_blog_author' => 'PublishLayer',
            'public_blog_category' => 'Product',
            'public_blog_tags' => ['featured'],
            'public_blog_featured_image_url' => 'https://images.example.test/featured.jpg',
            'public_blog_featured_image_width' => 1200,
            'public_blog_featured_image_height' => 630,
        ])->save();

        ContentImage::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => (string) $content->id,
            'type' => 'featured',
            'provider' => 'test',
            'image_url' => 'https://images.example.test/featured.jpg',
            'alt_text' => $content->title . ' alt',
            'width' => 1200,
            'height' => 630,
            'status' => 'ready',
            'is_active' => true,
            'created_at' => $publishedAt,
            'updated_at' => $publishedAt,
        ]);
    }

    $blog = app(\App\Services\PublicBlog\PublicBlogService::class);

    expect(fn () => $blog->latestPosts(5, 'en'))->not->toThrow(Throwable::class);
    expect(fn () => $blog->listPublishedPosts(1, 12, [], 'nl'))->not->toThrow(Throwable::class);

    $this->get('/en/blog')
        ->assertOk()
        ->assertSee('English featured blog');

    $this->get('/nl/blog')
        ->assertOk()
        ->assertSee('Nederlandse featured blog');

    $this->get('/llms.txt?lang=en')
        ->assertOk()
        ->assertSee('English featured blog');

    $this->get('/llms-full.txt?lang=en')
        ->assertOk()
        ->assertSee('English featured blog');
});

it('resolves localized detail pages by locale and slug', function () {
    $source = makePublishedBlog($this->workspace, [
        'title' => 'Nederlandse bron',
        'language' => 'nl',
        'publish_url_key' => 'nederlandse-bron',
        'is_source_locale' => true,
        'seo_title' => 'Nederlandse SEO titel',
        'seo_meta_description' => 'Nederlandse omschrijving',
        'seo_og_title' => 'Nederlandse OG titel',
        'seo_og_description' => 'Nederlandse OG omschrijving',
    ]);

    makePublishedBlog($this->workspace, [
        'title' => 'English translation',
        'language' => 'en',
        'publish_url_key' => 'english-translation',
        'translation_source_content_id' => (string) $source->id,
        'translation_source_version_id' => (string) $source->current_version_id,
        'translation_source_locale' => 'nl',
        'is_source_locale' => false,
        'translation_generated_at' => now()->subHours(2),
        'translation_source_updated_at' => now()->subHours(3),
        'seo_title' => 'English SEO title',
        'seo_meta_description' => 'English description',
    ]);

    $nlUrl = LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'nederlandse-bron'], 'nl', false);
    $enUrl = LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'english-translation'], 'en', false);

    $this->get($nlUrl)
        ->assertOk()
        ->assertSee('Nederlandse bron')
        ->assertSee('rel="canonical" href="' . url($nlUrl) . '"', false)
        ->assertSee('rel="alternate" hreflang="nl" href="' . url($nlUrl) . '"', false)
        ->assertSee('rel="alternate" hreflang="en" href="' . url($enUrl) . '"', false)
        ->assertSee('rel="alternate" hreflang="x-default" href="' . url($enUrl) . '"', false)
        ->assertSee('href="' . url($enUrl) . '"', false);

    $this->get($enUrl)
        ->assertOk()
        ->assertSee('English translation')
        ->assertSee('rel="canonical" href="' . url($enUrl) . '"', false)
        ->assertSee('rel="alternate" hreflang="nl" href="' . url($nlUrl) . '"', false)
        ->assertSee('rel="alternate" hreflang="en" href="' . url($enUrl) . '"', false);
});

it('localizes generated related-reading links inside translated articles', function () {
    $targetNl = makePublishedBlog($this->workspace, [
        'title' => 'Nederlandse doelgids',
        'language' => 'nl',
        'publish_url_key' => 'nederlandse-doelgids',
        'is_source_locale' => true,
    ]);

    makePublishedBlog($this->workspace, [
        'title' => 'English target guide',
        'language' => 'en',
        'publish_url_key' => 'english-target-guide',
        'translation_source_content_id' => (string) $targetNl->id,
        'translation_source_version_id' => (string) $targetNl->current_version_id,
        'translation_source_locale' => 'nl',
        'is_source_locale' => false,
        'translation_generated_at' => now()->subHours(2),
        'translation_source_updated_at' => now()->subHours(3),
    ]);

    makePublishedBlog($this->workspace, [
        'title' => 'Nederlands bronartikel met related reading',
        'language' => 'nl',
        'publish_url_key' => 'nederlands-bronartikel-related-reading',
        'is_source_locale' => true,
        'body' => '<p>Intro.</p><p><strong>Gerelateerde lectuur:</strong> '
            . '<a href="' . url('/blog/english-target-guide') . '">Nederlandse doelgids</a>.</p>',
    ]);

    $this->get(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'nederlands-bronartikel-related-reading'], 'nl', false))
        ->assertOk()
        ->assertSee('href="' . url('/nl/blog/nederlandse-doelgids') . '"', false)
        ->assertDontSee('href="' . url('/blog/english-target-guide') . '"', false);
});

it('renders a self canonical even when stored canonical points to another locale', function () {
    makePublishedBlog($this->workspace, [
        'title' => 'English canonical repair',
        'language' => 'en',
        'publish_url_key' => 'english-canonical-repair',
        'seo_canonical' => url('/nl/blog/verkeerde-canonical'),
        'is_source_locale' => true,
    ]);

    $enUrl = LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'english-canonical-repair'], 'en', false);

    $this->get($enUrl)
        ->assertOk()
        ->assertSee('rel="canonical" href="' . url($enUrl) . '"', false)
        ->assertDontSee('rel="canonical" href="' . url('/nl/blog/verkeerde-canonical') . '"', false)
        ->assertSee('hreflang="x-default"', false);
});

it('resolves a delivered translated EN publication even when latest revision status is draft', function () {
    $source = makePublishedBlog($this->workspace, [
        'title' => 'Nederlandse bron',
        'language' => 'nl',
        'publish_url_key' => 'nederlandse-bron',
        'is_source_locale' => true,
    ]);

    $translation = makePublishedBlog($this->workspace, [
        'title' => 'AI Cybersecurity as an Architectural Layer',
        'language' => 'en',
        'publish_url_key' => 'ai-cybersecurity-as-an-architectural-layer',
        'translation_source_content_id' => (string) $source->id,
        'translation_source_version_id' => (string) $source->current_version_id,
        'translation_source_locale' => 'nl',
        'status' => 'draft',
        'publish_status' => 'draft',
    ]);
    markLaravelPublicationDelivered($translation);

    $this->get(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'ai-cybersecurity-as-an-architectural-layer'], 'en', false))
        ->assertOk()
        ->assertSee('AI Cybersecurity as an Architectural Layer');
});

it('uses the published snapshot instead of a newer draft revision for public routes', function () {
    $content = makePublishedBlog($this->workspace, [
        'title' => 'Live article title',
        'language' => 'en',
        'publish_url_key' => 'live-article-title',
        'status' => 'draft',
        'publish_status' => 'draft',
        'body' => '<p>New draft body should stay private</p>',
    ]);

    ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => ContentVersion::TYPE_PUBLISHED_SNAPSHOT,
        'body' => '<p>Live snapshot body</p>',
        'meta' => ['published_at' => now()->subHour()->toIso8601String()],
        'source' => 'pl',
    ]);

    markLaravelPublicationDelivered($content);

    $this->get(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'live-article-title'], 'en', false))
        ->assertOk()
        ->assertSee('Live snapshot body')
        ->assertDontSee('New draft body should stay private');
});

it('renders visible answer blocks and faq schema on public articles', function () {
    $content = makePublishedBlog($this->workspace, [
        'title' => 'Answer block article',
        'language' => 'en',
        'publish_url_key' => 'answer-block-article',
        'body' => '<p>Intro body</p><h2>Pricing</h2><p>Pricing body</p>',
    ]);
    $content->update([
        'answer_block_render_mode' => Content::ANSWER_BLOCK_RENDER_MODE_AI_OPTIMIZED,
        'answer_block_max_visible' => 3,
    ]);

    StructuredAnswerBlock::query()->create([
        'content_id' => $content->id,
        'question' => 'What is pricing?',
        'answer' => 'Pricing depends on your plan.',
        'entities' => ['pricing'],
        'order' => 0,
    ]);

    $this->get(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'answer-block-article'], 'en', false))
        ->assertOk()
        ->assertSee('data-answer-block="true"', false)
        ->assertSee('Quick answer')
        ->assertSee('FAQPage', false);
});

it('renders public articles when optional seo image and answer fields are missing or malformed', function () {
    $content = makePublishedBlog($this->workspace, [
        'title' => 'Defensive rendering article',
        'language' => 'en',
        'publish_url_key' => 'defensive-rendering-article',
        'seo_title' => null,
        'seo_meta_description' => null,
        'body' => '<p>Intro body with enough context for fallback metadata.</p><h2>Summary</h2><p>Fallbacks should render.</p>',
    ]);
    $content->update([
        'answer_block_render_mode' => Content::ANSWER_BLOCK_RENDER_MODE_AI_OPTIMIZED,
        'answer_block_max_visible' => 3,
    ]);

    $block = StructuredAnswerBlock::query()->create([
        'content_id' => $content->id,
        'question' => 'Can this render?',
        'answer' => 'Yes, malformed optional JSON must not break the page.',
        'entities' => ['rendering'],
        'platforms' => ['search'],
        'order' => 0,
    ]);

    DB::table('structured_answer_blocks')
        ->where('id', $block->id)
        ->update([
            'entities' => '{invalid-json',
            'platforms' => '',
        ]);

    $this->get(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'defensive-rendering-article'], 'en', false))
        ->assertOk()
        ->assertSee('Defensive rendering article', false)
        ->assertSee('<link rel="canonical"', false)
        ->assertSee('<meta name="description"', false);
});

it('suppresses generated resource fallback when inline links are present', function () {
    makePublishedBlog($this->workspace, [
        'title' => 'Inline link cleanup article',
        'language' => 'nl',
        'publish_url_key' => 'inline-link-cleanup-article',
        'body' => '<p>Lees meer over <a href="/nl/blog/wordpress-ai">WordPress AI</a> in je bestaande workflow.</p>'
            . '<p>Wil je dieper ingaan op hoe je dit in jouw WordPress-omgeving kunt inrichten, bekijk dan ook onze aanvullende resources: <a href="/nl/blog/extra">Extra resource</a>.</p>',
    ]);

    $this->get(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'inline-link-cleanup-article'], 'nl', false))
        ->assertOk()
        ->assertSee('href="/nl/blog/wordpress-ai"', false)
        ->assertSee('WordPress AI')
        ->assertDontSee('Wil je dieper ingaan')
        ->assertDontSee('aanvullende resources')
        ->assertDontSee('href="/nl/blog/extra"', false);
});

it('removes loose semantic keyword dumps while preserving markdown-style lists', function () {
    makePublishedBlog($this->workspace, [
        'title' => 'Keyword dump cleanup article',
        'language' => 'en',
        'publish_url_key' => 'keyword-dump-cleanup-article',
        'body' => '<p>Intro paragraph with real article context.</p>'
            . '<p>Microsoft 365</p><p>HubSpot</p><p>Salesforce</p><p>custom GPT’s</p><p>WordPress</p><p>AI visibility</p>'
            . '<h2>Checklist</h2><ul><li>Keep normal bullets</li><li>Preserve article lists</li></ul>',
    ]);

    $this->get(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'keyword-dump-cleanup-article'], 'en', false))
        ->assertOk()
        ->assertSee('Intro paragraph with real article context.')
        ->assertSee('<ul>', false)
        ->assertSee('<li>Keep normal bullets</li>', false)
        ->assertSee('<li>Preserve article lists</li>', false)
        ->assertDontSee('Microsoft 365')
        ->assertDontSee('HubSpot')
        ->assertDontSee('Salesforce')
        ->assertDontSee('custom GPT’s');
});

it('keeps generated related resources when no inline links are present', function () {
    makePublishedBlog($this->workspace, [
        'title' => 'Fallback resource article',
        'language' => 'nl',
        'publish_url_key' => 'fallback-resource-article',
        'body' => '<p>Een artikel zonder inline links.</p>'
            . '<p>Wil je dieper ingaan op hoe je dit in jouw WordPress-omgeving kunt inrichten, bekijk dan ook onze aanvullende resources: <a href="/nl/blog/extra">Extra resource</a>.</p>',
    ]);

    $this->get(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'fallback-resource-article'], 'nl', false))
        ->assertOk()
        ->assertSee('Wil je dieper ingaan')
        ->assertSee('href="/nl/blog/extra"', false);
});

it('persists inline links through republish snapshots and repeated renders without fallback duplication', function () {
    $site = ClientSite::query()->create([
        'workspace_id' => (string) $this->workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => 'Inline Link Site',
        'site_url' => 'https://inline-links.example.test',
        'base_url' => 'https://inline-links.example.test',
        'allowed_domains' => ['inline-links.example.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $content = makePublishedBlog($this->workspace, [
        'title' => 'Stable inline links',
        'language' => 'en',
        'publish_url_key' => 'stable-inline-links',
        'body' => '<p>WordPress AI links should be inserted inline.</p>',
    ]);
    $content->forceFill([
        'client_site_id' => (string) $site->id,
    ])->save();

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => (string) $site->id,
        'content_id' => (string) $content->id,
        'status' => 'ready',
        'progress' => 1,
        'title' => 'Stable inline links',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'client_site_id' => (string) $site->id,
        'content_id' => (string) $content->id,
        'status' => 'ready_to_deliver',
        'delivery_status' => 'pending',
        'title' => 'Stable inline links',
        'language' => 'en',
        'output_type' => 'kb_article',
        'content_html' => '<p>WordPress AI links should be inserted inline.</p>'
            . '<p><strong>Related reading:</strong> <a href="/en/blog/wordpress-ai">Related article 1</a></p>',
    ]);

    $injector = app(InternalLinkInjector::class);
    $lifecycle = app(ContentLifecycleService::class);

    $firstResult = $injector->inject($content->fresh(), $draft, [], [[
        'target_url' => '/en/blog/wordpress-ai',
        'anchor_text' => 'WordPress AI',
        'title' => 'WordPress AI guide',
    ]]);
    expect((int) $firstResult['applied_count'])->toBe(1);

    $lifecycle->synchronizePublishedSnapshotFromDraft($draft->fresh());
    $reloaded = Content::query()->with(['currentVersion', 'drafts'])->findOrFail((string) $content->id);

    expect((string) $reloaded->currentVersion?->body)
        ->toContain('<a href="/en/blog/wordpress-ai">WordPress AI</a>')
        ->not->toContain('Related reading:')
        ->and((string) $reloaded->drafts()->latest('created_at')->first()?->content_html)
        ->toContain('<a href="/en/blog/wordpress-ai">WordPress AI</a>');

    markLaravelPublicationDelivered($reloaded);

    $firstRender = $this->get(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'stable-inline-links'], 'en', false))
        ->assertOk()
        ->assertSee('href="/en/blog/wordpress-ai"', false)
        ->assertDontSee('Related reading:')
        ->content();

    $secondResult = $injector->inject($reloaded->fresh(), $draft->fresh(), [], [[
        'target_url' => '/en/blog/wordpress-ai',
        'anchor_text' => 'WordPress AI',
        'title' => 'WordPress AI guide',
    ]]);
    expect((int) $secondResult['applied_count'])->toBe(0);

    $lifecycle->synchronizePublishedSnapshotFromDraft($draft->fresh());

    $secondRender = $this->get(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'stable-inline-links'], 'en', false))
        ->assertOk()
        ->assertSee('href="/en/blog/wordpress-ai"', false)
        ->assertDontSee('Related reading:')
        ->content();

    expect(substr_count($secondRender, 'href="/en/blog/wordpress-ai"'))->toBe(1)
        ->and($secondRender)->toBe($firstRender);
});

it('repair command rebuilds the public route cache for a translated EN publication', function () {
    $content = makePublishedBlog($this->workspace, [
        'title' => 'English route cache article',
        'language' => 'en',
        'publish_url_key' => 'old-english-route-cache-article',
        'status' => 'draft',
        'publish_status' => 'draft',
    ]);
    markLaravelPublicationDelivered($content, 'old-english-route-cache-article');

    $oldUrl = LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'old-english-route-cache-article'], 'en', false);
    $url = LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'english-route-cache-article'], 'en', false);

    $this->get($oldUrl)->assertOk();

    Content::withoutEvents(function () use ($content): void {
        $content->forceFill([
            'publish_url_key' => 'english-route-cache-article',
            'published_url' => url('/en/blog/english-route-cache-article'),
            'seo_canonical' => url('/en/blog/english-route-cache-article'),
        ])->save();
    });
    $content = $content->fresh();

    $this->get($url)->assertNotFound();

    $this->artisan('content:repair-publication-routes', [
        '--content-id' => (string) $content->id,
        '--rebuild-live-routes' => true,
    ])->assertSuccessful();

    $this->get($url)
        ->assertOk()
        ->assertSee('English route cache article');
});

it('repair command corrects stale delivered publication state when the public route cannot be rebuilt', function () {
    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $this->workspace->id,
        'title' => 'Broken route article',
        'language' => 'en',
        'publish_url_key' => 'broken-route-article',
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'draft',
        'source' => 'manual',
    ]);
    $publication = markLaravelPublicationDelivered($content);

    $this->artisan('content:repair-publication-routes', [
        '--content-id' => (string) $content->id,
        '--rebuild-live-routes' => true,
    ])->assertSuccessful();

    expect((string) $publication->fresh()->delivery_status)->toBe(ContentPublication::STATUS_MISSING_REMOTE);
});

it('only activates old EN slug redirects after the corrected EN route is live', function () {
    $content = makePublishedBlog($this->workspace, [
        'title' => 'Correct English route',
        'language' => 'en',
        'publish_url_key' => 'correct-english-route',
        'status' => 'draft',
        'publish_status' => 'draft',
    ]);
    markLaravelPublicationDelivered($content);

    \App\Models\MarketingBlogRedirect::query()->create([
        'source_path' => '/en/blog/old-english-route',
        'source_locale' => 'en',
        'source_slug' => 'old-english-route',
        'target_path' => '/en/blog/correct-english-route',
        'target_locale' => 'en',
        'target_slug' => 'correct-english-route',
        'target_content_id' => (string) $content->id,
        'redirect_kind' => 'slug_changed',
        'is_active' => false,
    ]);

    $this->artisan('content:repair-publication-routes', [
        '--content-id' => (string) $content->id,
        '--rebuild-live-routes' => true,
        '--rebuild-redirects' => true,
    ])->assertSuccessful();

    $this->get('/en/blog/old-english-route')
        ->assertRedirect('/en/blog/correct-english-route');
});

it('resolves NL source and EN translation from delivered localized publication records', function () {
    $source = makePublishedBlog($this->workspace, [
        'title' => 'Nederlandse live route',
        'language' => 'nl',
        'publish_url_key' => 'nederlandse-live-route',
        'is_source_locale' => true,
        'status' => 'draft',
        'publish_status' => 'draft',
    ]);
    markLaravelPublicationDelivered($source);

    $translation = makePublishedBlog($this->workspace, [
        'title' => 'English live route',
        'language' => 'en',
        'publish_url_key' => 'english-live-route',
        'translation_source_content_id' => (string) $source->id,
        'translation_source_version_id' => (string) $source->current_version_id,
        'translation_source_locale' => 'nl',
        'status' => 'draft',
        'publish_status' => 'draft',
    ]);
    markLaravelPublicationDelivered($translation);

    $this->get(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'nederlandse-live-route'], 'nl', false))
        ->assertOk()
        ->assertSee('Nederlandse live route');

    $this->get(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'english-live-route'], 'en', false))
        ->assertOk()
        ->assertSee('English live route');
});

it('returns 404 for a locale route when that locale variant does not exist', function () {
    makePublishedBlog($this->workspace, [
        'title' => 'Alleen Nederlands',
        'language' => 'nl',
        'publish_url_key' => 'alleen-nederlands',
        'is_source_locale' => true,
    ]);

    $this->get(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'alleen-nederlands'], 'en', false))
        ->assertNotFound();
});

it('only outputs hreflang and language switch links for published variants', function () {
    $source = makePublishedBlog($this->workspace, [
        'title' => 'Bronpost',
        'language' => 'nl',
        'publish_url_key' => 'bronpost',
        'is_source_locale' => true,
    ]);

    makePublishedBlog($this->workspace, [
        'title' => 'Draft translation',
        'language' => 'en',
        'publish_url_key' => 'draft-translation',
        'translation_source_content_id' => (string) $source->id,
        'translation_source_version_id' => (string) $source->current_version_id,
        'translation_source_locale' => 'nl',
        'is_source_locale' => false,
        'status' => 'draft',
        'publish_status' => 'draft',
    ]);

    $nlUrl = LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'bronpost'], 'nl', false);

    $this->get($nlUrl)
        ->assertOk()
        ->assertSee('rel="alternate" hreflang="nl" href="' . url($nlUrl) . '"', false)
        ->assertSee('rel="alternate" hreflang="x-default" href="' . url($nlUrl) . '"', false)
        ->assertDontSee('hreflang="en"', false)
        ->assertDontSee('>X-DEFAULT<', false)
        ->assertDontSee('href="/en/blog/draft-translation"', false);
});

it('keeps x-default in hreflang metadata but never renders it as a visible locale chip', function () {
    $source = makePublishedBlog($this->workspace, [
        'title' => 'Nederlandse bron',
        'language' => 'nl',
        'publish_url_key' => 'nederlandse-bron',
        'is_source_locale' => true,
    ]);

    makePublishedBlog($this->workspace, [
        'title' => 'English translation',
        'language' => 'en',
        'publish_url_key' => 'english-translation',
        'translation_source_content_id' => (string) $source->id,
        'translation_source_version_id' => (string) $source->current_version_id,
        'translation_source_locale' => 'nl',
        'is_source_locale' => false,
        'translation_generated_at' => now()->subHours(2),
        'translation_source_updated_at' => now()->subHours(3),
    ]);

    $nlUrl = LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'nederlandse-bron'], 'nl', false);
    $enUrl = LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'english-translation'], 'en', false);

    $this->get($nlUrl)
        ->assertOk()
        ->assertSee('rel="alternate" hreflang="x-default" href="' . url($enUrl) . '"', false)
        ->assertSee('href="' . url($nlUrl) . '"', false)
        ->assertSee('href="' . url($enUrl) . '"', false)
        ->assertSee('>NL<', false)
        ->assertSee('>EN<', false)
        ->assertDontSee('>X-DEFAULT<', false)
        ->assertDontSee('>DE<', false)
        ->assertDontSee('>FR<', false);
});

it('hides the article locale chip block when only one visible locale exists', function () {
    makePublishedBlog($this->workspace, [
        'title' => 'Alleen Nederlands',
        'language' => 'nl',
        'publish_url_key' => 'alleen-nederlands',
        'is_source_locale' => true,
    ]);

    $response = $this->get(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'alleen-nederlands'], 'nl', false));

    $response
        ->assertOk()
        ->assertSee('rel="alternate" hreflang="x-default"', false)
        ->assertDontSee('rounded-full border border-border bg-white p-1 text-xs', false)
        ->assertDontSee('>X-DEFAULT<', false);
});

it('keeps legacy dutch blog urls working by redirecting to the localized route', function () {
    makePublishedBlog($this->workspace, [
        'title' => 'Bestaande slug',
        'language' => 'nl',
        'publish_url_key' => 'bestaande-slug',
        'is_source_locale' => true,
    ]);

    $this->get('/blog/bestaande-slug?lang=nl')
        ->assertRedirect(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'bestaande-slug'], 'nl', false));
});
