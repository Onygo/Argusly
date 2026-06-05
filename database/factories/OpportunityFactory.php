<?php

namespace Database\Factories;

use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Opportunity>
 */
class OpportunityFactory extends Factory
{
    protected $model = Opportunity::class;

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

        $topic = $this->faker->words(3, true);

        return [
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'category' => OpportunityCategory::CONTENT_GAP,
            'status' => OpportunityStatus::OPEN,
            'title' => 'Content gap: '.$topic,
            'topic' => $topic,
            'summary' => $this->faker->sentence(),
            'priority_score' => 70,
            'confidence_score' => 76,
            'impact_score' => 80,
            'urgency_score' => 68,
            'effort_score' => 55,
            'score_breakdown' => [],
            'recommended_actions' => [],
            'evidence' => [],
            'source_signal_summary' => [],
            'dedupe_hash' => hash('sha256', $workspace->id.$topic),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
