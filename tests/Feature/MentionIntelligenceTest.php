<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Integration;
use App\Models\IntegrationConnection;
use App\Models\Entity;
use App\Models\IntelligenceSignal;
use App\Models\Mention;
use App\Models\Narrative;
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

    public function test_ingestion_normalizes_links_entities_topics_evidence_and_signals(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $source = $this->source($account, $brand, $user);
        $topic = Topic::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Competitive Intelligence',
            'slug' => 'competitive-intelligence',
            'status' => 'active',
        ]);
        $competitor = Entity::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Beta Rival',
            'slug' => 'beta-rival',
            'entity_type' => 'competitor',
            'status' => 'active',
        ]);

        for ($i = 1; $i <= 3; $i++) {
            app(MentionIntelligenceService::class)->create($account, $brand, [
                'source_id' => $source->id,
                'title' => "  Competitive Intelligence mention {$i}  ",
                'content' => 'Beta Rival is gaining attention in competitive intelligence.',
                'url' => "https://example.com/post-{$i}#comments",
                'sentiment' => $i === 1 ? 'negative' : 'mixed',
                'impact_score' => $i === 1 ? 82 : 55,
                'entities' => [
                    ['entity_name' => 'Beta Rival', 'entity_type' => 'competitor'],
                ],
            ]);
        }

        $mention = Mention::query()->where('url', 'https://example.com/post-1')->firstOrFail();

        $this->assertSame('Competitive Intelligence mention 1', $mention->title);
        $this->assertTrue($mention->topics()->whereKey($topic->id)->exists());
        $this->assertTrue($mention->relationships()->where('related_id', $competitor->id)->exists());
        $this->assertDatabaseHas('evidence_items', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'subject_type' => $mention->getMorphClass(),
            'subject_id' => $mention->id,
            'evidence_type' => 'mention',
        ]);
        $this->assertDatabaseHas('intelligence_signals', ['account_id' => $account->id, 'brand_id' => $brand->id, 'type' => 'mention_captured']);
        $this->assertDatabaseHas('intelligence_signals', ['account_id' => $account->id, 'brand_id' => $brand->id, 'type' => 'sentiment_shift']);
        $this->assertDatabaseHas('intelligence_signals', ['account_id' => $account->id, 'brand_id' => $brand->id, 'type' => 'topic_velocity']);
        $this->assertDatabaseHas('intelligence_signals', ['account_id' => $account->id, 'brand_id' => $brand->id, 'type' => 'competitor_mention']);
        $this->assertGreaterThanOrEqual(8, IntelligenceSignal::query()->where('account_id', $account->id)->count());
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

    public function test_brand_intelligence_dashboard_tracks_coverage_journalists_publications_share_of_voice_and_narratives(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $news = Source::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Tech Daily',
            'type' => 'news',
            'provider' => 'rss',
            'status' => 'active',
        ]);
        $social = Source::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'LinkedIn Monitor',
            'type' => 'social',
            'provider' => 'linkedin',
            'status' => 'active',
        ]);
        $topic = Topic::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'AI Visibility',
            'slug' => 'ai-visibility',
            'status' => 'active',
        ]);
        $narrative = Narrative::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'AI Visibility Leader',
            'description' => 'Alpha should be understood as an AI visibility leader.',
            'narrative_type' => 'brand',
            'status' => 'active',
            'importance' => 'high',
        ]);
        $narrative->topics()->attach($topic->id);

        $positive = Mention::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'source_id' => $news->id,
            'title' => 'Alpha becomes AI Visibility Leader',
            'content' => 'Alpha Main is gaining attention for AI Visibility.',
            'url' => 'https://techdaily.example/alpha',
            'author' => 'Jane Reporter',
            'sentiment' => 'positive',
            'impact_score' => 88,
            'published_at' => now(),
            'metadata' => ['publication' => 'Tech Daily'],
        ]);
        $positive->topics()->attach($topic->id, [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'relationship_type' => 'primary',
            'relevance_score' => 90,
        ]);

        $competitor = Mention::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'source_id' => $social->id,
            'title' => 'Beta Rival gets discussed',
            'content' => 'Beta Rival is compared against Alpha Main.',
            'url' => 'https://linkedin.example/posts/beta',
            'author' => 'Jane Reporter',
            'sentiment' => 'mixed',
            'impact_score' => 62,
            'published_at' => now()->subDay(),
        ]);
        $competitor->entities()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'entity_name' => 'Beta Rival',
            'entity_type' => 'competitor',
        ]);

        Mention::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'source_id' => $news->id,
            'title' => 'Support criticism',
            'content' => 'A critical note about support response.',
            'url' => 'https://techdaily.example/support',
            'author' => 'Mark Editor',
            'sentiment' => 'negative',
            'impact_score' => 45,
            'published_at' => now()->subDays(2),
        ]);

        $otherAccount = Account::query()->create(['name' => 'Hidden Account', 'slug' => 'hidden-account']);
        $otherBrand = Brand::query()->create(['account_id' => $otherAccount->id, 'name' => 'Hidden Brand', 'slug' => 'hidden-brand']);
        Mention::query()->create([
            'account_id' => $otherAccount->id,
            'brand_id' => $otherBrand->id,
            'title' => 'Hidden coverage',
            'sentiment' => 'positive',
            'published_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('app.mentions', ['source_type' => 'news']))
            ->assertOk()
            ->assertSee('Brand Intelligence')
            ->assertSee('Coverage dashboard')
            ->assertSee('Journalist tracking')
            ->assertSee('Publication tracking')
            ->assertSee('Share of voice')
            ->assertSee('Narrative monitoring')
            ->assertSee('Executive insights')
            ->assertSee('Tech Daily')
            ->assertSee('Jane Reporter')
            ->assertSee('AI Visibility Leader')
            ->assertSee('Alpha becomes AI Visibility Leader')
            ->assertDontSee('Beta Rival gets discussed')
            ->assertDontSee('Hidden coverage');
    }

    public function test_brand_intelligence_mentions_export_is_filtered_and_tenant_safe(): void
    {
        [$user, $account, $brand] = $this->tenantUser('owner');
        $source = Source::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Tech Daily',
            'type' => 'news',
            'provider' => 'rss',
            'status' => 'active',
        ]);

        Mention::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'source_id' => $source->id,
            'title' => 'Exported coverage',
            'content' => 'Export this mention.',
            'author' => 'Jane Reporter',
            'sentiment' => 'positive',
            'impact_score' => 90,
            'published_at' => now(),
            'metadata' => ['publication' => 'Tech Daily'],
        ]);
        Mention::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'source_id' => $source->id,
            'title' => 'Filtered negative coverage',
            'author' => 'Mark Editor',
            'sentiment' => 'negative',
            'published_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get(route('app.mentions.export', ['sentiment' => 'positive']));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();

        $this->assertStringContainsString('Exported coverage', $csv);
        $this->assertStringContainsString('Jane Reporter', $csv);
        $this->assertStringNotContainsString('Filtered negative coverage', $csv);
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
