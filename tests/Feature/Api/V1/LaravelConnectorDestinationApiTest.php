<?php

use App\Models\ClientSite;
use App\Models\ContentDestination;
use App\Models\Organization;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Services\Api\ApiScopes;
use App\Services\Integrations\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeLaravelDestinationApiContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Laravel API Org',
        'slug' => 'laravel-api-org-'.Str::random(5),
        'status' => 'active',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Laravel API Workspace',
        'organization_id' => $organization->id,
    ]);

    WorkspaceEntitlement::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'organization_id' => $organization->id,
        'feature_key' => 'api_only_enabled',
        'value_type' => 'bool',
        'value_bool' => true,
        'source' => 'manual',
        'effective_at' => now()->subMinute(),
    ]);

    $destination = ContentDestination::query()->create([
        'workspace_id' => $workspace->id,
        'name' => 'API Destination',
        'type' => 'api',
        'status' => 'active',
        'environment' => 'production',
        'default_language' => 'en',
        'tracking_enabled' => true,
        'seo_audit_enabled' => true,
        'config' => [],
    ]);

    $created = app(ApiKeyService::class)->create(
        workspace: $workspace,
        name: 'Destinations key',
        scopes: [ApiScopes::DESTINATIONS_READ, ApiScopes::DESTINATIONS_WRITE],
        contentDestinationId: (string) $destination->id,
    );

    return [
        'organization' => $organization,
        'workspace' => $workspace,
        'destination' => $destination,
        'plain_key' => $created['plain_text_key'],
    ];
}

it('creates and updates laravel connector destinations through the api', function () {
    $ctx = makeLaravelDestinationApiContext();

    $createResponse = $this->withHeaders([
        'Authorization' => 'Bearer '.$ctx['plain_key'],
    ])->postJson('/api/v1/destinations', [
        'name' => 'Client Laravel Site',
        'type' => 'laravel',
        'config' => [
            'laravel_connector' => [
                'base_url' => 'https://client.example.com',
                'sync_endpoint' => '/argusly/sync',
                'site_id' => 'client-site-1',
                'api_key' => 'shared-secret-123456',
                'enabled' => true,
                'mode' => 'hosted_views',
            ],
        ],
    ]);

    $createResponse->assertCreated()
        ->assertJsonPath('data.type', 'laravel')
        ->assertJsonPath('data.config.laravel_connector.base_url', 'https://client.example.com')
        ->assertJsonPath('data.config.laravel_connector.sync_url', 'https://client.example.com/argusly/sync')
        ->assertJsonPath('data.config.laravel_connector.health_url', 'https://client.example.com/argusly/health')
        ->assertJsonPath('data.config.laravel_connector.has_api_key', true);

    $destinationId = (string) $createResponse->json('data.id');

    $destination = ContentDestination::query()->findOrFail($destinationId);
    expect($destination->billingClientSiteId())->not->toBeNull()
        ->and($destination->laravelConnectorApiKey())->toBe('shared-secret-123456');

    $billingSite = ClientSite::query()->findOrFail($destination->billingClientSiteId());
    expect((string) $billingSite->base_url)->toBe('https://client.example.com')
        ->and((string) $billingSite->name)->toBe('Client Laravel Site');

    $updateResponse = $this->withHeaders([
        'Authorization' => 'Bearer '.$ctx['plain_key'],
    ])->patchJson('/api/v1/destinations/'.$destinationId, [
        'name' => 'Client Laravel Site Updated',
        'status' => 'active',
        'config' => [
            'laravel_connector' => [
                'base_url' => 'https://kb.client.example.com',
                'sync_endpoint' => '/argusly/custom-sync',
                'site_id' => 'client-site-1',
                'enabled' => false,
                'mode' => 'headless',
            ],
        ],
    ]);

    $updateResponse->assertOk()
        ->assertJsonPath('data.name', 'Client Laravel Site Updated')
        ->assertJsonPath('data.config.laravel_connector.base_url', 'https://kb.client.example.com')
        ->assertJsonPath('data.config.laravel_connector.sync_url', 'https://kb.client.example.com/argusly/custom-sync')
        ->assertJsonPath('data.config.laravel_connector.health_url', 'https://kb.client.example.com/api/argusly/health')
        ->assertJsonPath('data.config.laravel_connector.enabled', false)
        ->assertJsonPath('data.config.laravel_connector.mode', 'headless')
        ->assertJsonPath('data.config.laravel_connector.has_api_key', true);
});

it('does not allow one workspace api key to update another workspaces destination', function () {
    $ctxA = makeLaravelDestinationApiContext();
    $ctxB = makeLaravelDestinationApiContext();

    $destinationB = ContentDestination::query()->create([
        'workspace_id' => $ctxB['workspace']->id,
        'name' => 'Other Destination',
        'type' => 'laravel',
        'status' => 'active',
        'environment' => 'production',
        'default_language' => 'en',
        'tracking_enabled' => true,
        'seo_audit_enabled' => true,
        'config' => [
            'laravel_connector' => [
                'base_url' => 'https://other.example.com',
                'site_id' => 'other-site',
                'sync_endpoint' => '/argusly/sync',
            ],
        ],
    ]);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$ctxA['plain_key'],
    ])->patchJson('/api/v1/destinations/'.$destinationB->id, [
        'name' => 'Hijack attempt',
    ])->assertNotFound();
});
