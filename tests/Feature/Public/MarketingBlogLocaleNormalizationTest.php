<?php

use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\MarketingBlogRedirect;
use App\Models\Organization;
use App\Models\Workspace;
use App\Support\LocalizedMarketingUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('publishlayer_connector.public_blog.use_connector', false);
    config()->set('publishlayer_connector.public_blog.fallback_to_local', true);

    $organization = Organization::query()->create([
        'name' => 'Marketing Locale Org',
        'slug' => 'marketing-locale-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $this->workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Marketing Locale Workspace',
        'organization_id' => $organization->id,
    ]);

    config()->set('marketing.blog_source.mode', 'workspace');
    config()->set('marketing.blog_source.id', (string) $this->workspace->id);
});

function makeLegacyEnglishRoutedDutchBlog(Workspace $workspace, string $slug = 'publishlayer-laravel-seo-architectuur'): Content
{
    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'title' => 'Laravel SEO architectuur: zo ontwerp je een schaalbaar contentplatform',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'manual',
        'publish_url_key' => $slug,
        'published_url' => url('/en/blog/' . $slug),
        'seo_canonical' => url('/en/blog/' . $slug),
        'is_source_locale' => true,
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'revision',
        'body' => '<p>Dit artikel legt uit hoe je een schaalbaar contentplatform ontwerpt met maximale controle en een duidelijke governance-laag.</p>',
        'meta' => [
            'excerpt' => 'Een Nederlandse uitleg over Laravel SEO architectuur en schaalbare contentplatformen.',
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

it('does not write changes during dry run', function () {
    $content = makeLegacyEnglishRoutedDutchBlog($this->workspace);

    $this->artisan('marketing:normalize-blog-locales', ['--dry-run' => true])
        ->assertSuccessful();

    $content->refresh();

    expect($content->localeCode())->toBe('en')
        ->and(MarketingBlogRedirect::query()->count())->toBe(0);
});

it('normalizes a legacy english route with dutch content into a dutch source variant and redirect', function () {
    $slug = 'publishlayer-laravel-seo-architectuur';
    $content = makeLegacyEnglishRoutedDutchBlog($this->workspace, $slug);
    $enUrl = '/en/blog/' . $slug;
    $nlUrl = '/nl/blog/' . $slug;
    $nlAbsoluteUrl = url($nlUrl);

    $this->artisan('marketing:normalize-blog-locales')
        ->assertSuccessful();

    $content->refresh();

    expect($content->localeCode())->toBe('nl');
    expect((bool) $content->is_source_locale)->toBeTrue();
    expect($content->translation_source_locale)->toBeNull();
    expect($content->translation_source_content_id)->toBeNull();
    expect((string) $content->published_url)->toBe($nlAbsoluteUrl);
    expect((string) $content->seo_canonical)->toBe($nlAbsoluteUrl);

    $this->assertDatabaseHas('marketing_blog_redirects', [
        'source_path' => $enUrl,
        'target_path' => $nlUrl,
        'source_locale' => 'en',
        'target_locale' => 'nl',
        'source_slug' => $slug,
        'target_slug' => $slug,
        'redirect_kind' => 'legacy_locale_mismatch',
    ]);

    $this->get($enUrl)
        ->assertStatus(301)
        ->assertRedirect($nlUrl);

    $this->get($nlUrl)
        ->assertOk()
        ->assertSee('Laravel SEO architectuur')
        ->assertSee('rel="canonical" href="' . $nlAbsoluteUrl . '"', false)
        ->assertSee('rel="alternate" hreflang="nl" href="' . $nlAbsoluteUrl . '"', false)
        ->assertDontSee('hreflang="en"', false);
});
