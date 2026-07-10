<?php

namespace Database\Factories;

use App\Enums\ContentLifecycleStatus;
use App\Enums\ContentSource;
use App\Enums\ContentType;
use App\Enums\SupportedLanguage;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Content>
 */
class ContentFactory extends Factory
{
    protected $model = Content::class;

    public function definition(): array
    {
        $workspace = $this->workspace();
        $slug = $this->faker->unique()->slug();

        return [
            'workspace_id' => $workspace->id,
            'client_site_id' => null,
            'title' => $this->faker->sentence(5),
            'language' => SupportedLanguage::EN,
            'type' => ContentType::ARTICLE,
            'status' => ContentLifecycleStatus::BRIEF->toLegacyStatus(),
            'source' => ContentSource::SYSTEM,
            'lifecycle_stage' => ContentLifecycleStatus::BRIEF,
            'generation_mode' => 'balanced',
            'delivery_status' => 'pending',
            'published_url' => 'https://example.com/'.$slug,
        ];
    }

    public function forWorkspace(Workspace $workspace): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $workspace->id,
        ]);
    }

    public function forSite(ClientSite $site): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $site->workspace_id,
            'client_site_id' => $site->id,
            'published_url' => rtrim((string) ($site->base_url ?: $site->site_url), '/').'/'.$this->faker->unique()->slug(),
        ]);
    }

    private function workspace(): Workspace
    {
        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'content-factory-org'],
            ['name' => 'Content Factory Organization', 'status' => Organization::STATUS_ACTIVE, 'approved_at' => now()]
        );

        return Workspace::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'Content Factory Workspace'],
            ['display_name' => 'Content Factory Workspace']
        );
    }
}
