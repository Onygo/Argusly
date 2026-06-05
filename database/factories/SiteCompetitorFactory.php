<?php

namespace Database\Factories;

use App\Models\ClientSite;
use App\Models\SiteCompetitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteCompetitor>
 */
class SiteCompetitorFactory extends Factory
{
    protected $model = SiteCompetitor::class;

    public function definition(): array
    {
        $site = ClientSite::query()->first();

        return [
            'workspace_id' => $site?->workspace_id,
            'client_site_id' => $site?->id,
            'name' => $this->faker->company(),
            'domain' => $this->faker->unique()->domainName(),
            'notes' => 'Competitive intelligence target.',
            'is_active' => true,
        ];
    }
}
