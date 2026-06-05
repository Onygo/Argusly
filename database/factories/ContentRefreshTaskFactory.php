<?php

namespace Database\Factories;

use App\Enums\ContentRefreshTaskStatus;
use App\Enums\ContentRefreshTaskType;
use App\Models\Content;
use App\Models\ContentRefreshTask;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentRefreshTask>
 */
class ContentRefreshTaskFactory extends Factory
{
    protected $model = ContentRefreshTask::class;

    public function definition(): array
    {
        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'factory-refresh-task-org'],
            ['name' => 'Factory Refresh Task Organization', 'status' => Organization::STATUS_ACTIVE, 'approved_at' => now()]
        );

        $workspace = Workspace::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'Factory Refresh Task Workspace'],
            ['organization_id' => $organization->id, 'name' => 'Factory Refresh Task Workspace']
        );

        $content = Content::query()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'title' => 'Factory Refresh Task Content'],
            ['type' => 'article', 'status' => 'draft', 'source' => 'api', 'generation_mode' => 'balanced']
        );

        return [
            'content_id' => $content->id,
            'workspace_id' => $workspace->id,
            'client_site_id' => null,
            'content_lifecycle_analysis_id' => null,
            'campaign_id' => null,
            'type' => ContentRefreshTaskType::REFRESH_CONTENT->value,
            'status' => ContentRefreshTaskStatus::OPEN->value,
            'priority' => $this->faker->numberBetween(40, 90),
            'title' => 'Refresh decaying content',
            'description' => 'Review lifecycle signals and refresh the content.',
            'recommended_actions' => ['Review evidence and update the article.'],
            'evidence' => [],
            'due_at' => now()->addDays(14),
            'completed_at' => null,
        ];
    }
}
