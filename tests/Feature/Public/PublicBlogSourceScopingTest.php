<?php

use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\Organization;
use App\Models\Workspace;
use App\Support\LocalizedMarketingUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
