<?php

use App\Models\Content;
use App\Models\ContentIndexationHealth;
use App\Models\ContentVersion;
use App\Models\MarketingBlogRedirect;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeSeoWorkspace(): Workspace
{
    $organization = Organization::query()->create([
        'name' => 'SEO Org',
        'slug' => 'seo-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'SEO Workspace',
        'organization_id' => $organization->id,
    ]);

    config()->set('marketing.blog_source.mode', 'workspace');
    config()->set('marketing.blog_source.id', (string) $workspace->id);

    return $workspace;
}

function makeSeoContent(Workspace $workspace, array $attributes = []): Content
{
    $slug = (string) ($attributes['slug'] ?? 'seo-article');

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'title' => (string) ($attributes['title'] ?? 'SEO article'),
        'language' => (string) ($attributes['language'] ?? 'en'),
        'publish_url_key' => $slug,
        'canonical_url_key' => $slug,
        'seo_canonical' => $attributes['seo_canonical'] ?? null,
        'robots_index' => $attributes['robots_index'] ?? true,
        'type' => 'article',
        'status' => $attributes['status'] ?? 'published',
        'publish_status' => $attributes['publish_status'] ?? 'published',
        'source' => 'manual',
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'revision',
        'body' => '<p>SEO body</p>',
        'meta' => ['slug' => $slug, 'published_at' => now()->subDay()->toIso8601String()],
        'source' => 'pl',
    ]);

    $content->forceFill(['current_version_id' => (string) $version->id])->save();

    return $content->fresh(['currentVersion']);
}

it('repairs redirect chains to the final target', function () {
    MarketingBlogRedirect::query()->create([
        'source_path' => '/en/blog/old-a',
        'source_locale' => 'en',
        'source_slug' => 'old-a',
        'target_path' => '/en/blog/old-b',
        'target_locale' => 'en',
        'target_slug' => 'old-b',
        'redirect_kind' => 'localized_slug_repair',
        'is_active' => true,
    ]);

    MarketingBlogRedirect::query()->create([
        'source_path' => '/en/blog/old-b',
        'source_locale' => 'en',
        'source_slug' => 'old-b',
        'target_path' => '/en/blog/final-c',
        'target_locale' => 'en',
        'target_slug' => 'final-c',
        'redirect_kind' => 'localized_slug_repair',
        'is_active' => true,
    ]);

    $this->artisan('seo:repair-redirect-chains --fix')
        ->assertSuccessful();

    expect(MarketingBlogRedirect::query()->where('source_path', '/en/blog/old-a')->value('target_path'))
        ->toBe('/en/blog/final-c');
});

it('persists indexation health and flags canonical mismatches', function () {
    $workspace = makeSeoWorkspace();
    $content = makeSeoContent($workspace, [
        'slug' => 'canonical-mismatch',
        'language' => 'en',
        'seo_canonical' => url('/nl/blog/verkeerde-canonical'),
    ]);

    $this->artisan('seo:repair-indexation-health')
        ->assertSuccessful();

    $health = ContentIndexationHealth::query()->where('content_id', (string) $content->id)->first();

    expect($health)->not->toBeNull()
        ->and((string) parse_url((string) $health?->canonical_url, PHP_URL_PATH))->toBe('/en/blog/canonical-mismatch')
        ->and($health?->redirect_issue)->toBeTrue()
        ->and((string) $health?->sitemap_status)->toBe('excluded');
});
