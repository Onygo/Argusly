<?php

namespace Tests\Feature;

use App\Jobs\PublishContentAssetJob;
use App\Models\Account;
use App\Models\AnswerBlock;
use App\Models\Brand;
use App\Models\ConnectorInstallation;
use App\Models\ConnectorToken;
use App\Models\ConnectorVersion;
use App\Models\ContentAsset;
use App\Models\ContentTranslation;
use App\Models\IntelligenceSignal;
use App\Models\OutboxMessage;
use App\Models\Property;
use App\Models\PublishingAction;
use App\Models\PublishingChannel;
use App\Models\Role;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\ConnectorCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ConnectorPublishingQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_connector_publishing_happy_path_queues_pull_payload_and_completes_from_api(): void
    {
        Queue::fake();

        [$publisher, $installation, $token, $channel] = $this->connectorTenant();
        $asset = ContentAsset::factory()->forBrand($installation->brand)->create([
            'channel_id' => $channel->id,
            'status' => 'approved',
            'title' => 'Connector Queue Article',
            'slug' => 'connector-queue-article',
            'language' => 'en',
            'locale' => 'en_US',
            'canonical_url' => 'https://example.com/original',
            'excerpt' => 'Short connector excerpt.',
            'body' => 'Connector body copy.',
            'seo_metadata' => ['title' => 'Connector SEO title'],
        ]);
        AnswerBlock::factory()->forContentAsset($asset)->create([
            'question' => 'What is connector publishing?',
            'answer' => 'A connector pulls approved content and reports the result.',
            'status' => 'approved',
            'position' => 1,
        ]);
        $translated = ContentAsset::factory()->forBrand($installation->brand)->create([
            'title' => 'Connector Queue Article NL',
            'channel_id' => $channel->id,
            'language' => 'nl',
            'locale' => 'nl_NL',
            'canonical_url' => 'https://example.com/nl/connector-queue-article',
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

        $this->actingAs($publisher)
            ->post(route('app.content.publish', $asset))
            ->assertRedirect(route('app.content.show', $asset));

        $action = PublishingAction::query()->where('content_asset_id', $asset->id)->firstOrFail();

        $this->assertSame('queued', $action->status);
        $this->assertSame('publish', $action->action);
        Queue::assertNotPushed(PublishContentAssetJob::class);
        $this->assertDatabaseHas('outbox_messages', [
            'account_id' => $installation->account_id,
            'brand_id' => $installation->brand_id,
            'type' => 'wordpress_publishing',
            'status' => 'pending',
        ]);
        $outbox = OutboxMessage::query()->firstOrFail();
        $this->assertTrue($outbox->payload['connector_queue']);
        $this->assertSame('en', $outbox->payload['language']);
        $this->assertSame('en_US', $outbox->payload['locale']);
        $this->assertNull($outbox->payload['market']);
        $this->assertSame('https://example.com/original', $outbox->payload['canonical_url']);
        $this->assertSame($asset->id, $outbox->payload['translation_group_id']);
        $this->assertNull($outbox->payload['translated_from']);
        $this->assertSame('nl', $outbox->payload['hreflang'][1]['language']);
        $this->assertSame('Connector Queue Article', $outbox->payload['content']['title']);
        $this->assertSame('What is connector publishing?', $outbox->payload['content']['answer_blocks'][0]['question']);

        $this->withToken($token)
            ->getJson('/api/v1/content/pending')
            ->assertOk()
            ->assertJsonPath('data.0.publishing_action.id', $action->id)
            ->assertJsonPath('data.0.publishing_action.action', 'publish')
            ->assertJsonPath('data.0.content.title', 'Connector Queue Article')
            ->assertJsonPath('data.0.content.slug', 'connector-queue-article')
            ->assertJsonPath('data.0.publishing_action.language', 'en')
            ->assertJsonPath('data.0.publishing_action.locale', 'en_US')
            ->assertJsonPath('data.0.content.language', 'en')
            ->assertJsonPath('data.0.content.locale', 'en_US')
            ->assertJsonPath('data.0.content.market', null)
            ->assertJsonPath('data.0.content.seo_metadata.title', 'Connector SEO title')
            ->assertJsonPath('data.0.content.canonical_url', 'https://example.com/original')
            ->assertJsonPath('data.0.content.translation_group_id', $asset->id)
            ->assertJsonPath('data.0.content.translated_from', null)
            ->assertJsonPath('data.0.content.hreflang.0.language', 'en')
            ->assertJsonPath('data.0.content.hreflang.1.language', 'nl')
            ->assertJsonPath('data.0.content.answer_blocks.0.language', 'en')
            ->assertJsonPath('data.0.content.answer_blocks.0.answer', 'A connector pulls approved content and reports the result.');

        $this->withToken($token)
            ->postJson("/api/v1/content/{$asset->id}/published", [
                'external_id' => 'wp-post-100',
                'external_url' => 'https://wp.example.com/connector-queue-article',
                'language' => 'en',
                'locale' => 'en_US',
                'external_locale' => 'en_US',
                'external_translation_group' => 'tr-group-100',
                'external_canonical_url' => 'https://wp.example.com/canonical/connector-queue-article',
                'response' => ['remote_status' => 'published'],
            ])
            ->assertOk()
            ->assertJsonPath('data.content.language', 'en')
            ->assertJsonPath('data.content.locale', 'en_US')
            ->assertJsonPath('data.content.status', 'published')
            ->assertJsonPath('data.publishing_action.status', 'completed');

        $this->assertSame('completed', $action->refresh()->status);
        $this->assertSame('published', $asset->refresh()->status);
        $this->assertSame('https://wp.example.com/canonical/connector-queue-article', $asset->canonical_url);
        $this->assertSame('en_US', $action->refresh()->response_payload['external_locale']);
        $this->assertSame('tr-group-100', $action->response_payload['external_translation_group']);
        $this->assertDatabaseHas('domain_events', [
            'account_id' => $installation->account_id,
            'brand_id' => $installation->brand_id,
            'event_type' => 'ContentAssetPublished',
            'subject_id' => $asset->id,
        ]);
        $this->assertDatabaseHas('intelligence_signals', [
            'account_id' => $installation->account_id,
            'brand_id' => $installation->brand_id,
            'source' => 'content_published',
        ]);
        $this->assertDatabaseHas('intelligence_signals', [
            'account_id' => $installation->account_id,
            'brand_id' => $installation->brand_id,
            'source' => 'content_publishing',
        ]);
    }

    public function test_connector_publishing_failed_path_updates_state_event_and_signal(): void
    {
        Queue::fake();

        [$publisher, $installation, $token, $channel] = $this->connectorTenant();
        $asset = ContentAsset::factory()->forBrand($installation->brand)->create([
            'channel_id' => $channel->id,
            'status' => 'approved',
            'title' => 'Failing Connector Article',
        ]);

        $this->actingAs($publisher)
            ->post(route('app.content.publish', $asset))
            ->assertRedirect(route('app.content.show', $asset));

        $action = PublishingAction::query()->where('content_asset_id', $asset->id)->firstOrFail();

        $this->withToken($token)
            ->postJson("/api/v1/content/{$asset->uuid}/failed", [
                'message' => 'Remote connector rejected the payload.',
                'response' => ['code' => 'invalid_payload'],
            ])
            ->assertOk()
            ->assertJsonPath('data.content.status', 'failed')
            ->assertJsonPath('data.publishing_action.status', 'failed');

        $this->assertSame('failed', $action->refresh()->status);
        $this->assertSame('Remote connector rejected the payload.', $action->error_message);
        $this->assertSame('failed', $asset->refresh()->status);
        $this->assertDatabaseHas('domain_events', [
            'account_id' => $installation->account_id,
            'brand_id' => $installation->brand_id,
            'event_type' => 'ContentPublishingFailed',
            'subject_id' => $action->id,
        ]);

        $signal = IntelligenceSignal::query()->where('type', 'publishing_failed')->firstOrFail();
        $this->assertSame($action->id, $signal->payload['publishing_action_id']);
        $this->assertSame('Remote connector rejected the payload.', $signal->payload['error_message']);
    }

    /**
     * @return array{0: User, 1: ConnectorInstallation, 2: string, 3: PublishingChannel}
     */
    private function connectorTenant(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);
        $this->seed(ConnectorCatalogSeeder::class);

        $publisher = User::factory()->create();
        $account = Account::query()->create(['name' => fake()->company(), 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Connector Brand', 'slug' => fake()->unique()->slug()]);
        $role = Role::query()->where('name', 'publisher')->firstOrFail();
        $property = Property::factory()->forBrand($brand)->create();
        $channel = PublishingChannel::factory()->forProperty($property)->create(['provider' => 'wordpress']);
        $version = ConnectorVersion::query()
            ->whereHas('manifest', fn ($query) => $query->where('key', 'wordpress'))
            ->firstOrFail();
        $plainToken = ConnectorToken::plainToken();

        $publisher->accounts()->attach($account, ['status' => 'active']);
        $publisher->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $publisher->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');
        app(CreditService::class)->grant($account, 1000, $publisher, 'Test credits');

        $installation = ConnectorInstallation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'property_id' => $property->id,
            'channel_id' => $channel->id,
            'connector_manifest_id' => $version->connector_manifest_id,
            'connector_version_id' => $version->id,
            'installed_by_user_id' => $publisher->id,
            'name' => 'WordPress Production',
            'status' => 'active',
            'enabled_capabilities' => ['publish_content', 'preview_url', 'health_check'],
        ]);

        ConnectorToken::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'connector_installation_id' => $installation->id,
            'name' => 'Publishing token',
            'token_hash' => ConnectorToken::hashToken($plainToken),
            'abilities' => ['content:read', 'content:publish'],
            'created_by' => $publisher->id,
        ]);

        return [$publisher, $installation, $plainToken, $channel];
    }
}
