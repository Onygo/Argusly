<?php

namespace Tests\Feature;

use App\Jobs\ProjectDomainEventJob;
use App\Models\Account;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\DomainEvent;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\Role;
use App\Models\User;
use App\Services\DomainEvents\ProjectorRegistry;
use App\Services\DomainEventService;
use App\Services\NotificationService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class NotificationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_events_create_in_app_notifications_for_tenant_users(): void
    {
        Queue::fake();

        [$user, $account, $brand] = $this->tenantUser('owner', 'notifications');
        [$otherUser] = $this->tenantUser('owner', 'notifications-other');
        $asset = ContentAsset::factory()->forBrand($brand)->create();
        $event = app(DomainEventService::class)->recordForSubject('ContentPublishingFailed', $asset, $user, [
            'error_message' => 'Connector timed out.',
        ]);

        (new ProjectDomainEventJob($event->id))->handle(app(ProjectorRegistry::class));

        $this->assertDatabaseHas('notification_events', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'type' => 'publishing_failed',
            'channel' => 'in_app',
            'title' => 'Publishing or provider workflow failed',
        ]);
        $this->assertSame(0, NotificationEvent::query()->where('user_id', $otherUser->id)->count());
    }

    public function test_user_preference_can_disable_in_app_notification_type(): void
    {
        Queue::fake();

        [$user, $account, $brand] = $this->tenantUser('owner', 'notifications-pref');
        app(NotificationService::class)->updatePreferences($user, $account, $brand, [
            'credits_low' => ['in_app' => false],
        ]);
        $asset = ContentAsset::factory()->forBrand($brand)->create();
        $event = app(DomainEventService::class)->recordForSubject('CreditsLow', $asset, $user, [
            'balance_after' => 50,
        ]);

        (new ProjectDomainEventJob($event->id))->handle(app(ProjectorRegistry::class));

        $this->assertSame(0, NotificationEvent::query()->where('user_id', $user->id)->where('type', 'credits_low')->count());
        $this->assertFalse(app(NotificationService::class)->preferenceMatrix($user, $account, $brand)['credits_low']['in_app']);
    }

    public function test_traffic_drop_domain_event_creates_traffic_notification(): void
    {
        Queue::fake();

        [$user, $account, $brand] = $this->tenantUser('owner', 'notifications-traffic');
        $asset = ContentAsset::factory()->forBrand($brand)->create();
        $event = app(DomainEventService::class)->recordForSubject('PerformanceInsightDetected', $asset, $user, [
            'performance_insight_type' => 'traffic_drop',
            'title' => 'Traffic dropped for key page',
        ]);

        (new ProjectDomainEventJob($event->id))->handle(app(ProjectorRegistry::class));

        $this->assertDatabaseHas('notification_events', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'type' => 'traffic_drop',
            'title' => 'Traffic drop detected',
        ]);
    }

    public function test_notifications_ui_shows_events_updates_preferences_and_marks_read(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner', 'notifications-ui');
        $notification = app(NotificationService::class)->notify(
            $account,
            $brand,
            'approval_requested',
            'Approval requested',
            'A workflow item is waiting.',
        )->first();

        $this->actingAs($user)
            ->get(route('app.notifications'))
            ->assertOk()
            ->assertSee('Notifications')
            ->assertSee('Approval requested')
            ->assertSee('Preferences');

        $this->actingAs($user)
            ->post(route('app.notifications.preferences'), [
                'preferences' => [
                    'approval_requested' => ['in_app' => '1'],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'type' => 'approval_requested',
            'channel' => 'in_app',
            'enabled' => true,
        ]);

        $this->actingAs($user)
            ->post(route('app.notifications.read', $notification))
            ->assertRedirect();

        $this->assertNotNull($notification->refresh()->read_at);
    }

    public function test_notification_models_reject_cross_tenant_domain_events(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner', 'notifications-safe');
        [, , $otherBrand] = $this->tenantUser('owner', 'notifications-foreign');
        $asset = ContentAsset::factory()->forBrand($otherBrand)->create();
        $event = app(DomainEventService::class)->recordForSubject('ContentAssetCreated', $asset, $user, [
            'title' => 'Foreign event',
        ]);

        $this->expectException(InvalidArgumentException::class);

        NotificationEvent::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'domain_event_id' => $event->id,
            'type' => 'publishing_failed',
            'channel' => 'in_app',
            'title' => 'Invalid',
            'body' => 'Invalid',
        ]);
    }

    private function tenantUser(string $roleName, string $slug): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => str($slug)->headline()->toString(), 'slug' => $slug.'-account']);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => str($slug)->headline().' Brand', 'slug' => $slug.'-brand']);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'scale_monthly');

        return [$user, $account, $brand];
    }
}
