<?php

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Api\ApiScopes;
use App\Services\Integrations\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('soft deletes a single content item via the api without deleting sibling variants', function () {
    $ctx = makeContentDeletionApiContext([ApiScopes::CONTENT_WRITE]);
    [$source, $variant] = createLocalizedContentFamily($ctx['workspace'], $ctx['site'], $ctx['destination']);

    $this->withHeaders(contentDeletionApiHeaders($ctx['plain_key']))
        ->deleteJson('/api/v1/content/'.$source->id)
        ->assertOk()
        ->assertJsonPath('data.scope', 'single')
        ->assertJsonPath('data.count', 1);

    expect(Content::withTrashed()->find($source->id)?->trashed())->toBeTrue()
        ->and(Content::find($variant->id))->not->toBeNull();
});

it('soft deletes an entire content family via the api', function () {
    $ctx = makeContentDeletionApiContext([ApiScopes::CONTENT_WRITE]);
    [$source, $variant] = createLocalizedContentFamily($ctx['workspace'], $ctx['site'], $ctx['destination']);

    $this->withHeaders(contentDeletionApiHeaders($ctx['plain_key']))
        ->deleteJson('/api/v1/content/'.$source->id.'?scope=family')
        ->assertOk()
        ->assertJsonPath('data.scope', 'family')
        ->assertJsonPath('data.count', 2);

    expect(Content::withTrashed()->find($source->id)?->trashed())->toBeTrue()
        ->and(Content::withTrashed()->find($variant->id)?->trashed())->toBeTrue();
});

it('restores a soft deleted content item via the api', function () {
    $ctx = makeContentDeletionApiContext([ApiScopes::CONTENT_WRITE]);
    [$source] = createLocalizedContentFamily($ctx['workspace'], $ctx['site'], $ctx['destination']);
    $source->delete();

    $this->withHeaders(contentDeletionApiHeaders($ctx['plain_key']))
        ->postJson('/api/v1/content/'.$source->id.'/restore')
        ->assertOk()
        ->assertJsonPath('data.count', 1);

    expect(Content::find($source->id))->not->toBeNull()
        ->and(Content::withTrashed()->find($source->id)?->trashed())->toBeFalse();
});

it('supports bulk family soft delete via the api', function () {
    $ctx = makeContentDeletionApiContext([ApiScopes::CONTENT_WRITE]);
    [$sourceA, $variantA] = createLocalizedContentFamily($ctx['workspace'], $ctx['site'], $ctx['destination'], 'Family A');
    [$sourceB, $variantB] = createLocalizedContentFamily($ctx['workspace'], $ctx['site'], $ctx['destination'], 'Family B');

    $this->withHeaders(contentDeletionApiHeaders($ctx['plain_key']))
        ->deleteJson('/api/v1/content/bulk', [
            'ids' => [(string) $sourceA->id, (string) $sourceB->id],
            'scope' => 'family',
        ])
        ->assertOk()
        ->assertJsonPath('data.count', 4);

    expect(Content::withTrashed()->find($sourceA->id)?->trashed())->toBeTrue()
        ->and(Content::withTrashed()->find($variantA->id)?->trashed())->toBeTrue()
        ->and(Content::withTrashed()->find($sourceB->id)?->trashed())->toBeTrue()
        ->and(Content::withTrashed()->find($variantB->id)?->trashed())->toBeTrue();
});

function makeContentDeletionApiContext(array $scopes): array
{
    $organization = Organization::query()->create([
        'name' => 'Content Delete API Org',
        'slug' => 'content-delete-api-'.Str::random(5),
        'status' => 'active',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Content Delete API Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'laravel',
        'name' => 'Delete API Site',
        'site_url' => 'https://delete-api-'.Str::random(6).'.example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $destination = ContentDestination::query()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Delete API Destination',
        'type' => 'api',
        'status' => 'active',
        'environment' => 'production',
    ]);

    $created = app(ApiKeyService::class)->create(
        workspace: $workspace,
        name: 'Delete API key',
        scopes: $scopes,
        contentDestinationId: (string) $destination->id,
    );

    return [
        'workspace' => $workspace,
        'site' => $site,
        'destination' => $destination,
        'plain_key' => $created['plain_text_key'],
    ];
}

function contentDeletionApiHeaders(string $plainKey): array
{
    return [
        'Authorization' => 'Bearer '.$plainKey,
    ];
}

/**
 * @return array{0: Content, 1: Content}
 */
function createLocalizedContentFamily(Workspace $workspace, ClientSite $site, ContentDestination $destination, string $prefix = 'Family'): array
{
    $source = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'content_destination_id' => (string) $destination->id,
        'title' => $prefix.' NL',
        'language' => 'nl',
        'family_id' => null,
        'is_source_locale' => true,
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'draft',
        'source' => 'api',
    ]);

    $variant = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'content_destination_id' => (string) $destination->id,
        'title' => $prefix.' EN',
        'language' => 'en',
        'family_id' => (string) $source->id,
        'translation_source_content_id' => (string) $source->id,
        'translation_source_locale' => 'nl',
        'is_source_locale' => false,
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'draft',
        'source' => 'api',
    ]);

    return [$source->fresh(), $variant->fresh()];
}
