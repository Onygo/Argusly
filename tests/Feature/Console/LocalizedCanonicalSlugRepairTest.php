<?php

use App\Enums\SupportedLanguage;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\ContentVersion;
use App\Models\Draft;
use App\Models\MarketingBlogRedirect;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Content\LocalizedContentSlugService;
use App\Services\PublicBlog\PublicBlogService;
use App\Services\Translation\SeoLocalizationService;
use App\Services\Translation\TranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('publishlayer_connector.public_blog.use_connector', false);
    config()->set('publishlayer_connector.public_blog.fallback_to_local', true);
    config()->set('cache.default', 'array');

    $organization = Organization::query()->create([
        'name' => 'Localized Canonical Org',
        'slug' => 'localized-canonical-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $this->workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Localized Canonical Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => 'nl',
        'enabled_content_languages' => ['nl', 'en'],
    ]);

    $this->site = ClientSite::query()->create([
        'workspace_id' => (string) $this->workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => 'Localized Canonical Site',
        'site_url' => 'https://localized-canonical.example.com',
        'base_url' => 'https://localized-canonical.example.com',
        'allowed_domains' => ['localized-canonical.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    config()->set('marketing.blog_source.mode', 'workspace');
    config()->set('marketing.blog_source.id', (string) $this->workspace->id);
});

function localizedCanonicalContent(Workspace $workspace, array $attributes = []): Content
{
    $title = (string) ($attributes['title'] ?? 'Nederlandse bron titel');
    $locale = (string) ($attributes['locale'] ?? 'nl');
    $slug = (string) ($attributes['slug'] ?? Str::slug($title));
    $status = (string) ($attributes['status'] ?? 'published');

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) test()->site->id,
        'title' => $title,
        'language' => $locale,
        'translation_source_content_id' => $attributes['translation_source_content_id'] ?? null,
        'translation_source_locale' => $attributes['translation_source_locale'] ?? null,
        'is_source_locale' => (bool) ($attributes['is_source_locale'] ?? ! ($attributes['translation_source_content_id'] ?? null)),
        'type' => 'article',
        'status' => $status,
        'publish_status' => $status,
        'source' => 'manual',
        'publish_url_key' => $slug,
        'canonical_url_key' => $slug,
        'published_url' => $slug !== '' ? url("/{$locale}/blog/{$slug}") : null,
        'seo_canonical' => $attributes['seo_canonical'] ?? url("/{$locale}/blog/{$slug}"),
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'client_site_id' => (string) test()->site->id,
        'type' => 'revision',
        'body' => (string) ($attributes['body'] ?? '<p>Body</p>'),
        'meta' => [
            'slug' => (string) ($attributes['version_slug'] ?? $slug),
            'excerpt' => 'Excerpt',
            'published_at' => now()->subDay()->toIso8601String(),
        ],
        'source' => 'pl',
    ]);

    $content->forceFill(['current_version_id' => (string) $version->id])->save();

    ContentPublication::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'locale' => $locale,
        'provider' => ContentPublication::PROVIDER_LARAVEL,
        'remote_id' => (string) $content->id,
        'remote_url' => $slug !== '' ? url("/{$locale}/blog/{$slug}") : null,
        'remote_status' => ContentPublication::REMOTE_PUBLISHED,
        'delivery_status' => ContentPublication::STATUS_DELIVERED,
        'last_delivered_at' => now(),
    ]);

    return $content->fresh(['currentVersion', 'publications']) ?? $content;
}

it('generates translated slugs from the localized title instead of translated seo slug input', function () {
    $sourceContent = localizedCanonicalContent($this->workspace, [
        'title' => 'AI cybersecurity als architectuurlaag',
        'slug' => 'ai-cybersecurity-als-architectuurlaag',
        'locale' => 'nl',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $sourceContent->id,
        'client_site_id' => (string) $this->site->id,
        'status' => 'done',
        'progress' => 1,
        'title' => $sourceContent->title,
        'language' => 'nl',
        'output_type' => 'kb_article',
    ]);

    $sourceDraft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'content_id' => (string) $sourceContent->id,
        'client_site_id' => (string) $this->site->id,
        'status' => 'ready',
        'title' => $sourceContent->title,
        'language' => 'nl',
        'output_type' => 'kb_article',
        'content_html' => '<p>Bron</p>',
        'meta' => ['slug' => 'ai-cybersecurity-als-architectuurlaag'],
    ]);

    $localized = app(SeoLocalizationService::class)->buildLocalizedSeoMetadata(
        $sourceDraft,
        'AI Cybersecurity as an Architectural Layer',
        SupportedLanguage::EN,
        ['slug' => 'ai-cybersecurity-als-architectuurlaag']
    );

    expect($localized['slug'])->toBe('ai-cybersecurity-as-an-architectural-layer');
});

