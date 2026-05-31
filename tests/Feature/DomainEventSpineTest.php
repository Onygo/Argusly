<?php

namespace Tests\Feature;

use App\Contracts\DomainEventProjector;
use App\Jobs\ProjectDomainEventJob;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\DomainEvent;
use App\Models\DomainEventProjectorRun;
use App\Models\IntelligenceSignal;
use App\Models\Recommendation;
use App\Models\Role;
use App\Models\Source;
use App\Models\User;
use App\Services\ContentAssetService;
use App\Services\DomainEvents\ActivityLogProjector;
use App\Services\DomainEvents\ProjectorRegistry;
use App\Services\DomainEventService;
use App\Services\MentionIntelligenceService;
use App\Services\SourceRegistryService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class DomainEventSpineTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_creation_and_mention_capture_emit_domain_events(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');

        $asset = app(ContentAssetService::class)->create($account, $brand, [
            'type' => 'article',
            'title' => 'Domain event article',
            'body' => 'Durable product fact.',
        ], $user);

        app(MentionIntelligenceService::class)->create($account, $brand, [
            'title' => 'Captured mention',
            'content' => 'The brand was mentioned.',
            'sentiment' => 'positive',
        ]);

        $this->assertDatabaseHas('domain_events', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'event_type' => 'ContentAssetCreated',
            'subject_type' => $asset->getMorphClass(),
            'subject_id' => $asset->id,
            'actor_user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('domain_events', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'event_type' => 'MentionCaptured',
        ]);
    }

    public function test_source_sync_completion_emits_domain_event(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $source = Source::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'RSS stream',
            'type' => 'blog',
            'provider' => 'rss',
            'status' => 'active',
        ]);
        $sync = app(SourceRegistryService::class)->createPlannedSync($source);

        app(SourceRegistryService::class)->completeSync($sync, 12);

        $this->assertDatabaseHas('domain_events', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'event_type' => 'SourceSyncCompleted',
            'subject_type' => $sync->getMorphClass(),
            'subject_id' => $sync->id,
        ]);
        $this->actingAs($user)
            ->get(route('app.domain-events', ['event_type' => 'SourceSyncCompleted']))
            ->assertOk()
            ->assertSee('Source Sync Completed')
            ->assertSee('12');
    }

    public function test_domain_event_admin_page_is_tenant_safe(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        [$otherUser, $otherAccount, $otherBrand] = $this->tenantUser('owner', 'Other Account', 'other-account');

        $visible = ContentAsset::factory()->forBrand($brand)->create(['title' => 'Visible event asset']);
        $hidden = ContentAsset::factory()->forBrand($otherBrand)->create(['title' => 'Hidden event asset']);

        app(DomainEventService::class)->recordForSubject('ContentAssetCreated', $visible, $user, ['title' => $visible->title]);
        app(DomainEventService::class)->recordForSubject('ContentAssetCreated', $hidden, $otherUser, ['title' => $hidden->title]);

        $this->actingAs($user)
            ->get(route('app.domain-events'))
            ->assertOk()
            ->assertSee('Domain events')
            ->assertSee('Visible event asset')
            ->assertDontSee('Hidden event asset');

        $this->assertSame(2, DomainEvent::query()->count());
        $this->assertTrue(DomainEvent::query()->where('account_id', $otherAccount->id)->exists());
    }

    public function test_domain_event_service_rejects_cross_account_subjects(): void
    {
        [$user, $account] = $this->tenantUser('owner');
        [, , $otherBrand] = $this->tenantUser('owner', 'Other Account', 'other-account');
        $hidden = ContentAsset::factory()->forBrand($otherBrand)->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Domain event subject must belong to the same account.');

        app(DomainEventService::class)->record('ContentAssetCreated', $account, null, $hidden, $user);
    }

    public function test_project_domain_event_job_projects_once_and_marks_event_processed(): void
    {
        Queue::fake();

        [$user, $account, $brand] = $this->tenantUser('owner');
        $asset = ContentAsset::factory()->forBrand($brand)->create(['title' => 'Published spine asset']);
        $event = app(DomainEventService::class)->recordForSubject('ContentAssetPublished', $asset, $user, [
            'content_asset_id' => $asset->id,
            'title' => $asset->title,
        ]);

        (new ProjectDomainEventJob($event->id))->handle(app(ProjectorRegistry::class));
        (new ProjectDomainEventJob($event->id))->handle(app(ProjectorRegistry::class));

        $event->refresh();

        $this->assertNotNull($event->processed_at);
        $this->assertSame(3, DomainEventProjectorRun::query()->where('event_uuid', $event->uuid)->where('status', 'completed')->count());
        $this->assertSame(1, ActivityLog::query()->where('properties->domain_event_uuid', $event->uuid)->count());
        $this->assertSame(1, IntelligenceSignal::query()->where('dedupe_key', "domain-event:{$event->uuid}:signal")->count());
        $this->assertSame(1, Recommendation::query()->whereHas('signal', fn ($query) => $query->where('dedupe_key', "domain-event:{$event->uuid}:signal"))->count());
    }

    public function test_projector_failures_are_logged_and_do_not_mark_event_processed(): void
    {
        Queue::fake();

        [$user, , $brand] = $this->tenantUser('owner');
        $asset = ContentAsset::factory()->forBrand($brand)->create(['title' => 'Failure event asset']);
        $event = app(DomainEventService::class)->recordForSubject('ContentAssetCreated', $asset, $user, [
            'title' => $asset->title,
        ]);

        $projector = new class implements DomainEventProjector
        {
            public function project(DomainEvent $event): void
            {
                throw new RuntimeException('Projection exploded.');
            }
        };

        $this->expectException(RuntimeException::class);

        try {
            (new ProjectorRegistry([
                app(ActivityLogProjector::class),
                $projector,
            ]))->project($event);
        } finally {
            $event->refresh();

            $this->assertNull($event->processed_at);
            $this->assertDatabaseHas('domain_event_projector_runs', [
                'event_uuid' => $event->uuid,
                'projector' => $projector::class,
                'status' => 'failed',
                'error' => 'Projection exploded.',
            ]);
            $this->assertDatabaseHas('domain_event_projector_runs', [
                'event_uuid' => $event->uuid,
                'projector' => ActivityLogProjector::class,
                'status' => 'completed',
            ]);
        }
    }

    /**
     * @return array{User, Account, Brand}
     */
    private function tenantUser(string $roleName, string $accountName = 'Domain Account', string $accountSlug = 'domain-account'): array
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

        return [$user, $account, $brand];
    }
}
