<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\SiteToken;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeLaravelDraftSeoPayloadContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Laravel Connector SEO Org',
        'slug' => 'laravel-connector-seo-' . Str::random(6),
        'status' => 'active',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Laravel Connector SEO Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'laravel',
        'name' => 'Laravel Connector SEO Site',
        'site_url' => 'https://laravel-seo.example.com',
        'base_url' => 'https://laravel-seo.example.com',
        'allowed_domains' => ['laravel-seo.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plainToken = 'pl_site_' . Str::random(48);
    SiteToken::query()->create([
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plainToken),
        'scopes' => ['drafts:read'],
        'revoked' => false,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Laravel SEO Content',
        'primary_keyword' => 'connector focus keyword',
        'seo_title' => 'Content fallback SEO title',
        'seo_meta_description' => 'Content fallback SEO description',
        'seo_canonical' => 'https://laravel-seo.example.com/blog/laravel-seo-content',
        'seo_og_image' => 'https://cdn.example.com/content-og.png',
        'robots_index' => true,
        'robots_follow' => false,
        'schema_type' => 'Article',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'SEO brief',
        'primary_keyword' => 'brief keyword fallback',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'delivery_status' => 'pending',
        'title' => 'Laravel SEO draft',
        'output_type' => 'kb_article',
        'content_html' => '<p>Connector draft body.</p>',
        'meta' => [],
    ]);

    $headers = [
        'Authorization' => 'Bearer ' . $plainToken,
        'X-PublishLayer-Site' => 'laravel-seo.example.com',
    ];

    return [$headers, $draft];
}

it('exposes normalized seo payload fields for laravel connector draft reads', function () {
    [$headers, $draft] = makeLaravelDraftSeoPayloadContext();

    $this->withHeaders($headers)
        ->getJson('/api/v1/drafts?status=ready')
        ->assertOk()
        ->assertJsonPath('items.0.primary_keyword', 'connector focus keyword')
        ->assertJsonPath('items.0.focus_keyword', 'connector focus keyword')
        ->assertJsonPath('items.0.meta_title', 'Content fallback SEO title')
        ->assertJsonPath('items.0.meta_description', 'Content fallback SEO description')
        ->assertJsonPath('items.0.canonical_url', 'https://laravel-seo.example.com/blog/laravel-seo-content')
        ->assertJsonPath('items.0.og_image', 'https://cdn.example.com/content-og.png')
        ->assertJsonPath('items.0.seo.focus_keyword', 'connector focus keyword')
        ->assertJsonPath('items.0.seo.meta_title', 'Content fallback SEO title')
        ->assertJsonPath('items.0.seo.robots_index', true)
        ->assertJsonPath('items.0.seo.robots_follow', false)
        ->assertJsonPath('items.0.seo.schema_type', 'Article')
        ->assertJsonPath('items.0.seo_title', null);

    $this->withHeaders($headers)
        ->getJson('/api/v1/drafts/' . $draft->id)
        ->assertOk()
        ->assertJsonPath('primary_keyword', 'connector focus keyword')
        ->assertJsonPath('focus_keyword', 'connector focus keyword')
        ->assertJsonPath('meta_title', 'Content fallback SEO title')
        ->assertJsonPath('meta_description', 'Content fallback SEO description')
        ->assertJsonPath('canonical_url', 'https://laravel-seo.example.com/blog/laravel-seo-content')
        ->assertJsonPath('og_image', 'https://cdn.example.com/content-og.png')
        ->assertJsonPath('seo.focus_keyword', 'connector focus keyword')
        ->assertJsonPath('seo.meta_title', 'Content fallback SEO title');
});
