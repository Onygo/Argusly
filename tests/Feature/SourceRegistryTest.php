<?php

namespace Tests\Feature;

use App\Jobs\RunSourceSyncJob;
use App\Models\Account;
use App\Models\Brand;
use App\Models\Integration;
use App\Models\IntegrationConnection;
use App\Models\Role;
use App\Models\Source;
use App\Models\User;
use App\Services\SourceRegistryService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\IntegrationCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SourceRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_sources_can_be_created_with_optional_integration_connection(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $connection = $this->connection($account, $brand, $user);

        $this->actingAs($user)
            ->post(route('app.sources.store'), [
                'name' => 'LinkedIn brand monitor',
                'type' => 'social',
                'provider' => 'linkedin',
                'status' => 'active',
                'scope' => 'brand',
                'integration_connection_id' => $connection->id,
            ])
            ->assertRedirect();

        $source = Source::query()->where('name', 'LinkedIn brand monitor')->firstOrFail();

        $this->assertSame($account->id, $source->account_id);
        $this->assertSame($brand->id, $source->brand_id);
        $this->assertDatabaseHas('source_connections', [
            'source_id' => $source->id,
            'integration_connection_id' => $connection->id,
            'status' => 'configured',
        ]);
    }

    public function test_source_registry_and_detail_are_tenant_safe(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $visible = Source::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Visible RSS',
            'type' => 'blog',
            'provider' => 'rss',
            'status' => 'active',
        ]);

        $otherBrand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => 'Other Brand',
            'slug' => 'other-brand',
        ]);
        $hidden = Source::query()->create([
            'account_id' => $account->id,
            'brand_id' => $otherBrand->id,
            'name' => 'Hidden RSS',
            'type' => 'blog',
            'provider' => 'rss',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('app.sources.index'))
            ->assertOk()
            ->assertSee('Source Registry')
            ->assertSee($visible->name)
            ->assertDontSee($hidden->name);

        $this->actingAs($user)
            ->get(route('app.sources.show', $hidden))
            ->assertForbidden();
    }

    public function test_planned_sync_records_create_history_without_running_sync(): void
    {
        Queue::fake();

        [$user, $account, $brand] = $this->tenantUser('owner');
        $source = Source::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Perplexity answers',
            'type' => 'ai',
            'provider' => 'perplexity',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->post(route('app.sources.syncs.plan', $source))
            ->assertRedirect(route('app.sources.show', $source));

        $this->assertDatabaseHas('source_syncs', [
            'source_id' => $source->id,
            'status' => 'planned',
            'records_found' => null,
        ]);
        Queue::assertPushed(RunSourceSyncJob::class, fn (RunSourceSyncJob $job) => $job->sourceSyncId === $source->syncs()->firstOrFail()->id);

        $this->actingAs($user)
            ->get(route('app.sources.syncs'))
            ->assertOk()
            ->assertSee('Sync history')
            ->assertSee('Perplexity answers')
            ->assertSee('Planned');
    }

    public function test_source_sync_job_completes_lifecycle_on_named_queue(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $source = Source::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'RSS Monitor',
            'type' => 'blog',
            'provider' => 'rss',
            'status' => 'active',
        ]);
        $sync = app(SourceRegistryService::class)->createPlannedSync($source);
        $job = new RunSourceSyncJob($sync->id);

        $job->handle(app(SourceRegistryService::class));

        $this->assertSame('completed', $sync->refresh()->status);
        $this->assertSame(0, $sync->records_found);
        $this->assertNotNull($sync->started_at);
        $this->assertNotNull($sync->completed_at);
        $this->assertDatabaseHas('domain_events', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'event_type' => 'SourceSyncCompleted',
            'subject_id' => $sync->id,
        ]);
    }

    public function test_service_rejects_cross_account_integration_connection(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $otherAccount = Account::query()->create(['name' => 'Other Account', 'slug' => 'other-account']);
        $connection = $this->connection($otherAccount, null, $user);

        $this->expectException(\InvalidArgumentException::class);

        app(SourceRegistryService::class)->create($account, $brand, [
            'name' => 'Invalid connection',
            'type' => 'social',
            'provider' => 'linkedin',
            'status' => 'active',
            'scope' => 'brand',
            'integration_connection_id' => $connection->id,
        ]);
    }

    public function test_source_connections_require_matching_provider_capability(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $credential = $this->connection($account, $brand, $user);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Integration connection provider must match the source provider.');

        app(SourceRegistryService::class)->create($account, $brand, [
            'name' => 'Google SERP monitor',
            'type' => 'search',
            'provider' => 'google',
            'status' => 'active',
            'scope' => 'brand',
            'integration_connection_id' => $credential->id,
        ]);
    }

    /**
     * @return array{User, Account, Brand}
     */
    private function tenantUser(string $roleName): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);
        $this->seed(IntegrationCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Source Account', 'slug' => 'source-account']);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => 'Source Brand',
            'slug' => 'source-brand',
        ]);

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail(), ['account_id' => $account->id]);

        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');

        return [$user, $account, $brand];
    }

    private function connection(Account $account, ?Brand $brand, User $owner): IntegrationConnection
    {
        return IntegrationConnection::query()->create([
            'integration_id' => Integration::query()->where('key', 'linkedin')->firstOrFail()->id,
            'owner_user_id' => $owner->id,
            'account_id' => $account->id,
            'brand_id' => $brand?->id,
            'name' => 'LinkedIn OAuth',
            'status' => 'active',
        ]);
    }
}
