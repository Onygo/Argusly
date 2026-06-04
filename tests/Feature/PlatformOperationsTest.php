<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\FeatureFlag;
use App\Models\LlmRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WorkerHeartbeat;
use App\Services\WebhookDeliveryService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlatformOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_operations_tables_exist(): void
    {
        foreach (['feature_flags', 'webhook_endpoints', 'webhook_deliveries', 'worker_heartbeats'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        $this->assertTrue(Schema::hasColumns('feature_flags', ['key', 'scope', 'enabled', 'rules']));
        $this->assertTrue(Schema::hasColumns('webhook_endpoints', ['account_id', 'brand_id', 'url', 'events', 'status']));
        $this->assertTrue(Schema::hasColumns('webhook_deliveries', ['webhook_endpoint_id', 'event', 'idempotency_key', 'payload', 'status']));
    }

    public function test_platform_admin_can_open_operations_dashboard(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        WorkerHeartbeat::query()->create([
            'worker_name' => 'worker-1',
            'queue' => 'default',
            'status' => 'running',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($this->platformAdmin())
            ->get(route('admin.platform.overview'))
            ->assertOk()
            ->assertSee('Operations')
            ->assertSee('Queue Summary')
            ->assertSee(route('admin.platform.feature-flags'), false);
    }

    public function test_platform_admin_can_manage_feature_flags(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->post(route('admin.platform.feature-flags.store'), [
                'key' => 'agentic_marketing_beta',
                'name' => 'Agentic Marketing Beta',
                'scope' => 'pilot',
                'enabled' => '1',
                'rules' => '{"account_ids":[1]}',
            ])
            ->assertRedirect();

        $flag = FeatureFlag::query()->firstOrFail();

        $this->assertSame('agentic_marketing_beta', $flag->key);
        $this->assertTrue($flag->enabled);
        $this->assertSame(['account_ids' => [1]], $flag->rules);
        $this->assertSame($admin->id, $flag->created_by);

        $this->actingAs($admin)
            ->patch(route('admin.platform.feature-flags.update', $flag), [
                'key' => 'agentic_marketing_beta',
                'name' => 'Agentic Marketing Beta',
                'scope' => 'platform',
            ])
            ->assertRedirect();

        $this->assertFalse($flag->fresh()->enabled);
        $this->assertSame('platform', $flag->fresh()->scope);
    }

    public function test_platform_admin_can_manage_webhook_endpoints_and_queue_delivery(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        [$account, $brand] = $this->tenant();

        $this->actingAs($this->platformAdmin())
            ->post(route('admin.platform.webhooks.store'), [
                'account_id' => $account->id,
                'brand_id' => $brand->id,
                'name' => 'CRM endpoint',
                'url' => 'https://example.test/webhooks/argusly',
                'status' => 'active',
                'events' => ['signal.created', 'visibility.run.completed'],
            ])
            ->assertRedirect();

        $endpoint = WebhookEndpoint::query()->firstOrFail();

        $deliveries = app(WebhookDeliveryService::class)->enqueue(
            'signal.created',
            ['signal_id' => 123],
            $account,
            $brand,
        );

        $this->assertCount(1, $deliveries);
        $this->assertDatabaseHas('webhook_deliveries', [
            'webhook_endpoint_id' => $endpoint->id,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'event' => 'signal.created',
            'status' => 'pending',
        ]);

        $this->actingAs($this->platformAdmin())
            ->get(route('admin.platform.webhooks'))
            ->assertOk()
            ->assertSee('CRM endpoint')
            ->assertSee('signal.created');
    }

    public function test_platform_queue_dashboard_shows_pending_and_failed_jobs(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => 'failed-job-uuid',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Example failure',
            'failed_at' => now(),
        ]);

        $this->actingAs($this->platformAdmin())
            ->get(route('admin.platform.queues'))
            ->assertOk()
            ->assertSee('Pending')
            ->assertSee('Example failure');
    }

    public function test_ai_runtime_monitor_surfaces_cost_and_latency_summary(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        [$account, $brand] = $this->tenant();

        LlmRequest::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider' => 'openai',
            'model' => 'gpt-4.1-mini',
            'purpose' => 'visibility_check',
            'status' => 'completed',
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'total_tokens' => 150,
            'estimated_cost' => 0.0015,
            'credits_charged' => 2,
            'latency_ms' => 1200,
            'completed_at' => now(),
        ]);

        $this->actingAs($this->platformAdmin())
            ->get(route('admin.ai-runtime.monitor'))
            ->assertOk()
            ->assertSee('AI Runtime Monitor')
            ->assertSee('openai')
            ->assertSee('visibility_check')
            ->assertSee('150');
    }

    private function platformAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('name', 'platform_admin')->firstOrFail();
        $user->roles()->attach($role);

        return $user;
    }

    /**
     * @return array{Account, Brand}
     */
    private function tenant(): array
    {
        $account = Account::query()->create(['name' => 'Workspace', 'slug' => 'workspace-'.uniqid()]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Brand', 'slug' => 'brand-'.uniqid()]);

        return [$account, $brand];
    }
}
