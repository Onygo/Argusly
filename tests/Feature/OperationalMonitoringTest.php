<?php

namespace Tests\Feature;

use App\Jobs\DispatchWebhookDeliveryJob;
use App\Jobs\ProcessOutboxMessageJob;
use App\Jobs\ProjectDomainEventJob;
use App\Jobs\RecordWorkerHeartbeatJob;
use App\Jobs\RetryWebhookDeliveryJob;
use App\Jobs\RunSourceSyncJob;
use App\Models\Account;
use App\Models\Brand;
use App\Models\IntelligenceSignal;
use App\Models\NotificationEvent;
use App\Models\Role;
use App\Models\SignalAlert;
use App\Models\Source;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WorkerHeartbeat;
use App\Services\AlertService;
use App\Services\PlatformHealthService;
use App\Services\QueueHealthService;
use App\Services\SchedulerMonitorService;
use App\Services\SignalManager;
use App\Services\SourceHealthService;
use App\Services\SourceRegistryService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OperationalMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_high_severity_signal_triggers_alert_notification_and_lifecycle(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');

        $signal = app(SignalManager::class)->record($account, [
            'source' => 'monitoring',
            'type' => 'technical_issue',
            'category' => 'system',
            'priority' => 'high',
            'severity' => 'critical',
            'dedupe_key' => 'monitoring:test-alert',
            'title' => 'Worker queue is unhealthy',
            'summary' => 'Critical operational condition detected.',
            'status' => 'new',
        ], $brand, generateRecommendations: false);

        $alert = SignalAlert::query()->where('intelligence_signal_id', $signal->id)->firstOrFail();

        $this->assertSame('critical', $signal->refresh()->severity);
        $this->assertSame('open', $alert->status);
        $this->assertDatabaseHas('notification_events', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'type' => 'operational_alert',
            'title' => 'Worker queue is unhealthy',
        ]);

        app(AlertService::class)->acknowledge($alert, $user);
        $this->assertSame('acknowledged', $alert->refresh()->status);
        $this->assertSame($user->id, $alert->acknowledged_by);

        app(AlertService::class)->resolve($alert, $user);
        $this->assertSame('resolved', $alert->refresh()->status);
        $this->assertSame($user->id, $alert->resolved_by);
    }

    public function test_platform_alert_dashboard_allows_acknowledge_and_resolve(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $admin = $this->platformAdmin();
        $signal = IntelligenceSignal::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'source' => 'monitoring',
            'type' => 'technical_issue',
            'category' => 'system',
            'priority' => 'high',
            'severity' => 'high',
            'title' => 'Source failure',
            'summary' => 'Source failed repeatedly.',
            'status' => 'new',
        ]);
        $alert = app(AlertService::class)->triggerForSignal($signal);

        $this->actingAs($admin)
            ->get(route('admin.platform.alerts'))
            ->assertOk()
            ->assertSee('Alerts')
            ->assertSee('Source failure');

        $this->actingAs($admin)
            ->post(route('admin.platform.alerts.acknowledge', $alert))
            ->assertRedirect();

        $this->assertSame('acknowledged', $alert->refresh()->status);

        $this->actingAs($admin)
            ->post(route('admin.platform.alerts.resolve', $alert))
            ->assertRedirect();

        $this->assertSame('resolved', $alert->refresh()->status);
        $this->assertSame($user->id, NotificationEvent::query()->where('type', 'operational_alert')->first()?->user_id);
    }

    public function test_named_queues_worker_health_and_platform_dashboard_are_visible(): void
    {
        $admin = $this->platformAdmin();

        WorkerHeartbeat::query()->create([
            'worker_name' => 'worker-intelligence-1',
            'queue' => config('queue.names.intelligence'),
            'status' => 'running',
            'last_seen_at' => now()->subMinutes(10),
        ]);

        $snapshot = app(QueueHealthService::class)->snapshot();
        $queues = $snapshot['queue_matrix']->pluck('name')->all();

        foreach (['critical', 'ai', 'intelligence', 'publishing', 'webhooks', 'integrations', 'mail', 'maintenance'] as $queue) {
            $this->assertContains($queue, $queues);
        }

        $this->assertSame(1, $snapshot['stale_heartbeats']->count());

        $this->actingAs($admin)
            ->get(route('admin.platform.queues'))
            ->assertOk()
            ->assertSee('Named Queues')
            ->assertSee('intelligence')
            ->assertSee('worker heartbeat');

        $this->actingAs($admin)
            ->get(route('admin.platform.overview'))
            ->assertOk()
            ->assertSee('Alert Summary')
            ->assertSee('Source Health')
            ->assertSee('Scheduler');
    }

    public function test_webhook_retry_management_resets_failed_delivery_on_webhooks_queue(): void
    {
        $endpoint = WebhookEndpoint::query()->create([
            'name' => 'Ops Hook',
            'url' => 'https://example.com/webhooks',
            'status' => 'active',
            'events' => ['test.event'],
        ]);
        $delivery = WebhookDelivery::query()->create([
            'webhook_endpoint_id' => $endpoint->id,
            'event' => 'test.event',
            'status' => 'failed',
            'payload' => ['ok' => true],
            'attempts' => 2,
            'error_message' => 'Timeout',
            'available_at' => now()->subMinute(),
            'next_retry_at' => now()->addMinutes(10),
            'failed_at' => now(),
        ]);

        $retryJob = new RetryWebhookDeliveryJob($delivery->id);
        $retryJob->handle();

        $this->assertSame('pending', $delivery->refresh()->status);
        $this->assertNull($delivery->error_message);
        $this->assertNull($delivery->next_retry_at);

        $dispatchJob = new DispatchWebhookDeliveryJob($delivery->id);
        $dispatchJob->handle();

        $this->assertSame('pending', $delivery->refresh()->status);
        $this->assertNotNull($delivery->next_retry_at);
    }

    public function test_source_and_scheduler_health_snapshots_track_operational_state(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $source = Source::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'RSS source',
            'type' => 'blog',
            'provider' => 'rss',
            'status' => 'active',
        ]);
        $sync = app(SourceRegistryService::class)->createPlannedSync($source);
        (new RunSourceSyncJob($sync->id))->handle(app(SourceRegistryService::class));

        $sourceHealth = app(SourceHealthService::class)->snapshot();
        $this->assertSame(1, $sourceHealth['healthy']);

        app(SchedulerMonitorService::class)->markHeartbeat();
        $scheduler = app(SchedulerMonitorService::class)->snapshot();
        $this->assertSame('healthy', $scheduler['status']);

        $platform = app(PlatformHealthService::class)->snapshot();
        $this->assertArrayHasKey('sources', $platform);
        $this->assertArrayHasKey('scheduler', $platform);
        $this->assertArrayHasKey('alerts', $platform);
    }

    public function test_operational_jobs_are_assigned_to_named_queues(): void
    {
        $this->assertSame(config('queue.names.intelligence'), (new ProjectDomainEventJob(1))->queue);
        $this->assertSame(config('queue.names.publishing'), (new ProcessOutboxMessageJob(1))->queue);
        $this->assertSame(config('queue.names.webhooks'), (new RetryWebhookDeliveryJob(1))->queue);
        $this->assertSame(config('queue.names.webhooks'), (new DispatchWebhookDeliveryJob(1))->queue);
        $this->assertSame(config('queue.names.maintenance'), (new RecordWorkerHeartbeatJob('worker'))->queue);
        $this->assertSame(config('queue.names.integrations'), (new RunSourceSyncJob(1))->queue);
    }

    /**
     * @return array{User, Account, Brand}
     */
    private function tenantUser(string $roleName): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Ops Account', 'slug' => 'ops-account-'.uniqid()]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Ops Brand', 'slug' => 'ops-brand-'.uniqid()]);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');

        return [$user, $account, $brand];
    }

    private function platformAdmin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $role = Role::query()->where('name', 'platform_admin')->firstOrFail();
        $user->roles()->attach($role);

        return $user;
    }
}
