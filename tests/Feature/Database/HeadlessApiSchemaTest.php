<?php

use App\Models\ApiKey;
use App\Models\ApiWebhook;
use App\Models\ContentDestination;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates headless api tables and core destination links', function () {
    expect(Schema::hasTable('content_destinations'))->toBeTrue();
    expect(Schema::hasTable('api_keys'))->toBeTrue();
    expect(Schema::hasTable('api_webhooks'))->toBeTrue();
    expect(Schema::hasTable('api_webhook_deliveries'))->toBeTrue();
    expect(Schema::hasTable('api_request_logs'))->toBeTrue();
    expect(Schema::hasTable('async_operation_runs'))->toBeTrue();

    expect(Schema::hasColumn('briefs', 'content_destination_id'))->toBeTrue();
    expect(Schema::hasColumn('drafts', 'content_destination_id'))->toBeTrue();
    expect(Schema::hasColumn('contents', 'content_destination_id'))->toBeTrue();
    expect(Schema::hasColumn('seo_audits', 'content_destination_id'))->toBeTrue();
});

it('links workspace to destinations keys and webhooks', function () {
    $organization = Organization::query()->create([
        'name' => 'Rel Org',
        'slug' => 'rel-org-'.Str::random(6),
        'status' => 'active',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Rel Workspace',
        'organization_id' => $organization->id,
    ]);

    $destination = ContentDestination::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'name' => 'Rel Destination',
        'type' => 'api',
        'status' => 'active',
        'environment' => 'production',
        'default_language' => 'en',
        'tracking_enabled' => true,
        'seo_audit_enabled' => true,
    ]);

    ApiKey::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'content_destination_id' => $destination->id,
        'name' => 'Rel Key',
        'key_prefix' => 'plk_rel',
        'key_hash' => hash('sha256', 'rel_key_'.Str::random(10)),
        'scopes' => ['briefs:read'],
    ]);

    ApiWebhook::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'content_destination_id' => $destination->id,
        'name' => 'Rel Webhook',
        'target_url' => 'https://example.com/webhook',
        'secret' => Str::random(32),
        'events' => ['brief.created'],
        'is_active' => true,
    ]);

    $workspace->load(['contentDestinations', 'apiKeys', 'apiWebhooks']);

    expect($workspace->contentDestinations)->toHaveCount(1);
    expect($workspace->apiKeys)->toHaveCount(1);
    expect($workspace->apiWebhooks)->toHaveCount(1);
});
