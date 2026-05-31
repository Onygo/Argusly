<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\ContentAsset;
use App\Models\ContentLifecycleScore;
use App\Models\Ga4MetricSnapshot;
use App\Models\Ga4Property;
use App\Models\IntelligenceSignal;
use App\Models\PerformanceInsight;
use App\Models\PublishingAction;
use App\Models\Recommendation;
use App\Models\SearchConsoleQuerySnapshot;
use App\Models\SearchConsoleSite;
use App\Models\VisibilityProviderRun;
use App\Services\PerformanceInsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PerformanceInsightTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_generates_performance_insights_signals_and_recommendations(): void
    {
        [$account, $brand] = $this->tenant();
        $brand->update(['enabled_content_languages' => ['en', 'nl', 'de']]);
        $asset = ContentAsset::factory()->forBrand($brand)->published()->create(['title' => 'Performance guide']);
        $campaign = Campaign::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Spring Campaign',
            'slug' => 'spring-campaign',
            'status' => 'active',
        ]);
        $campaign->contentAssets()->attach($asset);

        $property = Ga4Property::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'display_name' => 'Main GA4',
            'status' => 'connected',
        ]);
        $site = SearchConsoleSite::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'site_url' => 'https://example.test/',
            'status' => 'connected',
        ]);

        $this->ga4Snapshot($property, $asset, now()->subDays(21), 240);
        $this->ga4Snapshot($property, $asset, now()->subDays(4), 60);
        $this->searchSnapshot($site, $asset, now()->subDays(21), 50, 900, 0.055, 3.5);
        $this->searchSnapshot($site, $asset, now()->subDays(4), 12, 1400, 0.01, 8.5);
        PublishingAction::factory()->forContentAsset($asset)->create([
            'action' => 'publish',
            'status' => 'completed',
            'published_at' => now()->subDays(8),
        ]);
        ContentLifecycleScore::factory()->forContentAsset($asset)->create([
            'status' => 'critical',
            'health_score' => 32,
            'refresh_priority' => 70,
            'reason' => 'Search and traffic are declining.',
            'signals' => [
                'translation_coverage' => ['missing_languages' => ['nl', 'de']],
            ],
            'scored_at' => now(),
        ]);
        VisibilityProviderRun::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider' => 'ChatGPT',
            'query' => 'best performance intelligence platform',
            'status' => 'completed',
            'captured_at' => now()->subDay(),
            'metadata' => ['visibility_score' => 24],
        ]);

        $insights = app(PerformanceInsightService::class)->generateForTenant($account, $brand);

        $this->assertContains('traffic_drop', $insights->pluck('type'));
        $this->assertContains('ranking_drop', $insights->pluck('type'));
        $this->assertContains('ctr_opportunity', $insights->pluck('type'));
        $this->assertContains('social_gap', $insights->pluck('type'));
        $this->assertContains('translation_gap', $insights->pluck('type'));
        $this->assertContains('visibility_gap', $insights->pluck('type'));
        $this->assertContains('content_decay', $insights->pluck('type'));
        $this->assertContains('campaign_underperformance', $insights->pluck('type'));

        $traffic = PerformanceInsight::query()->where('type', 'traffic_drop')->firstOrFail();
        $this->assertSame($asset->id, $traffic->content_asset_id);
        $this->assertSame(60, $traffic->payload['current_sessions']);
        $this->assertSame(240, $traffic->payload['previous_sessions']);

        $this->assertDatabaseHas('intelligence_signals', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'source' => 'performance_insights',
            'title' => $traffic->title,
        ]);

        $signal = IntelligenceSignal::query()->where('source', 'performance_insights')->where('title', $traffic->title)->firstOrFail();
        $this->assertSame($traffic->id, $signal->payload['performance_insight_id']);
        $this->assertTrue(Recommendation::query()->where('signal_id', $signal->id)->exists());
    }

    public function test_performance_insights_are_tenant_safe_and_deduplicated(): void
    {
        [$account, $brand] = $this->tenant();
        [, $otherBrand] = $this->tenant('Other', 'other');
        $asset = ContentAsset::factory()->forBrand($brand)->published()->create(['title' => 'Tenant guide']);
        $otherAsset = ContentAsset::factory()->forBrand($otherBrand)->published()->create(['title' => 'Hidden guide']);
        $property = Ga4Property::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'display_name' => 'Main GA4',
            'status' => 'connected',
        ]);
        $otherProperty = Ga4Property::query()->create([
            'account_id' => $otherBrand->account_id,
            'brand_id' => $otherBrand->id,
            'display_name' => 'Other GA4',
            'status' => 'connected',
        ]);

        $this->ga4Snapshot($property, $asset, now()->subDays(21), 200);
        $this->ga4Snapshot($property, $asset, now()->subDays(3), 80);
        $this->ga4Snapshot($otherProperty, $otherAsset, now()->subDays(21), 900);
        $this->ga4Snapshot($otherProperty, $otherAsset, now()->subDays(3), 10);

        $service = app(PerformanceInsightService::class);
        $service->generateForTenant($account, $brand);
        $service->generateForTenant($account, $brand);

        $this->assertSame(1, PerformanceInsight::query()->where('account_id', $account->id)->where('type', 'traffic_drop')->count());
        $this->assertSame(0, PerformanceInsight::query()->where('account_id', $otherBrand->account_id)->count());

        $this->expectException(InvalidArgumentException::class);

        PerformanceInsight::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'content_asset_id' => $otherAsset->id,
            'type' => 'traffic_drop',
            'title' => 'Invalid cross tenant insight',
            'summary' => 'This should be rejected.',
            'severity' => 'high',
            'detected_at' => now(),
        ]);
    }

    private function tenant(string $name = 'Alpha', string $slug = 'alpha'): array
    {
        $account = Account::query()->create(['name' => $name, 'slug' => $slug.'-account']);
        $brand = Brand::query()->create(['account_id' => $account->id, 'name' => $name.' Brand', 'slug' => $slug.'-brand']);

        return [$account, $brand];
    }

    private function ga4Snapshot(Ga4Property $property, ContentAsset $asset, mixed $date, int $sessions): void
    {
        Ga4MetricSnapshot::query()->create([
            'account_id' => $asset->account_id,
            'brand_id' => $asset->brand_id,
            'ga4_property_id' => $property->id,
            'content_asset_id' => $asset->id,
            'date' => $date->toDateString(),
            'sessions' => $sessions,
            'users' => $sessions,
            'pageviews' => $sessions * 2,
        ]);
    }

    private function searchSnapshot(SearchConsoleSite $site, ContentAsset $asset, mixed $date, int $clicks, int $impressions, float $ctr, float $position): void
    {
        SearchConsoleQuerySnapshot::query()->create([
            'account_id' => $asset->account_id,
            'brand_id' => $asset->brand_id,
            'search_console_site_id' => $site->id,
            'content_asset_id' => $asset->id,
            'date' => $date->toDateString(),
            'query' => 'performance intelligence',
            'page' => 'https://example.test/performance-guide',
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $ctr,
            'position' => $position,
        ]);
    }
}
