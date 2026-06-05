<?php

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('backfills legacy destination and publication types to canonical values', function () {
    $organization = Organization::query()->create([
        'name' => 'Backfill Org',
        'slug' => 'backfill-org-'.Str::random(6),
        'status' => 'active',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Backfill Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => 'Backfill Site',
        'site_url' => 'https://backfill.example.com',
        'base_url' => 'https://backfill.example.com',
        'allowed_domains' => ['backfill.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $destination = ContentDestination::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'name' => 'Legacy Destination',
        'type' => 'api',
        'status' => 'active',
        'environment' => 'production',
        'default_language' => 'en',
        'tracking_enabled' => true,
        'seo_audit_enabled' => true,
        'config' => ['billing_client_site_id' => (string) $site->id],
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'content_destination_id' => $destination->id,
        'title' => 'Backfill Content',
        'primary_keyword' => 'backfill',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'laravel',
        'delivery_status' => 'pending',
    ]);

    $publication = ContentPublication::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'destination_id' => $destination->id,
        'client_site_id' => $site->id,
        'provider' => 'wordpress',
        'delivery_status' => 'pending',
    ]);

    DB::table('content_destinations')
        ->where('id', $destination->id)
        ->update(['type' => 'api_only']);

    DB::table('content_publications')
        ->where('id', $publication->id)
        ->update(['provider' => 'webhook_target']);

    $migration = require base_path('database/migrations/2026_03_31_120000_normalize_destination_types.php');
    $migration->up();

    expect(DB::table('content_destinations')->where('id', $destination->id)->value('type'))->toBe('api')
        ->and(DB::table('content_publications')->where('id', $publication->id)->value('provider'))->toBe('api');
});
