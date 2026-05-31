<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Approval;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\ContentAsset;
use App\Models\Integration;
use App\Models\IntegrationConnection;
use App\Models\MarketingObjective;
use App\Models\MarketingTask;
use App\Models\Recommendation;
use App\Models\Role;
use App\Models\SocialPost;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\MarketingTaskService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Tests\TestCase;

class MarketingTaskSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketing_task_can_link_supported_marketing_records(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $campaign = $this->campaign($account, $brand);
        $asset = $this->asset($account, $brand);
        $post = $this->socialPost($account, $brand, $user);
        $recommendation = $this->recommendation($account, $brand);
        $approval = $this->approval($account, $brand, $asset, $user);

        foreach ([
            ['content_asset', $asset->id, ContentAsset::class],
            ['social_post', $post->id, SocialPost::class],
            ['recommendation', $recommendation->id, Recommendation::class],
            ['approval', $approval->id, Approval::class],
            ['campaign', $campaign->id, Campaign::class],
        ] as [$type, $id, $class]) {
            $task = app(MarketingTaskService::class)->create($account, $brand, $user, [
                'scope' => 'brand',
                'related_type' => $type,
                'related_id' => $id,
                'title' => "Task for {$type}",
                'status' => 'todo',
                'priority' => 'medium',
            ]);

            $this->assertSame($class, $task->related_type);
            $this->assertSame($id, $task->related_id);
        }
    }

    public function test_due_tasks_appear_on_marketing_calendar(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');

        $this->actingAs($user)
            ->post(route('app.marketing.tasks.store'), [
                'scope' => 'brand',
                'title' => 'Review campaign brief',
                'status' => 'todo',
                'priority' => 'high',
                'due_at' => '2026-06-20 10:00:00',
            ])
            ->assertRedirect(route('app.marketing'));

        $task = MarketingTask::query()->where('title', 'Review campaign brief')->firstOrFail();

        $this->assertDatabaseHas('marketing_calendar_items', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'related_type' => $task->getMorphClass(),
            'related_id' => $task->id,
            'type' => 'marketing_task',
            'status' => 'planned',
            'assigned_to' => null,
        ]);

        app(MarketingTaskService::class)->create($account, $brand, $user, [
            'scope' => 'account',
            'title' => 'Account-wide planning task',
            'status' => 'todo',
            'priority' => 'medium',
            'due_at' => '2026-06-20 12:00:00',
        ]);

        $this->assertDatabaseHas('marketing_calendar_items', [
            'account_id' => $account->id,
            'brand_id' => null,
            'title' => 'Task: Account-wide planning task',
            'type' => 'marketing_task',
        ]);
    }

    public function test_task_can_be_created_from_recommendation(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $assignee = $this->accountUser($account, $brand, 'Editor');
        $recommendation = $this->recommendation($account, $brand);

        $this->actingAs($user)
            ->post(route('app.recommendations.task', $recommendation), [
                'assigned_to' => $assignee->id,
                'due_at' => '2026-06-21 12:00:00',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('marketing_tasks', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'related_type' => Recommendation::class,
            'related_id' => $recommendation->id,
            'title' => $recommendation->recommended_action,
            'priority' => 'high',
            'assigned_to' => $assignee->id,
            'created_by' => $user->id,
        ]);
    }

    public function test_assignee_must_have_account_and_brand_access(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $outsider = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        app(MarketingTaskService::class)->create($account, $brand, $user, [
            'scope' => 'brand',
            'title' => 'Unsafe assignment',
            'status' => 'todo',
            'priority' => 'medium',
            'assigned_to' => $outsider->id,
        ]);
    }

    public function test_marketing_tasks_are_tenant_safe_and_policy_protected(): void
    {
        [$owner, $account, $brand] = $this->tenantUser('owner');
        [$editor] = $this->tenantUser('editor', 'growth_monthly', 'editor-account');
        [, $otherAccount, $otherBrand] = $this->tenantUser('owner', 'growth_monthly', 'other-task-account');

        $visible = MarketingTask::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Visible task',
            'status' => 'todo',
            'priority' => 'medium',
        ]);
        MarketingTask::query()->create([
            'account_id' => $otherAccount->id,
            'brand_id' => $otherBrand->id,
            'title' => 'Hidden task',
            'status' => 'todo',
            'priority' => 'medium',
        ]);

        $this->actingAs($owner)
            ->get(route('app.marketing'))
            ->assertOk()
            ->assertSee($visible->title)
            ->assertDontSee('Hidden task');

        $this->assertTrue(Gate::forUser($owner)->allows('view', $visible));
        $this->assertFalse(Gate::forUser($editor)->allows('create', MarketingTask::class));
    }

    /**
     * @return array{User, Account, Brand}
     */
    private function tenantUser(string $roleName, ?string $plan = 'growth_monthly', string $slug = 'task-account'): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => str($slug)->headline(), 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => str($slug)->headline().' Brand',
            'slug' => fake()->unique()->slug(),
            'default_content_language' => 'en',
            'enabled_content_languages' => ['en'],
        ]);

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail(), ['account_id' => $account->id]);

        if ($plan !== null) {
            app(SubscriptionService::class)->activatePlan($account, $plan);
        }

        return [$user, $account, $brand];
    }

    private function accountUser(Account $account, Brand $brand, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);

        return $user;
    }

    private function campaign(Account $account, Brand $brand): Campaign
    {
        return Campaign::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Task Campaign',
            'slug' => fake()->unique()->slug(),
            'status' => 'active',
            'metadata' => ['campaign_type' => 'content'],
        ]);
    }

    private function asset(Account $account, Brand $brand): ContentAsset
    {
        return ContentAsset::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'type' => 'article',
            'status' => 'draft',
            'title' => 'Task asset',
            'slug' => fake()->unique()->slug(),
            'language' => 'en',
            'locale' => 'en_US',
            'source' => 'manual',
        ]);
    }

    private function socialPost(Account $account, Brand $brand, User $user): SocialPost
    {
        $integration = Integration::query()->firstOrCreate(
            ['key' => 'linkedin'],
            ['name' => 'LinkedIn', 'auth_type' => 'oauth', 'default_scopes' => [], 'is_active' => true, 'is_system' => true],
        );
        $connection = IntegrationConnection::query()->create([
            'integration_id' => $integration->id,
            'owner_user_id' => $user->id,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'LinkedIn',
            'status' => 'connected',
            'provider_account_id' => fake()->unique()->uuid(),
            'access_token' => 'token',
        ]);
        $profile = SocialProfile::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'integration_connection_id' => $connection->id,
            'owner_user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_profile_id' => fake()->unique()->uuid(),
            'display_name' => 'LinkedIn',
            'type' => 'person',
            'status' => 'connected',
        ]);

        return SocialPost::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'social_profile_id' => $profile->id,
            'provider' => 'linkedin',
            'status' => 'draft',
            'post_text' => 'Task social copy',
            'language' => 'en',
            'locale' => 'en_US',
            'created_by' => $user->id,
        ]);
    }

    private function recommendation(Account $account, Brand $brand): Recommendation
    {
        return Recommendation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Refresh decaying content',
            'summary' => 'A recommendation summary.',
            'recommended_action' => 'Refresh the decaying content asset',
            'impact_score' => 75,
            'confidence_score' => 80,
            'status' => 'new',
        ]);
    }

    private function approval(Account $account, Brand $brand, ContentAsset $asset, User $user): Approval
    {
        return Approval::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'subject_type' => $asset->getMorphClass(),
            'subject_id' => $asset->id,
            'requested_by' => $user->id,
            'status' => 'pending',
        ]);
    }
}
