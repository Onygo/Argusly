<?php

use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\Organization;
use App\Models\Workspace;
use App\Support\LocalizedMarketingUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('argusly_connector.public_blog.use_connector', false);
    config()->set('argusly_connector.public_blog.fallback_to_local', true);
});

it('shows only posts from the configured marketing blog source', function () {
    $organization = Organization::query()->create([
        'name' => 'Public Blog Org',
        'slug' => 'public-blog-org-'.Str::random(6),
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

    $createPost = function (Workspace $workspace, string $title): void {
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
            'body' => '<p>Body</p>',
            'meta' => ['excerpt' => 'Excerpt'],
            'source' => 'pl',
        ]);

        $content->update([
            'current_version_id' => (string) $version->id,
        ]);
    };

    $createPost($workspaceA, 'Workspace A Public Post');
    $createPost($workspaceB, 'Workspace B Public Post');

    config()->set('marketing.blog_source.mode', 'workspace');
    config()->set('marketing.blog_source.id', (string) $workspaceA->id);

    $this->get(LocalizedMarketingUrl::route('public.blog.index', [], 'en', false))
        ->assertOk()
        ->assertSee('Workspace A Public Post')
        ->assertDontSee('Workspace B Public Post');
});

it('shows an empty state when marketing blog source is missing', function () {
    $organization = Organization::query()->create([
        'name' => 'Public Blog Org Empty',
        'slug' => 'public-blog-org-empty-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Workspace Empty',
        'organization_id' => $organization->id,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'title' => 'Should Not Be Visible',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'wp',
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'revision',
        'body' => '<p>Body</p>',
        'meta' => ['excerpt' => 'Excerpt'],
        'source' => 'pl',
    ]);

    $content->update([
        'current_version_id' => (string) $version->id,
    ]);

    config()->set('marketing.blog_source.mode', 'workspace');
    config()->set('marketing.blog_source.id', null);

    $this->get(LocalizedMarketingUrl::route('public.blog.index', [], 'en', false))
        ->assertOk()
        ->assertDontSee('Should Not Be Visible')
        ->assertSee(__('public.blog.empty'));
});

it('renders a local published article detail when connector posts are stale', function () {
    config()->set('argusly_connector.public_blog.use_connector', true);
    config()->set('argusly_connector.api.base_url', 'https://api.argusly.test');
    config()->set('argusly_connector.connections.default.base_url', 'https://api.argusly.test');
    config()->set('argusly_connector.public_blog.connector_endpoint', '/v1/public/blog/posts');

    Http::fake([
        'https://api.argusly.test/v1/public/blog/posts*' => Http::response([
            'data' => [],
        ]),
    ]);

    $organization = Organization::query()->create([
        'name' => 'Public Blog Detail Org',
        'slug' => 'public-blog-detail-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Workspace Detail',
        'organization_id' => $organization->id,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'title' => 'Local Detail Post',
        'type' => 'article',
        'language' => 'nl',
        'publish_url_key' => 'local-detail-post',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'wp',
        'first_published_at' => now()->subMinute(),
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'published_snapshot',
        'body' => '<p>Lokale detailinhoud uit de gepubliceerde snapshot.</p>',
        'meta' => ['excerpt' => 'Excerpt'],
        'source' => 'pl',
    ]);

    $content->update([
        'current_version_id' => (string) $version->id,
    ]);

    config()->set('marketing.blog_source.mode', 'workspace');
    config()->set('marketing.blog_source.id', (string) $workspace->id);

    $this->get(LocalizedMarketingUrl::route('public.blog.index', [], 'nl', false))
        ->assertOk()
        ->assertSee('Local Detail Post');

    $this->get(LocalizedMarketingUrl::route('public.blog.show', ['slug' => 'local-detail-post'], 'nl', false))
        ->assertOk()
        ->assertSee('Local Detail Post')
        ->assertSee('Lokale detailinhoud uit de gepubliceerde snapshot.');
});
