<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\ConnectorInstallation;
use App\Models\ConnectorToken;
use App\Models\ConnectorVersion;
use App\Models\ContentAsset;
use App\Models\ContentTranslation;
use App\Models\DomainEvent;
use App\Models\IntelligenceSignal;
use App\Models\Property;
use App\Models\PublishingAction;
use App\Models\PublishingChannel;
use App\Models\Role;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\ConnectorCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectorApiV1Test extends TestCase
{
    use RefreshDatabase;

    public function test_connector_manifest_requires_valid_token_and_logs_request(): void
    {
        [$installation, $token] = $this->connectorInstallation();

        $this->getJson('/api/v1/connector/manifest')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'missing_connector_token');

        $this->withToken('wrong-token')
            ->getJson('/api/v1/connector/manifest')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_connector_token');

        $this->withToken($token)
            ->getJson('/api/v1/connector/manifest')
            ->assertOk()
            ->assertJsonPath('data.manifest.key', 'wordpress')
            ->assertJsonPath('data.manifest.api_base_path', '/api/v1')
            ->assertJsonPath('data.connector.channel_id', $installation->channel_id);

        $this->assertDatabaseHas('connector_logs', [
            'connector_installation_id' => $installation->id,
            'event' => 'connector.api_request',
            'status' => '200',
        ]);
    }

    public function test_connector_api_is_available_on_configured_api_domain_without_api_prefix(): void
    {
        [$installation, $token] = $this->connectorInstallation();

        $this->withToken($token)
            ->getJson('http://api.argusly.test/v1/connector/manifest')
            ->assertOk()
            ->assertJsonPath('data.manifest.key', 'wordpress')
            ->assertJsonPath('data.manifest.api_base_path', '/v1')
            ->assertJsonPath('data.connector.channel_id', $installation->channel_id);
    }

    public function test_connector_register_health_capabilities_and_events_are_scoped(): void
    {
        [$installation, $token] = $this->connectorInstallation();

        $this->withToken($token)
            ->postJson('/api/v1/connector/register', [
                'endpoint_url' => 'https://wp.example.com/argusly',
                'external_connector_id' => 'wp-prod',
                'connector_version' => '1.0.0',
                'capabilities' => ['publish_content', 'preview_url'],
            ])
            ->assertOk()
            ->assertJsonPath('data.connector.status', 'active');

        $this->assertSame('https://wp.example.com/argusly', $installation->refresh()->endpoint_url);
        $this->assertSame(['publish_content', 'preview_url'], $installation->enabled_capabilities);

        $this->withToken($token)
            ->postJson('/api/v1/connector/health', [
                'status' => 'ok',
                'message' => 'Ready',
                'metrics' => ['latency_ms' => 34],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.connector_status', 'active');

        $this->withToken($token)
            ->getJson('/api/v1/connector/capabilities')
            ->assertOk()
            ->assertJsonPath('data.capabilities.0', 'publish_content');

        $this->withToken($token)
            ->postJson('/api/v1/connector/events', [
                'type' => 'media.uploaded',
                'status' => 'received',
                'payload' => ['url' => 'https://wp.example.com/uploads/image.jpg'],
                'idempotency_key' => 'media-123',
            ])
            ->assertAccepted()
            ->assertJsonPath('data.accepted', true)
            ->assertJsonPath('data.duplicate', false);

        $this->assertDatabaseHas('domain_events', [
            'account_id' => $installation->account_id,
            'brand_id' => $installation->brand_id,
            'event_type' => 'ConnectorEventReceived',
            'subject_id' => $installation->id,
        ]);
        $this->assertDatabaseHas('connector_logs', [
            'connector_installation_id' => $installation->id,
            'event' => 'connector.event_received',
            'status' => 'received',
        ]);
    }

    public function test_connector_event_intake_validates_payload_and_supports_idempotency(): void
    {
        [$installation, $token] = $this->connectorInstallation();

        $this->withToken($token)
            ->postJson('/api/v1/connector/events', [
                'type' => 'content.failed',
                'payload' => ['content_id' => 123],
                'idempotency_key' => 'content-failed-123',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'invalid_connector_event_payload');

        $this->withToken($token)
            ->postJson('/api/v1/connector/events', [
                'type' => 'content.published',
                'payload' => [
                    'content_id' => 123,
                    'language' => 'nl',
                    'locale' => 'nl_NL',
                    'external_locale' => 'nl_NL',
                    'external_translation_group' => 'wp-tr-group',
                    'external_canonical_url' => 'not-a-url',
                ],
                'idempotency_key' => 'content-published-invalid-url',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'invalid_connector_event_payload');

        $this->withToken($token)
            ->postJson('/api/v1/connector/events', [
                'type' => 'content.published',
                'payload' => [
                    'content_id' => 123,
                    'language' => 'nl',
                    'locale' => 'nl_NL',
                    'external_locale' => 'nl_NL',
                    'external_translation_group' => 'wp-tr-group',
                    'external_canonical_url' => 'https://wp.example.com/nl/post',
                ],
                'idempotency_key' => 'content-published-123',
            ])
            ->assertAccepted()
            ->assertJsonPath('data.accepted', true)
            ->assertJsonPath('data.duplicate', false);

        $contentEvent = DomainEvent::query()
            ->where('event_type', 'ConnectorEventReceived')
            ->where('payload->idempotency_key', 'content-published-123')
            ->firstOrFail();

        $this->assertSame('nl', $contentEvent->payload['language']);
        $this->assertSame('nl_NL', $contentEvent->payload['locale']);
        $this->assertSame('nl_NL', $contentEvent->payload['external_locale']);
        $this->assertSame('wp-tr-group', $contentEvent->payload['external_translation_group']);
        $this->assertSame('https://wp.example.com/nl/post', $contentEvent->payload['external_canonical_url']);

        $this->withToken($token)
            ->postJson('/api/v1/connector/events', [
                'type' => 'health.warning',
                'message' => 'Publishing latency is above threshold.',
                'payload' => ['latency_ms' => 2500],
                'idempotency_key' => 'health-warning-1',
            ])
            ->assertAccepted()
            ->assertJsonPath('data.accepted', true)
            ->assertJsonPath('data.duplicate', false);

        $event = DomainEvent::query()
            ->where('event_type', 'ConnectorEventReceived')
            ->where('payload->idempotency_key', 'health-warning-1')
            ->firstOrFail();

        $this->assertSame($installation->id, $event->subject_id);
        $this->assertSame($installation->id, $event->payload['connector_installation_id']);
        $this->assertSame('health.warning', $event->payload['connector_event_type']);
        $this->assertSame('health-warning-1', $event->payload['idempotency_key']);
        $this->assertDatabaseHas('activity_logs', [
            'account_id' => $installation->account_id,
            'brand_id' => $installation->brand_id,
            'event' => 'connector.event.received',
            'subject_id' => $installation->id,
        ]);
        $this->assertDatabaseHas('intelligence_signals', [
            'account_id' => $installation->account_id,
            'brand_id' => $installation->brand_id,
            'source' => 'connector_event',
            'priority' => 'high',
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/connector/events', [
                'type' => 'health.warning',
                'message' => 'Publishing latency is above threshold.',
                'payload' => ['latency_ms' => 2500],
                'idempotency_key' => 'health-warning-1',
            ])
            ->assertAccepted()
            ->assertJsonPath('data.accepted', true)
            ->assertJsonPath('data.duplicate', true)
            ->assertJsonPath('data.domain_event_id', $event->id);

        $this->assertSame(2, DomainEvent::query()->where('event_type', 'ConnectorEventReceived')->count());
        $this->assertSame(1, IntelligenceSignal::query()->where('source', 'connector_event')->count());
    }

    public function test_pending_content_and_published_callback_update_argusly_state(): void
    {
        [$installation, $token, , , $channel] = $this->connectorInstallation();
        $asset = ContentAsset::factory()->forBrand($installation->brand)->create([
            'channel_id' => $channel->id,
            'status' => 'approved',
            'title' => 'Pending Article',
            'language' => 'en',
            'locale' => 'en_US',
            'canonical_url' => 'https://example.com/original',
            'seo_metadata' => ['title' => 'Pending SEO'],
        ]);
        $translated = ContentAsset::factory()->forBrand($installation->brand)->create([
            'channel_id' => $channel->id,
            'status' => 'draft',
            'title' => 'Pending Article NL',
            'language' => 'nl',
            'locale' => 'nl_NL',
            'canonical_url' => 'https://example.com/nl/original',
        ]);
        ContentTranslation::query()->create([
            'account_id' => $installation->account_id,
            'brand_id' => $installation->brand_id,
            'source_content_asset_id' => $asset->id,
            'translated_content_asset_id' => $translated->id,
            'source_language' => 'en',
            'source_locale' => 'en_US',
            'target_language' => 'nl',
            'target_locale' => 'nl_NL',
            'status' => 'draft',
        ]);
        $action = PublishingAction::factory()->forContentAsset($asset)->forPublishingChannel($channel)->create([
            'action' => 'publish',
            'status' => 'queued',
            'language' => 'en',
            'locale' => 'en_US',
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/content/pending')
            ->assertOk()
            ->assertJsonPath('data.0.content.id', $asset->id)
            ->assertJsonPath('data.0.publishing_action.id', $action->id)
            ->assertJsonPath('data.0.publishing_action.language', 'en')
            ->assertJsonPath('data.0.publishing_action.locale', 'en_US')
            ->assertJsonPath('data.0.publishing_action.market', null)
            ->assertJsonPath('data.0.content.language', 'en')
            ->assertJsonPath('data.0.content.locale', 'en_US')
            ->assertJsonPath('data.0.content.market', null)
            ->assertJsonPath('data.0.content.canonical_url', 'https://example.com/original')
            ->assertJsonPath('data.0.content.hreflang.0.language', 'en')
            ->assertJsonPath('data.0.content.hreflang.1.language', 'nl')
            ->assertJsonPath('data.0.content.translated_from', null)
            ->assertJsonPath('data.0.content.translation_group_id', $asset->id)
            ->assertJsonPath('data.0.content.seo_metadata.title', 'Pending SEO');

        $this->withToken($token)
            ->postJson("/api/v1/content/{$asset->id}/published", [
                'external_id' => 'wp-123',
                'external_url' => 'https://wp.example.com/pending-article',
                'language' => 'en',
                'locale' => 'en_US',
                'external_locale' => 'en_US',
                'external_translation_group' => 'wp-tr-group',
                'external_canonical_url' => 'https://wp.example.com/canonical/pending-article',
                'response' => ['ok' => true],
            ])
            ->assertOk()
            ->assertJsonPath('data.content.status', 'published')
            ->assertJsonPath('data.content.language', 'en')
            ->assertJsonPath('data.content.locale', 'en_US')
            ->assertJsonPath('data.publishing_action.status', 'completed');

        $this->assertSame('published', $asset->refresh()->status);
        $this->assertSame('https://wp.example.com/canonical/pending-article', $asset->canonical_url);
        $this->assertSame('completed', $action->refresh()->status);
        $this->assertSame('en_US', $action->response_payload['external_locale']);
        $this->assertSame('wp-tr-group', $action->response_payload['external_translation_group']);
        $this->assertDatabaseHas('domain_events', [
            'account_id' => $installation->account_id,
            'brand_id' => $installation->brand_id,
            'event_type' => 'ContentAssetPublished',
            'subject_id' => $asset->id,
        ]);
        $this->assertDatabaseHas('connector_logs', [
            'connector_installation_id' => $installation->id,
            'event' => 'connector.content_published',
            'status' => 'published',
        ]);
    }

    public function test_failed_callback_is_channel_and_tenant_safe(): void
    {
        [$installation, $token, , , $channel] = $this->connectorInstallation();
        [$otherInstallation] = $this->connectorInstallation();
        $asset = ContentAsset::factory()->forBrand($otherInstallation->brand)->create([
            'channel_id' => $otherInstallation->channel_id,
            'status' => 'approved',
        ]);
        PublishingAction::factory()->forContentAsset($asset)->forPublishingChannel($otherInstallation->channel)->create([
            'status' => 'queued',
        ]);

        $this->withToken($token)
            ->postJson("/api/v1/content/{$asset->id}/failed", [
                'message' => 'Remote API rejected the payload.',
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'content_not_found');

        $visibleAsset = ContentAsset::factory()->forBrand($installation->brand)->create([
            'channel_id' => $channel->id,
            'status' => 'approved',
        ]);
        $visibleAction = PublishingAction::factory()->forContentAsset($visibleAsset)->forPublishingChannel($channel)->create([
            'status' => 'processing',
        ]);

        $this->withToken($token)
            ->postJson("/api/v1/content/{$visibleAsset->uuid}/failed", [
                'message' => 'Remote API rejected the payload.',
                'language' => 'en',
                'locale' => 'en_US',
                'external_locale' => 'en_US',
                'external_translation_group' => 'wp-tr-failed',
                'external_canonical_url' => 'https://wp.example.com/failed',
                'response' => ['code' => 'invalid_payload'],
            ])
            ->assertOk()
            ->assertJsonPath('data.content.status', 'failed')
            ->assertJsonPath('data.publishing_action.status', 'failed');

        $this->assertSame('failed', $visibleAsset->refresh()->status);
        $this->assertSame('Remote API rejected the payload.', $visibleAction->refresh()->error_message);
        $this->assertSame('en_US', $visibleAction->response_payload['external_locale']);
        $this->assertSame('wp-tr-failed', $visibleAction->response_payload['external_translation_group']);
        $this->assertSame('https://wp.example.com/failed', $visibleAction->response_payload['external_canonical_url']);
    }

    public function test_connector_api_requires_complete_installation_scope(): void
    {
        [$installation, $token] = $this->connectorInstallation();
        $installation->update(['channel_id' => null]);

        $this->withToken($token)
            ->getJson('/api/v1/connector/manifest')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'connector_scope_incomplete');
    }

    public function test_connector_api_enforces_token_abilities_and_revocation(): void
    {
        [$installation, $token] = $this->connectorInstallation(['connector:read']);

        $this->withToken($token)
            ->getJson('/api/v1/connector/manifest')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/content/pending')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'connector_token_forbidden');

        $connectorToken = ConnectorToken::query()->where('connector_installation_id', $installation->id)->firstOrFail();
        $connectorToken->update(['revoked_at' => now()]);

        $this->withToken($token)
            ->getJson('/api/v1/connector/manifest')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'connector_token_inactive');
    }

    /**
     * @return array{0: ConnectorInstallation, 1: string, 2: Account, 3: Brand, 4: PublishingChannel}
     */
    private function connectorInstallation(?array $abilities = null): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);
        $this->seed(ConnectorCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => fake()->company(), 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => fake()->company(), 'slug' => fake()->unique()->slug()]);
        $role = Role::query()->where('name', 'owner')->firstOrFail();
        $property = Property::factory()->forBrand($brand)->create();
        $channel = PublishingChannel::factory()->forProperty($property)->create(['provider' => 'wordpress']);
        $version = ConnectorVersion::query()
            ->whereHas('manifest', fn ($query) => $query->where('key', 'wordpress'))
            ->firstOrFail();
        $token = 'connector-token-'.fake()->uuid();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'scale_monthly');

        $installation = ConnectorInstallation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'property_id' => $property->id,
            'channel_id' => $channel->id,
            'connector_manifest_id' => $version->connector_manifest_id,
            'connector_version_id' => $version->id,
            'installed_by_user_id' => $user->id,
            'name' => 'WordPress Connector',
            'status' => 'active',
            'enabled_capabilities' => ['publish_content', 'preview_url', 'health_check'],
        ]);

        ConnectorToken::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'connector_installation_id' => $installation->id,
            'name' => 'API token',
            'token_hash' => ConnectorToken::hashToken($token),
            'abilities' => $abilities ?: ConnectorToken::ABILITIES,
            'created_by' => $user->id,
        ]);

        return [$installation, $token, $account, $brand, $channel];
    }
}
