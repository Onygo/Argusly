<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Mention;
use App\Models\Role;
use App\Models\User;
use App\Services\InfluencerIntelligenceService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InfluencerIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_database_can_create_and_show_brand_scoped_creators(): void
    {
        [$user, $account, $brand] = $this->tenant();
        [, , $otherBrand] = $this->tenant('other-influencer-account');

        Contact::query()->create([
            'account_id' => $account->id,
            'first_name' => 'Hidden',
            'last_name' => 'Creator',
            'metadata' => [
                'creator_database' => true,
                'brand_id' => $otherBrand->id,
            ],
        ]);

        $this->actingAs($user)
            ->post(route('app.relationships.influencers.store'), [
                'first_name' => 'Ava',
                'last_name' => 'Stone',
                'display_name' => 'Ava Stone',
                'email' => 'ava@example.com',
                'category' => 'AI SaaS',
                'audience' => 'Marketing leaders',
                'channels' => 'linkedin, newsletter',
                'followers' => 120000,
                'engagement_rate' => 4.2,
                'avg_views' => 18000,
                'stage' => 'shortlisted',
                'next_action' => 'Send proposal',
            ])
            ->assertRedirect(route('app.relationships.influencers'));

        $creator = Contact::query()->where('display_name', 'Ava Stone')->firstOrFail();

        $this->assertTrue($creator->metadata['creator_database']);
        $this->assertSame($brand->id, $creator->metadata['brand_id']);
        $this->assertSame(['linkedin', 'newsletter'], $creator->metadata['channels']);
        $this->assertSame('shortlisted', $creator->metadata['crm']['stage']);

        $this->actingAs($user)
            ->get(route('app.relationships.influencers'))
            ->assertOk()
            ->assertSee('Influencer Intelligence')
            ->assertSee('Ava Stone')
            ->assertDontSee('Hidden Creator');
    }

    public function test_monitoring_calculates_performance_score_and_media_value(): void
    {
        [$user, $account, $brand] = $this->tenant();
        $creator = app(InfluencerIntelligenceService::class)->createCreator($account, $brand, $user, [
            'first_name' => 'Noah',
            'last_name' => 'Reed',
            'display_name' => 'Noah Reed',
            'followers' => 85000,
            'engagement_rate' => 5.5,
            'avg_views' => 14000,
            'channels' => 'youtube, linkedin',
        ]);

        Mention::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Noah Reed discussed Argusly',
            'content' => 'Creator coverage for Argusly.',
            'author' => 'Noah Reed',
            'sentiment' => 'positive',
            'impact_score' => 80,
            'published_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('app.relationships.influencers.monitor', $creator))
            ->assertRedirect();

        $creator->refresh();

        $this->assertTrue($creator->metadata['monitoring']['enabled']);
        $this->assertSame(1, $creator->metadata['monitoring']['mentions']);
        $this->assertGreaterThan(0, $creator->metadata['performance']['score']);
        $this->assertGreaterThan(0, $creator->metadata['performance']['media_value']);
        $this->assertDatabaseHas('domain_events', [
            'account_id' => $account->id,
            'brand_id' => null,
            'event_type' => 'InfluencerCreatorMonitored',
            'subject_id' => $creator->id,
        ]);
    }

    public function test_campaign_tracking_and_creator_crm_are_tenant_safe(): void
    {
        [$user, $account, $brand] = $this->tenant();
        [, $otherAccount, $otherBrand] = $this->tenant('other-campaign-influencer-account');
        $creator = app(InfluencerIntelligenceService::class)->createCreator($account, $brand, $user, [
            'first_name' => 'Mira',
            'last_name' => 'Cole',
            'display_name' => 'Mira Cole',
            'followers' => 32000,
            'engagement_rate' => 3.1,
        ]);
        $campaign = $this->campaign($account, $brand, 'Influencer launch');
        $otherCampaign = $this->campaign($otherAccount, $otherBrand, 'Hidden influencer campaign');

        $this->actingAs($user)
            ->post(route('app.relationships.influencers.campaigns.store', $creator), [
                'campaign_id' => $campaign->id,
            ])
            ->assertRedirect();

        $creator->refresh();

        $this->assertSame('active_campaign', $creator->metadata['crm']['stage']);
        $this->assertSame($campaign->id, $creator->metadata['campaigns'][0]['id']);

        $this->actingAs($user)
            ->post(route('app.relationships.influencers.crm', $creator), [
                'stage' => 'nurture',
                'next_action' => 'Invite to executive briefing',
                'owner_notes' => 'Strong fit for AI visibility narrative.',
            ])
            ->assertRedirect();

        $creator->refresh();

        $this->assertSame('nurture', $creator->metadata['crm']['stage']);
        $this->assertSame('Invite to executive briefing', $creator->metadata['crm']['next_action']);

        $this->actingAs($user)
            ->post(route('app.relationships.influencers.campaigns.store', $creator), [
                'campaign_id' => $otherCampaign->id,
            ])
            ->assertNotFound();
    }

    public function test_discovery_engine_ranks_creator_candidates(): void
    {
        [$user, $account, $brand] = $this->tenant();

        app(InfluencerIntelligenceService::class)->createCreator($account, $brand, $user, [
            'first_name' => 'Lina',
            'last_name' => 'Park',
            'display_name' => 'Lina Park',
            'followers' => 250000,
            'engagement_rate' => 6.5,
        ]);

        $dashboard = app(InfluencerIntelligenceService::class)->dashboard($account, $brand);

        $this->assertNotEmpty($dashboard['discovery']);
        $this->assertSame('Lina Park', $dashboard['discovery']->first()['creator']->display_name);
        $this->assertGreaterThanOrEqual(35, $dashboard['discovery']->first()['score']);
    }

    /**
     * @return array{User, Account, Brand}
     */
    private function tenant(string $slug = 'influencer-account'): array
    {
        $this->seed(LanguageSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => fake()->company(), 'slug' => fake()->unique()->slug().'-'.$slug]);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => 'Influencer Brand',
            'slug' => fake()->unique()->slug(),
            'default_content_language' => 'en',
            'enabled_content_languages' => ['en'],
        ]);
        $role = Role::query()->where('name', 'owner')->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'scale_monthly');

        return [$user, $account, $brand];
    }

    private function campaign(Account $account, Brand $brand, string $name): Campaign
    {
        return Campaign::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => $name,
            'objective' => 'Track creator performance and media value.',
            'status' => 'active',
            'metadata' => ['type' => 'influencer'],
        ]);
    }
}
