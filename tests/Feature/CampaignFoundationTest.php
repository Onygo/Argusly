<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\ContentAsset;
use App\Models\IntelligenceSignal;
use App\Models\Role;
use App\Models\Topic;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaigns_can_be_created_with_assets_topics_and_signals(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $asset = $this->asset($account, $brand, 'Campaign landing page');
        $topic = $this->topic($account, $brand, 'Agentic Marketing');
        $signal = $this->signal($account, $brand, 'Launch opportunity detected');

        $this->actingAs($user)
            ->post(route('app.campaigns.store'), [
                'name' => 'Q3 Awareness Campaign',
                'description' => 'A campaign for awareness.',
                'objective' => 'Increase visibility for agentic marketing.',
                'status' => 'planned',
                'campaign_type' => 'content',
                'start_date' => '2026-07-01',
                'end_date' => '2026-09-30',
                'content_asset_ids' => [$asset->id],
                'topic_ids' => [$topic->id],
                'signal_ids' => [$signal->id],
            ])
            ->assertRedirect();

        $campaign = Campaign::query()->where('name', 'Q3 Awareness Campaign')->firstOrFail();

        $this->assertSame($account->id, $campaign->account_id);
        $this->assertSame($brand->id, $campaign->brand_id);
        $this->assertSame('planned', $campaign->status);
        $this->assertSame('content', $campaign->metadata['campaign_type']);
        $this->assertDatabaseHas('campaign_assets', ['campaign_id' => $campaign->id, 'content_asset_id' => $asset->id]);
        $this->assertDatabaseHas('campaign_topics', ['campaign_id' => $campaign->id, 'topic_id' => $topic->id]);
        $this->assertDatabaseHas('campaign_signals', ['campaign_id' => $campaign->id, 'intelligence_signal_id' => $signal->id]);
    }

    public function test_campaign_dashboard_and_detail_are_tenant_safe(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $visible = Campaign::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Visible Campaign',
            'slug' => 'visible-campaign',
            'status' => 'active',
            'metadata' => ['campaign_type' => 'pr'],
        ]);

        $otherBrand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => 'Other Brand',
            'slug' => 'other-brand',
        ]);

        $hidden = Campaign::query()->create([
            'account_id' => $account->id,
            'brand_id' => $otherBrand->id,
            'name' => 'Hidden Campaign',
            'slug' => 'hidden-campaign',
            'status' => 'active',
            'metadata' => ['campaign_type' => 'social'],
        ]);

        $this->actingAs($user)
            ->get(route('app.campaigns'))
            ->assertOk()
            ->assertSee('Campaign Dashboard')
            ->assertSee($visible->name)
            ->assertDontSee($hidden->name);

        $this->actingAs($user)
            ->get(route('app.campaigns.show', $hidden))
            ->assertForbidden();
    }

    public function test_campaign_detail_shows_timeline_and_future_architecture_lanes(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $asset = $this->asset($account, $brand, 'Published campaign story');
        $signal = $this->signal($account, $brand, 'Audience demand increased');

        $campaign = Campaign::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'PR Launch',
            'slug' => 'pr-launch',
            'status' => 'active',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'metadata' => ['campaign_type' => 'pr'],
        ]);

        $campaign->contentAssets()->attach($asset);
        $campaign->signals()->attach($signal);

        $this->actingAs($user)
            ->get(route('app.campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('Campaign Timeline')
            ->assertSee('Campaign starts')
            ->assertSee('Published campaign story')
            ->assertSee('Audience demand increased');

        $this->actingAs($user)
            ->get(route('app.campaigns'))
            ->assertOk()
            ->assertSee('Social Campaigns')
            ->assertSee('Influencer Campaigns')
            ->assertSee('Content Campaigns')
            ->assertSee('PR Campaigns');
    }

    /**
     * @return array{User, Account, Brand}
     */
    private function tenantUser(string $roleName): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Growth Account', 'slug' => 'growth-account']);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => 'Growth Brand',
            'slug' => 'growth-brand',
        ]);

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail(), ['account_id' => $account->id]);

        app(SubscriptionService::class)->activatePlan($account, 'growth_monthly');

        return [$user, $account, $brand];
    }

    private function asset(Account $account, Brand $brand, string $title): ContentAsset
    {
        return ContentAsset::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'type' => 'article',
            'status' => 'published',
            'title' => $title,
            'slug' => str($title)->slug(),
            'language' => 'en',
            'locale' => 'en_US',
            'source' => 'manual',
            'published_at' => now(),
        ]);
    }

    private function topic(Account $account, Brand $brand, string $name): Topic
    {
        return Topic::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => $name,
            'slug' => str($name)->slug(),
            'status' => 'active',
        ]);
    }

    private function signal(Account $account, Brand $brand, string $title): IntelligenceSignal
    {
        return IntelligenceSignal::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'source' => 'campaign_foundation_test',
            'type' => 'content_opportunity',
            'category' => 'content',
            'priority' => 'high',
            'title' => $title,
            'summary' => 'A campaign-relevant signal.',
            'impact_score' => 80,
            'confidence_score' => 70,
            'status' => 'new',
            'detected_at' => now(),
        ]);
    }
}
