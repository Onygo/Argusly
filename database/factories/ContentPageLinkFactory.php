<?php

namespace Database\Factories;

use App\Enums\ContentLifecycleStatus;
use App\Enums\ContentPageLinkType;
use App\Enums\ContentSource;
use App\Enums\ContentType;
use App\Enums\SupportedLanguage;
use App\Models\Content;
use App\Models\ContentPageLink;
use App\Models\MonitoredPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentPageLink>
 */
class ContentPageLinkFactory extends Factory
{
    protected $model = ContentPageLink::class;

    public function definition(): array
    {
        $page = MonitoredPage::factory()->create();
        $content = Content::query()->create([
            'workspace_id' => $page->workspace_id,
            'client_site_id' => $page->client_site_id,
            'title' => $page->title_current ?: 'Linked monitored page',
            'language' => SupportedLanguage::fromStringOrDefault($page->language_current),
            'type' => ContentType::SEO_PAGE,
            'status' => ContentLifecycleStatus::BRIEF->toLegacyStatus(),
            'source' => ContentSource::SYSTEM,
            'lifecycle_stage' => ContentLifecycleStatus::BRIEF,
            'generation_mode' => 'balanced',
            'delivery_status' => 'pending',
            'published_url' => $page->canonical_url,
        ]);

        return [
            'workspace_id' => $page->workspace_id,
            'client_site_id' => $page->client_site_id,
            'content_id' => $content->id,
            'monitored_page_id' => $page->id,
            'link_type' => ContentPageLinkType::OBSERVED_SOURCE,
            'is_primary' => true,
            'confidence_score' => 90.0,
            'metadata' => ['factory' => true],
        ];
    }

    public function forContentAndPage(Content $content, MonitoredPage $page): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $content->workspace_id,
            'client_site_id' => $content->client_site_id ?: $page->client_site_id,
            'content_id' => $content->id,
            'monitored_page_id' => $page->id,
        ]);
    }
}
