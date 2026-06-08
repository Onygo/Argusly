<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublishTarget;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\SiteToken;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('repairs wp mapping by preferring the published remote post', function () {
    $organization = Organization::query()->create([
        'name' => 'Repair Org',
        'slug' => 'repair-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Repair Org BV',
        'billing_address_line1' => 'Damrak 1',
        'billing_postal_code' => '1000AA',
        'billing_city' => 'Amsterdam',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Repair Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Repair Site',
        'site_url' => 'https://repair.example.com',
        'base_url' => 'https://repair.example.com',
        'allowed_domains' => ['repair.example.com'],
        'is_active' => true,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Repair Content',
        'primary_keyword' => 'repair',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
        'wp_post_id' => '131',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'queued',
        'progress' => 0,
        'title' => 'Repair Brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'ready_to_deliver',
        'title' => 'Repair Draft',
        'output_type' => 'kb_article',
        'content_html' => '<p>Repair draft</p>',
        'meta' => [
            'client_refs' => [
                'wp_post_id' => '132',
            ],
        ],
    ]);

    ContentPublishTarget::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'target_type' => 'wp',
        'target_identifier' => '131',
        'wp_post_id' => '131',
        'sync_status' => 'pending',
        'meta' => [
            'previous_wp_post_ids' => ['131', '132'],
        ],
    ]);

    $plainToken = 'arg_site_repair_mapping_token';
    SiteToken::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'workspace_id' => $workspace->id,
        'name' => 'repair key',
        'token_hash' => hash('sha256', $plainToken),
        'token_encrypted' => Crypt::encryptString($plainToken),
        'key_prefix' => substr($plainToken, 0, 14),
        'scopes' => ['*'],
        'abilities' => ['*'],
        'revoked' => false,
        'revoked_at' => null,
    ]);

    Http::fake([
        'https://repair.example.com/wp-json/argusly/v1/posts/131' => Http::response([
            'id' => 131,
            'status' => 'draft',
            'modified_gmt' => '2026-02-01T10:00:00',
            'url' => 'https://repair.example.com/?p=131',
        ], 200),
        'https://repair.example.com/wp-json/argusly/v1/posts/132' => Http::response([
            'id' => 132,
            'status' => 'publish',
            'modified_gmt' => '2026-03-01T11:30:00',
            'url' => 'https://repair.example.com/?p=132',
        ], 200),
    ]);

    $exit = Artisan::call('pl:wp:repair-mapping', [
        '--site' => (string) $site->id,
        '--limit' => 10,
    ]);

    expect($exit)->toBe(0);

    expect((string) $content->fresh()->wp_post_id)->toBe('132');
    expect((string) $content->fresh()->published_url)->toBe('https://repair.example.com/?p=132');
    expect((string) data_get($draft->fresh()->meta, 'client_refs.wp_post_id'))->toBe('132');

    $this->assertDatabaseHas('content_publish_targets', [
        'content_id' => (string) $content->id,
        'client_site_id' => (string) $site->id,
        'target_type' => 'wp',
        'wp_post_id' => '132',
    ]);
});

