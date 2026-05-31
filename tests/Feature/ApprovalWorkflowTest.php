<?php

namespace Tests\Feature;

use App\Jobs\PublishContentAssetJob;
use App\Models\Account;
use App\Models\Approval;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\GeneratedAsset;
use App\Models\Permission;
use App\Models\PublishingAction;
use App\Models\Recommendation;
use App\Models\Role;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\CreditService;
use App\Services\PublishingService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class ApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_can_request_and_manager_can_approve_generated_asset(): void
    {
        [$editor, $account, $brand] = $this->tenantWithRole('editor', 'approval-editor');
        [$manager] = $this->attachUserWithRole($account, $brand, 'manager');
        $asset = ContentAsset::factory()->forBrand($brand)->create();
        $generated = GeneratedAsset::factory()->forContentAsset($asset)->create(['status' => 'completed']);

        $approval = app(ApprovalService::class)->request($generated, $editor, 'Ready for review.');
        $approved = app(ApprovalService::class)->approve($approval, $manager, 'Approved.');

        $this->assertSame('approved', $approved->status);
        $this->assertSame('approved', $generated->refresh()->status);
        $this->assertSame($manager->id, $generated->approved_by);
        $this->assertDatabaseHas('domain_events', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'event_type' => 'ApprovalApproved',
            'subject_id' => $approval->id,
        ]);
    }

    public function test_viewer_cannot_approve_recommendation(): void
    {
        [$editor, $account, $brand] = $this->tenantWithRole('editor', 'approval-viewer');
        [$viewer] = $this->attachUserWithRole($account, $brand, 'viewer');
        $recommendation = Recommendation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Approval recommendation',
            'summary' => 'Needs approval.',
            'recommended_action' => 'Accept this recommendation.',
            'action_type' => 'refresh_content',
            'status' => 'new',
        ]);
        $approval = app(ApprovalService::class)->request($recommendation, $editor);

        $this->expectException(InvalidArgumentException::class);

        app(ApprovalService::class)->approve($approval, $viewer);
    }

    public function test_publish_requires_approval_when_bypass_permission_is_absent(): void
    {
        Queue::fake();

        [$publisher, $account, $brand] = $this->tenantWithRole('publisher', 'approval-publisher');
        $this->removeBypassFromRole('publisher');
        app(CreditService::class)->grant($account, 100, $publisher, 'Test credits');
        $asset = ContentAsset::factory()->forBrand($brand)->create(['status' => 'approved']);

        try {
            app(PublishingService::class)->request($asset, $publisher, ['action' => 'publish']);
            $this->fail('Expected publishing to require approval.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('requires approval', $exception->getMessage());
        }

        Approval::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'subject_type' => $asset->getMorphClass(),
            'subject_id' => $asset->id,
            'requested_by' => $publisher->id,
            'approved_by' => $publisher->id,
            'status' => 'approved',
            'requested_at' => now(),
            'decided_at' => now(),
        ]);

        $action = app(PublishingService::class)->request($asset, $publisher, ['action' => 'publish']);

        $this->assertSame('queued', $action->status);
        Queue::assertPushed(PublishContentAssetJob::class);
    }

    public function test_approval_subjects_are_tenant_safe(): void
    {
        [$editor, $account, $brand] = $this->tenantWithRole('editor', 'approval-tenant');
        [, , $otherBrand] = $this->tenantWithRole('editor', 'approval-other');
        $otherAsset = ContentAsset::factory()->forBrand($otherBrand)->create();
        $publishingAction = PublishingAction::factory()->forContentAsset($otherAsset)->create();

        $this->expectException(InvalidArgumentException::class);

        app(ApprovalService::class)->request($publishingAction, $editor);
    }

    private function tenantWithRole(string $roleName, string $slug): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $account = Account::query()->create(['name' => str($slug)->headline()->toString(), 'slug' => $slug.'-account']);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => str($slug)->headline().' Brand', 'slug' => $slug.'-brand']);

        app(SubscriptionService::class)->activatePlan($account, 'scale_monthly');

        return $this->attachUserWithRole($account, $brand, $roleName);
    }

    private function attachUserWithRole(Account $account, Brand $brand, string $roleName): array
    {
        $user = User::factory()->create();
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);

        return [$user, $account, $brand];
    }

    private function removeBypassFromRole(string $roleName): void
    {
        $permission = Permission::query()->where('name', 'bypass_approval')->firstOrFail();
        Role::query()->where('name', $roleName)->firstOrFail()->permissions()->detach($permission->id);
    }
}
