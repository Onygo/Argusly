<?php

namespace Database\Factories;

use App\Models\MonitoredPage;
use App\Models\PageSerpObservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PageSerpObservation>
 */
class PageSerpObservationFactory extends Factory
{
    protected $model = PageSerpObservation::class;

    public function definition(): array
    {
        $page = MonitoredPage::factory()->create();
        $query = 'page intelligence platform';

        return [
            'organization_id' => $page->organization_id,
            'workspace_id' => $page->workspace_id,
            'client_site_id' => $page->client_site_id,
            'monitored_page_id' => $page->id,
            'page_snapshot_id' => null,
            'query' => $query,
            'query_hash' => hash('sha256', mb_strtolower($query)),
            'locale' => 'en_US',
            'country' => 'US',
            'device' => 'desktop',
            'search_engine' => 'google',
            'observed_at' => now(),
            'result_type' => 'organic',
            'position' => 3,
            'absolute_position' => 3,
            'page_url' => $page->canonical_url,
            'page_url_hash' => hash('sha256', $page->canonical_url),
            'domain' => $page->domain,
            'title' => $page->title_current,
            'snippet' => 'A monitored page SERP observation.',
            'serp_features_json' => [],
            'competitor_presence_json' => [],
            'search_volume' => 1200,
            'keyword_intent' => 'informational',
            'click_potential' => 0.11,
            'visibility_score' => 75,
            'breakdown_json' => ['factory' => true],
            'raw_payload_json' => ['factory' => true],
            'provider_key' => 'factory',
            'metadata_json' => ['factory' => true],
        ];
    }
}
