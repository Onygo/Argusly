<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Entity;
use App\Models\IntelligenceSignal;
use App\Models\Mention;
use App\Models\Role;
use App\Models\Source;
use App\Models\Topic;
use App\Models\User;
use App\Services\MentionIntelligenceService;
use App\Services\IntelligenceSignalService;
use App\Services\Signals\SignalManager;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\IntelligenceSignalSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class IntelligenceSignalTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_creates_valid_tenant_scoped_signal(): void
    {
        $account = Account::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Alpha Brand', 'slug' => 'alpha-brand']);

        $signal = app(IntelligenceSignalService::class)->create($account, [
            'source' => 'test',
            'type' => 'visibility_change',
            'title' => 'Visibility changed',
            'summary' => 'The brand moved in tracked prompts.',
            'status' => 'new',
            'impact_score' => 80,
            'confidence_score' => 90,
            'detected_at' => now(),
        ], $brand);

        $this->assertNotNull($signal->uuid);
        $this->assertSame($account->id, $signal->account_id);
        $this->assertSame($brand->id, $signal->brand_id);
    }

    public function test_service_rejects_invalid_type_status_and_cross_account_brand(): void
    {
        $account = Account::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $otherAccount = Account::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $otherBrand = Brand::query()->create(['account_id' => $otherAccount->id, 'name' => 'Beta Brand', 'slug' => 'beta-brand']);
        $service = app(IntelligenceSignalService::class);

        $this->expectException(InvalidArgumentException::class);

        $service->create($account, [
            'source' => 'test',
            'type' => 'not_real',
            'title' => 'Invalid',
            'summary' => 'Invalid',
            'status' => 'new',
        ], $otherBrand);
    }

    public function test_intelligence_index_is_tenant_brand_scoped_and_filterable(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Alpha Brand', 'slug' => 'alpha-brand']);
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $otherAccount = Account::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $role = Role::query()->where('name', 'owner')->firstOrFail();
        $service = app(IntelligenceSignalService::class);

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');

        $service->create($account, [
            'source' => 'test',
            'type' => 'visibility_change',
            'title' => 'Visible account signal',
            'summary' => 'Account level signal.',
            'status' => 'new',
        ]);
        $service->create($account, [
            'source' => 'test',
            'type' => 'content_opportunity',
            'title' => 'Visible brand signal',
            'summary' => 'Brand level signal.',
            'status' => 'reviewed',
        ], $brand);
        $service->create($account, [
            'source' => 'test',
            'type' => 'visibility_change',
            'title' => 'Hidden other brand signal',
            'summary' => 'Other brand signal.',
            'status' => 'new',
        ], $otherBrand);
        $service->create($otherAccount, [
            'source' => 'test',
            'type' => 'visibility_change',
            'title' => 'Hidden other account signal',
            'summary' => 'Other account signal.',
            'status' => 'new',
        ]);

        $this->actingAs($user)
            ->get(route('app.intelligence', ['status' => 'reviewed', 'type' => 'content_opportunity']))
            ->assertOk()
            ->assertSee('Visible brand signal')
            ->assertDontSee('Visible account signal')
            ->assertDontSee('Hidden other brand signal')
            ->assertDontSee('Hidden other account signal');
    }

    public function test_signal_manager_deduplicates_and_feed_filters_priority_and_category(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Alpha Brand', 'slug' => 'alpha-brand']);
        $role = Role::query()->where('name', 'owner')->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');

        $manager = app(SignalManager::class);
        $manager->record($account, [
            'source' => 'billing',
            'type' => 'credits_low',
            'category' => 'billing',
            'priority' => 'critical',
            'dedupe_key' => 'credits-low:test',
            'title' => 'Credits are low',
            'summary' => 'Original balance warning.',
            'status' => 'new',
        ]);
        $manager->record($account, [
            'source' => 'billing',
            'type' => 'credits_low',
            'category' => 'billing',
            'priority' => 'critical',
            'dedupe_key' => 'credits-low:test',
            'title' => 'Credits are still low',
            'summary' => 'Updated balance warning.',
            'status' => 'new',
        ]);
        $manager->record($account, [
            'source' => 'content_generation',
            'type' => 'generation_completed',
            'category' => 'content',
            'priority' => 'low',
            'dedupe_key' => 'generation:test',
            'title' => 'Generation completed',
            'summary' => 'Generated content is ready.',
            'status' => 'new',
        ], $brand);

        $this->assertSame(2, IntelligenceSignal::query()->where('account_id', $account->id)->count());

        $this->actingAs($user)
            ->get(route('app.intelligence', ['category' => 'billing', 'priority' => 'critical']))
            ->assertOk()
            ->assertSee('Credits are still low')
            ->assertDontSee('Generation completed');
    }

    public function test_feed_allows_marking_reviewed_and_dismissing_tenant_scoped_signals(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Alpha Brand', 'slug' => 'alpha-brand']);
        $role = Role::query()->where('name', 'owner')->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');

        $signal = app(SignalManager::class)->record($account, [
            'source' => 'system',
            'type' => 'technical_issue',
            'category' => 'system',
            'priority' => 'high',
            'dedupe_key' => 'system:test',
            'title' => 'System signal',
            'summary' => 'Needs operator review.',
            'status' => 'new',
        ], $brand);

        $this->actingAs($user)
            ->post(route('app.intelligence.reviewed', $signal))
            ->assertRedirect();

        $this->assertSame('reviewed', $signal->refresh()->status);
        $this->assertNotNull($signal->reviewed_at);

        $this->actingAs($user)
            ->post(route('app.intelligence.dismiss', $signal))
            ->assertRedirect();

        $this->assertSame('dismissed', $signal->refresh()->status);
        $this->assertNotNull($signal->dismissed_at);
    }

    public function test_intelligence_feed_filters_by_source_topic_entity_sentiment_period_and_shows_detail(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Alpha Brand', 'slug' => 'alpha-brand']);
        $role = Role::query()->where('name', 'owner')->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');

        $source = Source::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Reddit Monitor',
            'type' => 'forum',
            'provider' => 'reddit',
            'status' => 'active',
        ]);
        $topic = Topic::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Pricing',
            'slug' => 'pricing',
            'status' => 'active',
        ]);
        $entity = Entity::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Pricing Desk',
            'slug' => 'pricing-desk',
            'entity_type' => 'product',
            'status' => 'active',
        ]);

        $mention = app(MentionIntelligenceService::class)->create($account, $brand, [
            'source_id' => $source->id,
            'title' => 'Pricing Desk mention',
            'content' => 'Pricing Desk was discussed in a pricing thread.',
            'sentiment' => 'negative',
            'impact_score' => 88,
            'published_at' => now(),
        ]);
        $signal = IntelligenceSignal::query()->where('type', 'sentiment_shift')->firstOrFail();

        $this->actingAs($user)
            ->get(route('app.intelligence', [
                'source_id' => $source->id,
                'topic_id' => $topic->id,
                'entity_id' => $entity->id,
                'sentiment' => 'negative',
                'date_from' => now()->subDay()->toDateString(),
                'date_to' => now()->addDay()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('Negative mention needs review')
            ->assertDontSee('No signals found');

        $this->actingAs($user)
            ->get(route('app.intelligence.show', $signal))
            ->assertOk()
            ->assertSee('Signal detail')
            ->assertSee('Negative mention needs review');

        $this->assertTrue($mention->topics()->whereKey($topic->id)->exists());
        $this->assertTrue($mention->relationships()->where('related_id', $entity->id)->exists());
    }

    public function test_dashboard_shows_recent_tenant_scoped_intelligence_signals(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Alpha Brand', 'slug' => 'alpha-brand']);
        $otherBrand = Brand::query()->create(['account_id' => $account->id, 'name' => 'Other Brand', 'slug' => 'other-brand']);
        $role = Role::query()->where('name', 'owner')->firstOrFail();
        $service = app(IntelligenceSignalService::class);

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');

        $service->create($account, [
            'source' => 'test',
            'type' => 'agent_recommendation',
            'title' => 'Visible dashboard signal',
            'summary' => 'This should show.',
            'status' => 'new',
        ], $brand);
        $service->create($account, [
            'source' => 'test',
            'type' => 'agent_recommendation',
            'title' => 'Resolved dashboard signal',
            'summary' => 'This should not show in feed.',
            'status' => 'resolved',
        ], $brand);
        $service->create($account, [
            'source' => 'test',
            'type' => 'agent_recommendation',
            'title' => 'Hidden dashboard signal',
            'summary' => 'This should not show.',
            'status' => 'new',
        ], $otherBrand);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Visible dashboard signal')
            ->assertDontSee('Resolved dashboard signal')
            ->assertDontSee('Hidden dashboard signal');
    }

    public function test_demo_signal_seeder_creates_realistic_signals_for_existing_accounts(): void
    {
        $account = Account::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        Brand::query()->create(['account_id' => $account->id, 'name' => 'Alpha Brand', 'slug' => 'alpha-brand']);

        $this->seed(IntelligenceSignalSeeder::class);

        $this->assertSame(3, IntelligenceSignal::query()->where('account_id', $account->id)->count());
        $this->assertDatabaseHas('intelligence_signals', ['type' => 'visibility_change']);
        $this->assertDatabaseHas('intelligence_signals', ['type' => 'content_opportunity']);
        $this->assertDatabaseHas('intelligence_signals', ['type' => 'integration_event']);
    }
}
