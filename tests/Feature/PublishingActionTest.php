<?php

namespace Tests\Feature;

use App\Jobs\PublishContentAssetJob;
use App\Models\Account;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\IntelligenceSignal;
use App\Models\PublishingAction;
use App\Models\PublishingChannel;
use App\Models\Role;
use App\Models\User;
use App\Services\CreditService;
use App\Services\PublishingService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PublishingActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_route_creates_queued_action_and_dispatches_job(): void
    {
        Queue::fake();

        [$publisher, , $brand] = $this->tenantWithRole('publisher');
        $asset = ContentAsset::factory()->forBrand($brand)->create(['status' => 'approved']);

        $this->actingAs($publisher)
            ->post(route('app.content.publish', $asset))
            ->assertRedirect(route('app.content.show', $asset));

        $action = PublishingAction::query()->where('content_asset_id', $asset->id)->firstOrFail();

        $this->assertSame('publish', $action->action);
        $this->assertSame('queued', $action->status);
        $this->assertSame($publisher->id, $action->created_by);

        Queue::assertPushed(
            PublishContentAssetJob::class,
            fn (PublishContentAssetJob $job) => $job->publishingActionId === $action->id,
        );
    }

    public function test_publish_job_completes_action_updates_content_and_creates_signal_and_activity(): void
    {
        Queue::fake();

        [$publisher, , $brand] = $this->tenantWithRole('publisher');
        $asset = ContentAsset::factory()->forBrand($brand)->create([
            'title' => 'Publishing foundation article',
            'status' => 'approved',
            'canonical_url' => 'https://example.com/articles/publishing-foundation',
        ]);
        $action = app(PublishingService::class)->request($asset, $publisher, ['action' => 'publish']);

        (new PublishContentAssetJob($action->id))->handle(app(PublishingService::class));

        $action->refresh();
        $asset->refresh();

        $this->assertSame('completed', $action->status);
        $this->assertNotNull($action->published_at);
        $this->assertStringStartsWith('fake-publish-', $action->external_id);
        $this->assertStringContainsString('/fake-published/fake-publish-', $action->external_url);
        $this->assertTrue($action->response_payload['fake']);
        $this->assertSame('published', $asset->status);
        $this->assertNotNull($asset->published_at);
        $this->assertNotNull($asset->first_published_at);

        $this->assertDatabaseHas('activity_logs', [
            'event' => 'content.publishing.completed',
            'subject_type' => (new PublishingAction)->getMorphClass(),
            'subject_id' => $action->id,
        ]);

        $signal = IntelligenceSignal::query()->where('source', 'content_publishing')->firstOrFail();
        $this->assertSame('integration_event', $signal->type);
        $this->assertSame($action->id, $signal->payload['publishing_action_id']);
    }

    public function test_update_unpublish_and_schedule_actions_update_content_when_applicable(): void
    {
        Queue::fake();

        [$publisher, , $brand] = $this->tenantWithRole('publisher');
        $asset = ContentAsset::factory()->forBrand($brand)->create(['status' => 'published', 'published_at' => now()->subDay()]);

        $update = app(PublishingService::class)->request($asset, $publisher, ['action' => 'update']);
        (new PublishContentAssetJob($update->id))->handle(app(PublishingService::class));
        $this->assertSame('published', $asset->refresh()->status);

        $schedule = app(PublishingService::class)->request($asset, $publisher, ['action' => 'schedule', 'scheduled_at' => now()->addDay()->toDateTimeString()]);
        (new PublishContentAssetJob($schedule->id))->handle(app(PublishingService::class));
        $this->assertSame('scheduled', $asset->refresh()->status);

        $unpublish = app(PublishingService::class)->request($asset, $publisher, ['action' => 'unpublish']);
        (new PublishContentAssetJob($unpublish->id))->handle(app(PublishingService::class));
        $this->assertSame('archived', $asset->refresh()->status);
    }

    public function test_editor_cannot_publish_and_content_module_is_required(): void
    {
        [$editor, , $brand] = $this->tenantWithRole('editor');
        $asset = ContentAsset::factory()->forBrand($brand)->create(['status' => 'approved']);

        $this->actingAs($editor)
            ->post(route('app.content.publish', $asset))
            ->assertForbidden();

        [$publisherNoContent, , $noContentBrand] = $this->tenantWithRole('publisher', activatePlan: false, slug: 'no-content');
        $blockedAsset = ContentAsset::factory()->forBrand($noContentBrand)->create(['status' => 'approved']);

        $this->actingAs($publisherNoContent)
            ->post(route('app.content.publish', $blockedAsset))
            ->assertForbidden();
    }

    public function test_publishing_channel_must_belong_to_same_brand(): void
    {
        [$publisher, $account, $brand] = $this->tenantWithRole('publisher');
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $asset = ContentAsset::factory()->forBrand($brand)->create(['status' => 'approved']);
        $otherChannel = PublishingChannel::factory()->forBrand($otherBrand)->create(['provider' => 'wordpress']);

        $this->actingAs($publisher)
            ->post(route('app.content.publishing-actions.store', $asset), [
                'action' => 'publish',
                'publishing_channel_id' => $otherChannel->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('publishing_actions', [
            'content_asset_id' => $asset->id,
            'publishing_channel_id' => $otherChannel->id,
        ]);
    }

    public function test_publishing_history_is_tenant_and_brand_scoped(): void
    {
        [$publisher, $account, $brand] = $this->tenantWithRole('publisher');
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $publisher->brands()->attach($otherBrand, ['account_id' => $account->id, 'status' => 'active']);

        $visibleAsset = ContentAsset::factory()->forBrand($brand)->create(['title' => 'Visible publish asset']);
        $hiddenAsset = ContentAsset::factory()->forBrand($otherBrand)->create(['title' => 'Hidden publish asset']);

        PublishingAction::factory()->forContentAsset($visibleAsset)->create([
            'action' => 'publish',
            'status' => 'completed',
            'external_url' => 'https://example.com/visible',
        ]);
        PublishingAction::factory()->forContentAsset($hiddenAsset)->create([
            'action' => 'publish',
            'status' => 'completed',
            'external_url' => 'https://example.com/hidden',
        ]);

        $this->actingAs($publisher)
            ->get(route('app.content.show', $visibleAsset))
            ->assertOk()
            ->assertSee('https://example.com/visible')
            ->assertDontSee('https://example.com/hidden');
    }

    /**
     * @return array{0: User, 1: Account, 2: Brand}
     */
    private function tenantWithRole(string $roleName, bool $activatePlan = true, string $slug = 'alpha-account'): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => str($slug)->replace('-', ' ')->headline(), 'slug' => $slug]);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => str($slug)->headline().' Brand', 'slug' => $slug.'-brand']);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);

        if ($activatePlan) {
            app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');
            app(CreditService::class)->grant($account, 1000, $user, 'Test credits');
        }

        return [$user, $account, $brand];
    }
}
