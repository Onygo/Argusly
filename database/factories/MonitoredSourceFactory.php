<?php

namespace Database\Factories;

use App\Models\MonitoredSource;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitoredSource>
 */
class MonitoredSourceFactory extends Factory
{
    protected $model = MonitoredSource::class;

    public function definition(): array
    {
        $workspace = $this->workspace();
        $domain = $this->faker->unique()->domainName();

        return [
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'client_site_id' => null,
            'source_type' => $this->faker->randomElement(['rss', 'xml_sitemap', 'competitor_crawl', 'press_room', 'serp', 'answer_engine_citation']),
            'name' => 'Monitored source '.$this->faker->unique()->numberBetween(100, 999),
            'base_url' => 'https://'.$domain,
            'domain' => $domain,
            'status' => MonitoredSource::STATUS_ACTIVE,
            'trust_level' => 3,
            'authority_score' => 62.50,
            'polling_frequency' => 'daily',
            'crawl_policy_json' => ['respect_robots' => true],
            'fetch_config_json' => ['timeout_seconds' => 10],
            'discovery_config_json' => ['max_urls' => 25],
            'metadata_json' => ['factory' => true],
            'last_discovered_at' => now()->subHour(),
            'last_fetched_at' => now()->subMinutes(45),
            'last_processed_at' => now()->subMinutes(30),
            'failure_count' => 0,
        ];
    }

    private function workspace(): Workspace
    {
        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'page-intelligence-factory-org'],
            ['name' => 'Page Intelligence Factory Organization', 'status' => Organization::STATUS_ACTIVE, 'approved_at' => now()]
        );

        return Workspace::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'Page Intelligence Factory Workspace'],
            ['display_name' => 'Page Intelligence Factory Workspace']
        );
    }
}
