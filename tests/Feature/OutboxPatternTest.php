<?php

namespace Tests\Feature;

use App\Jobs\ProcessOutboxMessageJob;
use App\Models\Account;
use App\Models\Brand;
use App\Models\ConnectorInstallation;
use App\Models\ConnectorVersion;
use App\Models\ContentAsset;
use App\Models\OutboxMessage;
use App\Models\PublishingChannel;
use App\Models\Role;
use App\Models\User;
use App\Services\CreditService;
use App\Services\OutboxService;
use App\Services\PublishingService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\ConnectorCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OutboxPatternTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbox_messages_are_enqueued_idempotently_and_processed_once(): void
    {
        Queue::fake();

        [$user, $account, $brand] = $this->tenantUser('owner');

        $first = app(OutboxService::class)->enqueue($account, $brand, 'linkedin_publishing', [
            'idempotency_key' => 'linkedin-post:123',
            'body' => 'Prepared LinkedIn post.',
        ]);
        $second = app(OutboxService::class)->enqueue($account, $brand, 'linkedin_publishing', [
            'idempotency_key' => 'linkedin-post:123',
            'body' => 'Prepared LinkedIn post.',
        ]);

        $this->assertSame($first->id, $second->id);
        Queue::assertPushed(ProcessOutboxMessageJob::class, fn (ProcessOutboxMessageJob $job) => $job->outboxMessageId === $first->id);

        (new ProcessOutboxMessageJob($first->id))->handle(app(OutboxService::class));
        (new ProcessOutboxMessageJob($first->id))->handle(app(OutboxService::class));

        $first->refresh();

        $this->assertSame('completed', $first->status);
        $this->assertSame(1, $first->attempts);
        $this->assertNotNull($first->processed_at);
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public function test_publishing_request_creates_provider_outbox_message(): void
    {
        Queue::fake();

        [$publisher, , $brand] = $this->tenantUser('publisher');
        $asset = ContentAsset::factory()->forBrand($brand)->create(['status' => 'approved']);
        $channel = PublishingChannel::factory()->forBrand($brand)->create(['provider' => 'wordpress']);
        $this->connectorInstallation($publisher, $channel);
        $asset->forceFill(['channel_id' => $channel->id])->save();

        $action = app(PublishingService::class)->request($asset, $publisher, ['action' => 'publish']);

        $this->assertDatabaseHas('outbox_messages', [
            'account_id' => $asset->account_id,
            'brand_id' => $asset->brand_id,
            'type' => 'wordpress_publishing',
            'status' => 'pending',
        ]);

        $message = OutboxMessage::query()->where('type', 'wordpress_publishing')->firstOrFail();
        $this->assertSame($action->id, $message->payload['publishing_action_id']);
    }

    public function test_outbox_rejects_cross_account_brand_scope(): void
    {
        [$user, $account] = $this->tenantUser('owner');
        [, , $otherBrand] = $this->tenantUser('owner', 'Other Account', 'other-account');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Outbox message brand must belong to the same account.');

        app(OutboxService::class)->enqueue($account, $otherBrand, 'laravel_connector', [
            'idempotency_key' => "bad-scope:{$user->id}",
        ], dispatch: false);
    }

    /**
     * @return array{User, Account, Brand}
     */
    private function tenantUser(string $roleName, string $accountName = 'Outbox Account', string $accountSlug = 'outbox-account'): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => $accountName, 'slug' => $accountSlug]);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => "{$accountName} Brand",
            'slug' => "{$accountSlug}-brand",
        ]);

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail(), ['account_id' => $account->id]);

        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');
        app(CreditService::class)->grant($account, 1000, $user, 'Test credits');

        return [$user, $account, $brand];
    }

    private function connectorInstallation(User $user, PublishingChannel $channel): ConnectorInstallation
    {
        $this->seed(ConnectorCatalogSeeder::class);

        $version = ConnectorVersion::query()
            ->whereHas('manifest', fn ($query) => $query->where('type', $channel->provider))
            ->firstOrFail();

        return ConnectorInstallation::query()->create([
            'account_id' => $channel->account_id,
            'brand_id' => $channel->brand_id,
            'property_id' => $channel->property_id,
            'channel_id' => $channel->id,
            'connector_manifest_id' => $version->connector_manifest_id,
            'connector_version_id' => $version->id,
            'installed_by_user_id' => $user->id,
            'name' => 'WordPress Connector',
            'status' => 'active',
            'enabled_capabilities' => ['publish_content', 'health_check'],
        ]);
    }
}
