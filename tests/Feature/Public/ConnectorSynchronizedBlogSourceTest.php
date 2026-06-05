<?php

use App\Models\Content;
use App\Models\ContentImage;
use App\Models\ContentPublication;
use App\Models\ContentVersion;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\PublicBlog\ConnectorSynchronizedBlogSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('uses generated featured image when post meta does not include one', function () {
    $organization = Organization::query()->create([
        'name' => 'Public Blog Org',
        'slug' => 'public-blog-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Public Blog Workspace',
        'organization_id' => $organization->id,
    ]);
    config()->set('marketing.blog_source.mode', 'workspace');
    config()->set('marketing.blog_source.id', (string) $workspace->id);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'title' => 'Public Blog Post',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'wp',
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'revision',
        'body' => '<p>This post has generated featured image only.</p>',
        'meta' => [
            'excerpt' => 'Excerpt without image fields.',
        ],
        'source' => 'pl',
    ]);

    $content->update([
        'current_version_id' => (string) $version->id,
    ]);

    ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'status' => 'ready',
        'is_active' => true,
        'provider' => 'openai',
        'image_url' => 'https://cdn.example.com/generated/featured-post.png',
    ]);

    $posts = app(ConnectorSynchronizedBlogSource::class)->fetchPublishedPosts();

    expect($posts)->toHaveCount(1)
        ->and($posts[0]['featured_image'])->toBe('https://cdn.example.com/generated/featured-post.png');
});

it('returns only posts for the configured workspace source', function () {
    $organization = Organization::query()->create([
        'name' => 'Scoped Blog Org',
        'slug' => 'scoped-blog-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspaceA = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Workspace A',
        'organization_id' => $organization->id,
    ]);
    $workspaceB = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Workspace B',
        'organization_id' => $organization->id,
    ]);

    $createPublishedContent = function (Workspace $workspace, string $title): void {
        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'title' => $title,
            'type' => 'article',
            'status' => 'published',
            'publish_status' => 'published',
            'source' => 'wp',
        ]);

        $version = ContentVersion::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => (string) $content->id,
            'type' => 'revision',
            'body' => '<p>Scoped body</p>',
            'meta' => ['excerpt' => $title.' excerpt'],
            'source' => 'pl',
        ]);

        $content->update(['current_version_id' => (string) $version->id]);
    };

    $createPublishedContent($workspaceA, 'Workspace A Post');
    $createPublishedContent($workspaceB, 'Workspace B Post');

    config()->set('marketing.blog_source.mode', 'workspace');
    config()->set('marketing.blog_source.id', (string) $workspaceA->id);

    $posts = app(ConnectorSynchronizedBlogSource::class)->fetchPublishedPosts();

    expect($posts)->toHaveCount(1)
        ->and($posts[0]['title'])->toBe('Workspace A Post');
});

it('uses the newest delivered Laravel publication as the public publication', function () {
    $organization = Organization::query()->create([
        'name' => 'Publication Integrity Org',
        'slug' => 'publication-integrity-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Publication Integrity Workspace',
        'organization_id' => $organization->id,
    ]);
    config()->set('marketing.blog_source.mode', 'workspace');
    config()->set('marketing.blog_source.id', (string) $workspace->id);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'title' => 'Canonical Publication Post',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'wp',
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'revision',
        'body' => '<p>Canonical publication body</p>',
        'meta' => ['slug' => 'canonical-publication-post'],
        'source' => 'pl',
    ]);
    $content->update(['current_version_id' => (string) $version->id]);

    ContentPublication::query()->create([
        'content_id' => (string) $content->id,
        'locale' => 'en',
        'provider' => ContentPublication::PROVIDER_LARAVEL,
        'remote_id' => 'old-publication',
        'remote_status' => ContentPublication::REMOTE_PUBLISHED,
        'delivery_status' => ContentPublication::STATUS_DELIVERED,
        'last_delivered_at' => now()->subDay(),
    ]);

    $newer = ContentPublication::query()->create([
        'content_id' => (string) $content->id,
        'locale' => 'en',
        'provider' => ContentPublication::PROVIDER_LARAVEL,
        'remote_id' => 'new-publication',
        'remote_status' => ContentPublication::REMOTE_PUBLISHED,
        'delivery_status' => ContentPublication::STATUS_DELIVERED,
        'last_delivered_at' => now(),
    ]);

    $posts = app(ConnectorSynchronizedBlogSource::class)->fetchPublishedPosts();

    expect($posts)->toHaveCount(1)
        ->and($posts[0]['publication_id'])->toBe((string) $newer->id);
});

it('does not expose content with only a pending Laravel publication', function () {
    $organization = Organization::query()->create([
        'name' => 'Pending Publication Org',
        'slug' => 'pending-publication-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Pending Publication Workspace',
        'organization_id' => $organization->id,
    ]);
    config()->set('marketing.blog_source.mode', 'workspace');
    config()->set('marketing.blog_source.id', (string) $workspace->id);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'title' => 'Pending Publication Post',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'wp',
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'revision',
        'body' => '<p>Pending publication body</p>',
        'meta' => ['slug' => 'pending-publication-post'],
        'source' => 'pl',
    ]);
    $content->update(['current_version_id' => (string) $version->id]);

    ContentPublication::query()->create([
        'content_id' => (string) $content->id,
        'locale' => 'en',
        'provider' => ContentPublication::PROVIDER_LARAVEL,
        'remote_status' => ContentPublication::REMOTE_DRAFT,
        'delivery_status' => ContentPublication::STATUS_PENDING,
    ]);

    expect(app(ConnectorSynchronizedBlogSource::class)->fetchPublishedPosts())->toBe([]);
});

it('returns no local posts when marketing blog source is not configured', function () {
    $organization = Organization::query()->create([
        'name' => 'Unconfigured Blog Org',
        'slug' => 'unconfigured-blog-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Workspace',
        'organization_id' => $organization->id,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'title' => 'Should Not Leak',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'wp',
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'revision',
        'body' => '<p>Hidden</p>',
        'meta' => ['excerpt' => 'Hidden'],
        'source' => 'pl',
    ]);
    $content->update(['current_version_id' => (string) $version->id]);

    config()->set('marketing.blog_source.mode', 'workspace');
    config()->set('marketing.blog_source.id', null);

    $posts = app(ConnectorSynchronizedBlogSource::class)->fetchPublishedPosts();

    expect($posts)->toBe([]);
});
