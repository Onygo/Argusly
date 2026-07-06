<?php

namespace Database\Factories;

use App\Models\MonitoredPage;
use App\Models\MonitoredSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitoredPage>
 */
class MonitoredPageFactory extends Factory
{
    protected $model = MonitoredPage::class;

    public function definition(): array
    {
        $source = MonitoredSource::factory()->create();
        $slug = $this->faker->unique()->slug();
        $domain = $source->domain ?: $this->faker->domainName();
        $url = 'https://'.$domain.'/'.$slug;

        return [
            'organization_id' => $source->organization_id,
            'workspace_id' => $source->workspace_id,
            'client_site_id' => $source->client_site_id,
            'monitored_source_id' => $source->id,
            'canonical_url' => $url,
            'canonical_url_hash' => hash('sha256', $url),
            'first_seen_url' => $url.'?utm_source=factory',
            'first_seen_url_hash' => hash('sha256', $url.'?utm_source=factory'),
            'final_url' => $url,
            'final_url_hash' => hash('sha256', $url),
            'domain' => $domain,
            'path' => '/'.$slug,
            'source_type' => $source->source_type,
            'page_type' => 'article',
            'content_type' => 'text/html',
            'publisher_name' => 'Factory Publisher',
            'language_current' => 'en',
            'title_current' => 'Page intelligence market update',
            'published_at_current' => now()->subDay(),
            'first_seen_at' => now()->subHours(3),
            'last_seen_at' => now()->subHours(2),
            'last_fetched_at' => now()->subHour(),
            'last_changed_at' => now()->subHour(),
            'crawl_status' => MonitoredPage::CRAWL_STATUS_FETCHED,
            'indexability_status' => 'indexable',
            'dedupe_key' => hash('sha256', 'dedupe|'.$url),
            'syndication_group_key' => hash('sha256', 'syndication|'.$url),
            'metadata_json' => ['factory' => true],
        ];
    }
}
