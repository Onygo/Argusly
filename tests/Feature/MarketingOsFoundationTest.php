<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Approval;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\MarketingCalendarItem;
use App\Models\MarketingObjective;
use App\Models\MarketingTask;
use App\Models\MarketingWorkspace;
use App\Models\Recommendation;
use App\Models\Role;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class MarketingOsFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketing_workspaces_and_objectives_can_be_created(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner', 'starter_monthly');
        $campaign = Campaign::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Visibility Campaign',
            'slug' => 'visibility-campaign',
            'status' => 'active',
            'metadata' => ['campaign_type' => 'content'],
        ]);

        $this->actingAs($user)
            ->post(route('app.marketing.workspaces.store'), [
                'scope' => 'account',
                'name' => 'Growth planning room',
                'description' => 'Account-wide planning.',
                'status' => 'active',
            ])
            ->assertRedirect(route('app.marketing'));

        $this->actingAs($user)
            ->post(route('app.marketing.objectives.store'), [
                'scope' => 'brand',
                'campaign_id' => $campaign->id,
                'name' => 'Increase AI visibility',
                'description' => 'Raise presence across answer engines.',
                'type' => 'visibility',
                'status' => 'active',
                'target_value' => 80,
                'current_value' => 25,
                'unit' => 'score',
                'start_date' => '2026-06-01',
                'end_date' => '2026-09-30',
            ])
            ->assertRedirect(route('app.marketing'));

        $this->assertDatabaseHas('marketing_workspaces', [
            'account_id' => $account->id,
            'brand_id' => null,
            'name' => 'Growth planning room',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('marketing_objectives', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'campaign_id' => $campaign->id,
            'name' => 'Increase AI visibility',
            'type' => 'visibility',
            'unit' => 'score',
        ]);
    }

    public function test_marketing_os_ui_is_tenant_and_brand_aware(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner', 'growth_monthly');
        $otherBrand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => 'Other Brand',
            'slug' => 'other-brand',
        ]);
        [, $otherAccount, $otherAccountBrand] = $this->tenantUser('owner', 'growth_monthly', 'other-account');

        MarketingWorkspace::query()->create([
            'account_id' => $account->id,
            'brand_id' => null,
            'name' => 'Account operating rhythm',
            'status' => 'active',
        ]);
        MarketingObjective::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Visible objective',
            'type' => 'traffic',
            'status' => 'active',
        ]);
        MarketingObjective::query()->create([
            'account_id' => $account->id,
            'brand_id' => $otherBrand->id,
            'name' => 'Other brand objective',
            'type' => 'traffic',
            'status' => 'active',
        ]);
        MarketingObjective::query()->create([
            'account_id' => $otherAccount->id,
            'brand_id' => $otherAccountBrand->id,
            'name' => 'Other account objective',
            'type' => 'traffic',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('app.marketing'))
            ->assertOk()
            ->assertSee('Marketing dashboard')
            ->assertSee('Visible objective')
            ->assertDontSee('Other brand objective')
            ->assertDontSee('Other account objective');
    }

    public function test_marketing_dashboard_surfaces_operating_work_for_the_selected_scope(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner', 'growth_monthly', 'dashboard-account');
        $campaign = Campaign::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Q3 Launch Campaign',
            'slug' => 'q3-launch-campaign',
            'status' => 'active',
            'start_date' => now()->toDateString(),
            'metadata' => ['campaign_type' => 'content'],
        ]);

        MarketingTask::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'campaign_id' => $campaign->id,
            'title' => 'Draft launch landing page',
            'status' => 'todo',
            'priority' => 'high',
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'due_at' => now()->addDays(2),
        ]);
        MarketingTask::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'campaign_id' => $campaign->id,
            'title' => 'Review delayed launch assets',
            'status' => 'in_progress',
            'priority' => 'urgent',
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'due_at' => now()->subDay(),
        ]);
        MarketingCalendarItem::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'campaign_id' => $campaign->id,
            'title' => 'Launch milestone review',
            'type' => 'campaign_task',
            'status' => 'planned',
            'start_at' => now()->addDays(3),
            'assigned_to' => $user->id,
        ]);
        MarketingObjective::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'campaign_id' => $campaign->id,
            'name' => 'Reach 10k launch visitors',
            'type' => 'traffic',
            'status' => 'active',
            'target_value' => 10000,
            'current_value' => 2500,
            'unit' => 'visitors',
        ]);
        Approval::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'subject_type' => Campaign::class,
            'subject_id' => $campaign->id,
            'requested_by' => $user->id,
            'status' => 'pending',
            'notes' => 'Approve the launch direction.',
        ]);
        Recommendation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Create launch task plan',
            'summary' => 'The active campaign needs a clearer execution plan.',
            'recommended_action' => 'Create a marketing task plan.',
            'action_type' => 'create_campaign_task_plan',
            'status' => 'new',
            'impact_score' => 88,
            'confidence_score' => 92,
        ]);

        $this->actingAs($user)
            ->get(route('app.marketing', ['brand_id' => $brand->id, 'campaign_id' => $campaign->id]))
            ->assertOk()
            ->assertSee('Marketing dashboard')
            ->assertSee('Q3 Launch Campaign')
            ->assertSee('Draft launch landing page')
            ->assertSee('Review delayed launch assets')
            ->assertSee('Launch milestone review')
            ->assertSee('Reach 10k launch visitors')
            ->assertSee('Campaign approval')
            ->assertSee('Create launch task plan')
            ->assertSee('Content Agent');
    }

    public function test_marketing_models_reject_cross_tenant_brand_and_campaign_links(): void
    {
        [, $account, $brand] = $this->tenantUser('owner', 'growth_monthly');
        [, $otherAccount, $otherBrand] = $this->tenantUser('owner', 'growth_monthly', 'cross-account');
        $otherCampaign = Campaign::query()->create([
            'account_id' => $otherAccount->id,
            'brand_id' => $otherBrand->id,
            'name' => 'Other Campaign',
            'slug' => 'other-campaign',
            'status' => 'active',
            'metadata' => ['campaign_type' => 'content'],
        ]);

        $this->expectException(InvalidArgumentException::class);

        MarketingObjective::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'campaign_id' => $otherCampaign->id,
            'name' => 'Unsafe objective',
            'type' => 'campaign_performance',
            'status' => 'active',
        ]);
    }

    public function test_marketing_os_is_gated_by_campaigns_or_marketing_os_module(): void
    {
        [$starterUser] = $this->tenantUser('owner', 'starter_monthly', 'starter-account');
        [$unsubscribedUser] = $this->tenantUser('owner', null, 'unsubscribed-account');
        $filteredAccount = Account::query()->create(['name' => 'Filtered Account', 'slug' => fake()->unique()->slug()]);
        $filteredBrand = Brand::query()->create([
            'account_id' => $filteredAccount->id,
            'name' => 'Filtered Brand',
            'slug' => fake()->unique()->slug(),
        ]);

        $starterUser->accounts()->attach($filteredAccount, ['status' => 'active']);
        $starterUser->brands()->attach($filteredBrand, ['account_id' => $filteredAccount->id, 'status' => 'active']);
        $starterUser->roles()->attach(Role::query()->where('name', 'owner')->firstOrFail(), ['account_id' => $filteredAccount->id]);

        $this->actingAs($starterUser)
            ->get(route('app.marketing'))
            ->assertOk()
            ->assertSee('Marketing dashboard');

        $this->actingAs($starterUser)
            ->get(route('app.marketing', ['account_id' => $filteredAccount->id]))
            ->assertForbidden();

        $this->actingAs($unsubscribedUser)
            ->get(route('app.marketing'))
            ->assertForbidden();
    }

    /**
     * @return array{User, Account, Brand}
     */
    private function tenantUser(string $roleName, ?string $plan = 'growth_monthly', string $slug = 'marketing-account'): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => str($slug)->headline(), 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => str($slug)->headline().' Brand',
            'slug' => fake()->unique()->slug(),
        ]);

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail(), ['account_id' => $account->id]);

        if ($plan !== null) {
            app(SubscriptionService::class)->activatePlan($account, $plan);
        }

        return [$user, $account, $brand];
    }
}
