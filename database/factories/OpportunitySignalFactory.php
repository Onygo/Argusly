<?php

namespace Database\Factories;

use App\Enums\OpportunityCategory;
use App\Enums\OpportunitySignalSource;
use App\Models\OpportunitySignal;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpportunitySignal>
 */
class OpportunitySignalFactory extends Factory
{
    protected $model = OpportunitySignal::class;

    public function definition(): array
    {
        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'factory-org'],
            ['name' => 'Factory Organization', 'status' => Organization::STATUS_ACTIVE, 'approved_at' => now()]
        );

        $workspace = Workspace::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'Factory Workspace'],
            ['organization_id' => $organization->id, 'name' => 'Factory Workspace']
        );

        $topic = 'AI visibility workflow';

        return [
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'source' => OpportunitySignalSource::SEARCH_TRENDS,
            'category' => OpportunityCategory::TREND_OPPORTUNITY,
            'topic' => $topic,
            'signal_strength' => 72,
            'confidence' => 80,
            'observed_at' => now(),
            'metrics' => ['growth_rate' => 0.34],
            'evidence' => [['label' => 'Trend growth', 'value' => '34%']],
            'metadata' => [],
            'dedupe_hash' => hash('sha256', $workspace->id.$topic.now()->toDateString()),
        ];
    }
}
