<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Integration;
use App\Models\IntegrationConnection;
use App\Models\Mention;
use App\Models\Role;
use App\Models\Source;
use App\Models\SourceConnection;
use App\Models\Topic;
use App\Models\User;
use App\Services\MentionIntelligenceService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\IntegrationCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MentionIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_mention_feed_shows_current_brand_mentions_and_filters_by_sentiment(): void
    {
        [$user, $account, $brand] = $this->tenantUser();
        $otherBrand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => 'Other Brand',
            'slug' => 'other-brand',
        ]);

        Mention::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Positive launch mention',
            'content' => 'Customers praise the new launch.',
            'sentiment' => 'positive',
            'impact_score' => 81,
            'published_at' => now(),
        ]);

        Mention::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Critical support mention',
            'content' => 'Support response needs attention.',
            'sentiment' => 'negative',
            'impact_score' => 65,
            'published_at' => now(),
        ]);

        Mention::query()->create([
            'account_id' => $account->id,
            'brand_id' => $otherBrand->id,
            'title' => 'Hidden other brand mention',
            'sentiment' => 'positive',
            'published_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('app.mentions', ['sentiment' => 'positive']))
            ->assertOk()
            ->assertSee('Positive launch mention')
            ->assertDontSee('Critical support mention')
            ->assertDontSee('Hidden other brand mention');
    }

    public function test_mention_detail_shows_entities_source_and_topic_relationships(): void
    {
        [$user, $account, $brand] = $this->tenantUser();
        $source = $this->source($account, $brand, $user);

        $mention = app(MentionIntelligenceService::class)->create($account, $brand, [
            'source_id' => $source->id,
            'title' => 'Analyst mentions Alpha',
            'content' => 'Alpha was included in the competitive intelligence roundup.',
            'author' => 'Industry Analyst',
            'sentiment' => 'mixed',
            'impact_score' => 74,
            'entities' => [
                ['entity_name' => 'Alpha', 'entity_type' => 'brand'],
                ['entity_name' => 'Competitive Intelligence', 'entity_type' => 'topic'],
            ],
        ]);

        $topic = Topic::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Competitive Intelligence',
            'slug' => 'competitive-intelligence',
            'status' => 'active',
        ]);

        $mention->topics()->attach($topic->id, [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'relationship_type' => 'primary',
            'relevance_score' => 91,
        ]);

        $this->actingAs($user)
            ->get(route('app.mentions.show', $mention))
            ->assertOk()
            ->assertSee('Analyst mentions Alpha')
            ->assertSee('Industry Analyst')
            ->assertSee('Alpha')
            ->assertSee('Competitive Intelligence')
            ->assertSee('LinkedIn Monitor');
    }

    public function test_mentions_reference_sources_not_integration_credentials(): void
    {
        [$user, $account, $brand] = $this->tenantUser();
        $credential = IntegrationConnection::query()->create([
            'integration_id' => Integration::query()->where('key', 'linkedin')->firstOrFail()->id,
            'owner_user_id' => $user->id,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'LinkedIn OAuth',
            'status' => 'active',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Mention source must be a configured source in the same account.');

        app(MentionIntelligenceService::class)->create($account, $brand, [
            'source_id' => $credential->id,
            'title' => 'Credential should not be a mention source',
        ]);
    }

    public function test_dashboard_shows_recent_mentions_and_sentiment_overview(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');

        Mention::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Dashboard mention',
            'sentiment' => 'neutral',
            'published_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Recent Mentions')
            ->assertSee('Sentiment Overview')
            ->assertSee('Dashboard mention');
    }

    /**
     * @return array{User, Account, Brand}
     */
    private function tenantUser(string $roleName = 'viewer'): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);
        $this->seed(IntegrationCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => 'Alpha Agency', 'slug' => 'alpha-agency']);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => 'Alpha Main',
            'slug' => 'alpha-main',
            'domain' => 'alpha.example',
        ]);

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail(), [
            'account_id' => $account->id,
            'brand_id' => $roleName === 'viewer' ? $brand->id : null,
        ]);

        app(SubscriptionService::class)->activatePlan($account, 'starter_monthly');

        return [$user, $account, $brand];
    }

    private function source(Account $account, Brand $brand, User $owner): Source
    {
        $connection = IntegrationConnection::query()->create([
            'integration_id' => Integration::query()->where('key', 'linkedin')->firstOrFail()->id,
            'owner_user_id' => $owner->id,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'LinkedIn OAuth',
            'status' => 'active',
        ]);

        $source = Source::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'LinkedIn Monitor',
            'type' => 'social',
            'provider' => 'linkedin',
            'status' => 'active',
        ]);

        SourceConnection::query()->create([
            'source_id' => $source->id,
            'integration_connection_id' => $connection->id,
            'status' => 'active',
        ]);

        return $source;
    }
}