it('uses localized content slugs for en translations and nl source payloads', function () {
    $nl = localizedCanonicalContent($this->workspace, [
        'title' => 'AI cybersecurity als architectuurlaag',
        'slug' => 'ai-cybersecurity-als-architectuurlaag',
        'locale' => 'nl',
    ]);

    $en = localizedCanonicalContent($this->workspace, [
        'title' => 'AI Cybersecurity as an Architectural Layer',
        'slug' => '',
        'version_slug' => 'ai-cybersecurity-als-architectuurlaag',
        'locale' => 'en',
        'translation_source_content_id' => (string) $nl->id,
        'translation_source_locale' => 'nl',
        'is_source_locale' => false,
    ]);

    expect(app(LocalizedContentSlugService::class)->publicationSlug($en))->toBe('ai-cybersecurity-as-an-architectural-layer')
        ->and(app(LocalizedContentSlugService::class)->publicationSlug($nl))->toBe('ai-cybersecurity-als-architectuurlaag');
});

it('repairs a broken en slug, canonical, and same-locale redirect while leaving nl untouched', function () {
    $nl = localizedCanonicalContent($this->workspace, [
        'title' => 'AI cybersecurity als architectuurlaag',
        'slug' => 'ai-cybersecurity-als-architectuurlaag',
        'locale' => 'nl',
    ]);

    $en = localizedCanonicalContent($this->workspace, [
        'title' => 'AI Cybersecurity as an Architectural Layer',
        'slug' => 'ai-cybersecurity-als-architectuurlaag',
        'version_slug' => 'ai-cybersecurity-als-architectuurlaag',
        'locale' => 'en',
        'translation_source_content_id' => (string) $nl->id,
        'translation_source_locale' => 'nl',
        'is_source_locale' => false,
        'seo_canonical' => url('/en/blog/ai-cybersecurity-als-architectuurlaag'),
    ]);

    $this->artisan('content:repair-localized-canonicals', [
        '--dry-run' => true,
        '--content-id' => (string) $en->id,
        '--locale' => 'en',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('wrong_locale_slug')
        ->expectsOutputToContain('Dry run only');

    expect($en->fresh()->publish_url_key)->toBe('ai-cybersecurity-als-architectuurlaag');

    $this->artisan('content:repair-localized-canonicals', [
        '--fix' => true,
        '--fix-canonical' => true,
        '--content-id' => (string) $en->id,
        '--locale' => 'en',
    ])->assertSuccessful();

    $en->refresh();
    $nl->refresh();

    expect((string) $en->publish_url_key)->toBe('ai-cybersecurity-as-an-architectural-layer')
        ->and((string) $en->seo_canonical)->toBe(url('/en/blog/ai-cybersecurity-as-an-architectural-layer'))
        ->and((string) data_get($en->currentVersion()->first()?->meta, 'slug'))->toBe('ai-cybersecurity-as-an-architectural-layer')
        ->and((string) $nl->publish_url_key)->toBe('ai-cybersecurity-als-architectuurlaag');

    $this->assertDatabaseHas('marketing_blog_redirects', [
        'source_path' => '/en/blog/ai-cybersecurity-als-architectuurlaag',
        'target_path' => '/en/blog/ai-cybersecurity-as-an-architectural-layer',
        'source_locale' => 'en',
        'target_locale' => 'en',
        'target_content_id' => (string) $en->id,
        'is_active' => true,
    ]);
});

it('serves the corrected canonical slug and same-locale old slug redirect', function () {
    $nl = localizedCanonicalContent($this->workspace, [
        'title' => 'Nederlandse bron titel',
        'slug' => 'nederlandse-bron-titel',
        'locale' => 'nl',
    ]);

    $en = localizedCanonicalContent($this->workspace, [
        'title' => 'English Source Title',
        'slug' => 'nederlandse-bron-titel',
        'locale' => 'en',
        'translation_source_content_id' => (string) $nl->id,
        'translation_source_locale' => 'nl',
        'is_source_locale' => false,
    ]);

    $this->artisan('content:repair-localized-canonicals', [
        '--fix' => true,
        '--fix-canonical' => true,
        '--content-id' => (string) $en->id,
    ])->assertSuccessful();

    $blog = app(PublicBlogService::class);

    expect($blog->legacyRedirectUrlForSlug('nederlandse-bron-titel', 'en'))->toBe('/en/blog/english-source-title');

    $post = $blog->getPostBySlug('english-source-title', 'en');
    expect($post)->not->toBeNull()
        ->and($post['canonical_url'])->toBe(url('/en/blog/english-source-title'));
});

it('removes invalid cross-locale redirects but keeps same-locale redirects', function () {
    $nl = localizedCanonicalContent($this->workspace, [
        'title' => 'Nederlandse bron titel',
        'slug' => 'nederlandse-bron-titel',
        'locale' => 'nl',
    ]);

    $en = localizedCanonicalContent($this->workspace, [
        'title' => 'English Source Title',
        'slug' => 'english-source-title',
        'locale' => 'en',
        'translation_source_content_id' => (string) $nl->id,
        'translation_source_locale' => 'nl',
        'is_source_locale' => false,
    ]);

    $cross = MarketingBlogRedirect::query()->create([
        'id' => (string) Str::uuid(),
        'source_path' => '/en/blog/english-source-title',
        'source_locale' => 'en',
        'source_slug' => 'english-source-title',
        'target_path' => '/nl/blog/nederlandse-bron-titel',
        'target_locale' => 'nl',
        'target_slug' => 'nederlandse-bron-titel',
        'target_content_id' => (string) $nl->id,
        'redirect_kind' => 'legacy_locale_mismatch',
        'is_active' => true,
    ]);

    $same = MarketingBlogRedirect::query()->create([
        'id' => (string) Str::uuid(),
        'source_path' => '/en/blog/old-english-source-title',
        'source_locale' => 'en',
        'source_slug' => 'old-english-source-title',
        'target_path' => '/en/blog/english-source-title',
        'target_locale' => 'en',
        'target_slug' => 'english-source-title',
        'target_content_id' => (string) $en->id,
        'redirect_kind' => 'localized_slug_repair',
        'is_active' => true,
    ]);

    $this->artisan('content:repair-localized-canonicals', ['--fix' => true])->assertSuccessful();

    expect($cross->fresh()->is_active)->toBeFalse()
        ->and($same->fresh()->is_active)->toBeTrue();
});

it('refreshing a translated draft preserves the existing content slug', function () {
    $source = localizedCanonicalContent($this->workspace, [
        'title' => 'Nederlandse brontitel',
        'slug' => 'nederlandse-brontitel',
        'locale' => 'nl',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $source->id,
        'client_site_id' => (string) $this->site->id,
        'status' => 'done',
        'progress' => 1,
        'title' => $source->title,
        'language' => 'nl',
        'output_type' => 'kb_article',
    ]);

    $sourceDraft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'content_id' => (string) $source->id,
        'client_site_id' => (string) $this->site->id,
        'status' => 'ready',
        'title' => $source->title,
        'language' => 'nl',
        'output_type' => 'kb_article',
        'content_html' => '<p>Bron</p>',
    ]);

    $created = app(TranslationService::class)->createTranslatedDraft($sourceDraft, SupportedLanguage::EN, [
        'title' => 'Stable English Title',
        'content_html' => '<p>English body</p>',
        'seo' => ['slug' => 'nederlandse-brontitel'],
    ]);

    $translatedContent = $created->content()->firstOrFail();
    expect((string) $translatedContent->publish_url_key)->toBe('stable-english-title');

    app(TranslationService::class)->refreshTranslatedDraft($sourceDraft, $translatedContent, SupportedLanguage::EN, [
        'title' => 'Changed English Title',
        'content_html' => '<p>Changed English body</p>',
        'seo' => ['slug' => 'changed-english-title'],
    ]);

    expect((string) $translatedContent->fresh()->publish_url_key)->toBe('stable-english-title');
});
