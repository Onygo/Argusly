<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentImage;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('repushes missing featured images and stores wp metadata', function () {
    $organization = Organization::query()->create([
        'name' => 'Repush Org',
        'slug' => 'repush-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Repush Org BV',
        'billing_address_line1' => 'Damrak 1',
        'billing_postal_code' => '1000AA',
        'billing_city' => 'Amsterdam',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Repush Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Repush Site',
        'site_url' => 'https://repush.example.com',
        'allowed_domains' => ['repush.example.com'],
        'is_active' => true,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Repush Content',
        'primary_keyword' => 'repush',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
        'wp_post_id' => '987',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'queued',
        'progress' => 0,
        'title' => 'Repush Brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    \App\Models\Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'Repush Draft',
        'output_type' => 'kb_article',
        'content_html' => '<p>Repush draft</p>',
        'meta' => [
            'client_refs' => [
                'draft_webhook_url' => 'https://wp.example.com/pl-webhook',
                'draft_webhook_secret' => 'supersecret',
                'wp_post_id' => '987',
            ],
        ],
    ]);

    $image = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'featured',
        'status' => 'ready',
        'is_active' => true,
        'image_path' => 'content-images/test.png',
        'image_url' => 'https://cdn.example.test/content-images/test.png',
        'credit_cost' => 3,
        'metadata' => [],
    ]);

    Http::fake([
        'https://wp.example.com/wp-json/argusly/v1/posts/987/featured-image' => Http::response([
            'ok' => true,
            'attachment_id' => '123',
            'featured_image_id' => '123',
            'featured_image_url' => 'https://wp.example.com/wp-content/uploads/test.png',
        ], 200),
    ]);

    $exit = Artisan::call('content:repush-missing-featured-images', [
        '--site' => (string) $site->id,
        '--limit' => 10,
    ]);

    expect($exit)->toBe(0);
    expect(data_get($image->fresh()->metadata, 'wp.attachment_id'))->toBe('123');
});
