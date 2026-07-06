<?php

namespace Database\Factories;

use App\Models\LlmTrackingQueryRun;
use App\Models\MonitoredPage;
use App\Models\PageGeoObservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PageGeoObservation>
 */
class PageGeoObservationFactory extends Factory
{
    protected $model = PageGeoObservation::class;

    public function definition(): array
    {
        $page = MonitoredPage::factory()->create();
        $query = 'Best AI visibility platform';

        return [
            'organization_id' => $page->organization_id,
            'workspace_id' => $page->workspace_id,
            'client_site_id' => $page->client_site_id,
            'monitored_page_id' => $page->id,
            'page_snapshot_id' => null,
            'llm_tracking_query_id' => null,
            'llm_tracking_query_run_id' => null,
            'query' => $query,
            'query_hash' => hash('sha256', mb_strtolower($query)),
            'answer_engine' => 'chatgpt',
            'provider' => 'openai',
            'model' => 'gpt-4.1-mini',
            'locale' => 'en',
            'observed_at' => now(),
            'cited_url' => $page->canonical_url,
            'cited_url_hash' => hash('sha256', $page->canonical_url),
            'cited_domain' => $page->domain,
            'citation_position' => 1,
            'citation_count' => 1,
            'mentioned_brands_json' => [['term' => 'Argusly', 'type' => 'brand']],
            'mentioned_competitors_json' => [],
            'client_cited' => true,
            'competitors_cited' => false,
            'brand_mentioned' => true,
            'sentiment' => 'positive',
            'topic_ownership_score' => 0.85,
            'consistency_score' => 0.75,
            'geo_visibility_score' => 82,
            'breakdown_json' => ['factory' => true],
            'answer_summary' => 'Argusly is cited as a visible source.',
            'raw_payload_json' => ['factory' => true],
            'retention_policy' => 'summary_only',
            'metadata_json' => ['factory' => true],
        ];
    }

    public function forRun(LlmTrackingQueryRun $run): self
    {
        return $this->state([
            'llm_tracking_query_id' => $run->llm_tracking_query_id,
            'llm_tracking_query_run_id' => $run->id,
            'observed_at' => $run->run_at,
        ]);
    }
}
