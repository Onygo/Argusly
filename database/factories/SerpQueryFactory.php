<?php

namespace Database\Factories;

use App\Models\SerpQuery;
use App\Models\SerpQuerySet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SerpQuery>
 */
class SerpQueryFactory extends Factory
{
    protected $model = SerpQuery::class;

    public function definition(): array
    {
        $querySet = SerpQuerySet::factory()->create();
        $query = 'page intelligence software';

        return [
            'organization_id' => $querySet->organization_id,
            'workspace_id' => $querySet->workspace_id,
            'client_site_id' => $querySet->client_site_id,
            'serp_query_set_id' => $querySet->id,
            'query' => $query,
            'query_hash' => hash('sha256', mb_strtolower($query)),
            'locale' => $querySet->locale,
            'country' => $querySet->country,
            'device' => $querySet->device,
            'search_engine' => $querySet->search_engine,
            'keyword_intent' => 'informational',
            'search_volume' => 1200,
            'priority' => 100,
            'status' => SerpQuery::STATUS_ACTIVE,
            'metadata_json' => ['factory' => true],
        ];
    }
}
