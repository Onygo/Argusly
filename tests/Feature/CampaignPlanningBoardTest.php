<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\CampaignStage;
use App\Models\ContentAsset;
use App\Models\MarketingTask;
use App\Models\Recommendation;
use App\Models\Role;
use App\Models\User;
use App\Services\CampaignBoardService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CampaignPlanningBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_board_creates_default_stages_and_renders(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $campaign = $this->campaign($account, $brand);

        $this->actingAs($user)
            ->get(route('app.campaigns.board', $campaign))
            ->assertOk()
            ->assertSee('Campaign planning board')
            ->assertSee('Ideas')
            ->assertSee('In production')
            ->assertSee('Done');

        $this->assertSame(CampaignStage::DEFAULT_STAGES, CampaignStage::query()->where('campaign_id', $campaign->id)->orderBy('position')->pluck('name')->all());
    }

    public function test_board_items_can_link_content_tasks_and_recommendations(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $campaign = $this->campaign($account, $brand);
        $stage = app(CampaignBoardService::class)->stages($campaign)->first();
        $asset = $this->asset($account, $brand);
        $task = MarketingTask::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'campaign_id' => $campaign->id,
            'title' => 'Campaign task',
            'status' => 'todo',
            'priority' => 'medium',
        ]);
        $recommendation = Recommendation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Campaign recommendation',
            'summary' => 'Summary',
            'recommended_action' => 'Do the thing',
            'impact_score' => 50,
            'confidence_score' => 70,
            'status' => 'new',
        ]);

        foreach ([
            ['content_asset', $asset->id, ContentAsset::class],
            ['marketing_task', $task->id, MarketingTask::class],
            ['recommendation', $recommendation->id, Recommendation::class],
        ] as [$type, $id, $class]) {
            $this->actingAs($user)
                ->post(route('app.campaigns.board.items.store', $campaign), [
                    'campaign_stage_id' => $stage->id,
                    'related_type' => $type,
                    'related_id' => $id,
                    'title' => "Board item {$type}",
                    'status' => 'active',
                ])
                ->assertRedirect(route('app.campaigns.board', $campaign));

            $this->assertDatabaseHas('campaign_items', [
                'campaign_id' => $campaign->id,
                'campaign_stage_id' => $stage->id,
                'related_type' => $class,
                'related_id' => $id,
            ]);
        }
    }

    public function test_board_item_can_move_between_stages_with_position_buttons(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $campaign = $this->campaign($account, $brand);
        $stages = app(CampaignBoardService::class)->stages($campaign);
        $item = CampaignItem::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'campaign_id' => $campaign->id,
            'campaign_stage_id' => $stages[0]->id,
            'title' => 'Move me',
            'status' => 'active',
            'position' => 1,
        ]);

        $this->actingAs($user)
            ->patch(route('app.campaigns.board.items.move', [$campaign, $item]), ['direction' => 'right'])
            ->assertRedirect(route('app.campaigns.board', $campaign));

        $this->assertSame($stages[1]->id, $item->refresh()->campaign_stage_id);
    }

    public function test_campaign_board_is_tenant_safe(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        [, $otherAccount, $otherBrand] = $this->tenantUser('owner', 'other-board-account');
        $otherCampaign = $this->campaign($otherAccount, $otherBrand, 'Hidden campaign');

        $this->actingAs($user)
            ->get(route('app.campaigns.board', $otherCampaign))
            ->assertForbidden();

        $this->expectException(InvalidArgumentException::class);

        CampaignItem::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'campaign_id' => $otherCampaign->id,
            'title' => 'Unsafe item',
            'status' => 'active',
        ]);
    }

    /**
     * @return array{User, Account, Brand}
     */
    private function tenantUser(string $roleName, string $slug = 'board-account'): array
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
        app(SubscriptionService::class)->activatePlan($account, 'growth_monthly');

        return [$user, $account, $brand];
    }

    private function campaign(Account $account, Brand $brand, string $name = 'Board campaign'): Campaign
    {
        return Campaign::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => $name,
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
            'title' => 'Board asset',
            'slug' => fake()->unique()->slug(),
            'language' => 'en',
            'locale' => 'en_US',
            'source' => 'manual',
        ]);
    }
}
