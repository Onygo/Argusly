<?php

namespace Database\Factories;

use App\Enums\ContentDecayRiskLevel;
use App\Models\Content;
use App\Models\ContentLifecycleAnalysis;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentLifecycleAnalysis>
 */
class ContentLifecycleAnalysisFactory extends Factory
{
    protected $model = ContentLifecycleAnalysis::class;

    public function definition(): array
    {
        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'factory-lifecycle-org'],
            ['name' => 'Factory Lifecycle Organization', 'status' => Organization::STATUS_ACTIVE, 'approved_at' => now()]
        );

        $workspace = Workspace::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'Factory Lifecycle Workspace'],
            ['organization_id' => $organization->id, 'name' => 'Factory Lifecycle Workspace']
        );

        $content = Content::query()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'title' => 'Factory Lifecycle Content'],
            ['type' => 'article', 'status' => 'draft', 'source' => 'api', 'generation_mode' => 'balanced']
        );

        return [
            'content_id' => $content->id,
            'workspace_id' => $workspace->id,
            'client_site_id' => null,
            'lifecycle_score' => $this->faker->numberBetween(35, 90),
            'decay_score' => $this->faker->numberBetween(10, 80),
            'decay_risk_level' => ContentDecayRiskLevel::MEDIUM->value,
            'refresh_priority_score' => $this->faker->numberBetween(20, 85),
            'confidence_score' => $this->faker->numberBetween(45, 90),
            'signals' => [],
            'score_breakdown' => [],
            'refresh_recommendations' => [],
            'campaign_reconnect_suggestions' => [],
            'related_content_suggestions' => [],
            'internal_linking_suggestions' => [],
            'analyzed_at' => now(),
        ];
    }
}
