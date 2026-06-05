<?php

namespace Database\Factories;

use App\Models\CompetitorContentItem;
use App\Models\SiteCompetitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompetitorContentItem>
 */
class CompetitorContentItemFactory extends Factory
{
    protected $model = CompetitorContentItem::class;

    public function definition(): array
    {
        $competitor = SiteCompetitor::query()->with('workspace')->first();
        $url = 'https://' . ($competitor?->domain ?? $this->faker->domainName()) . '/' . $this->faker->slug();

        return [
            'organization_id' => $competitor?->workspace?->organization_id,
            'workspace_id' => $competitor?->workspace_id,
            'client_site_id' => $competitor?->client_site_id,
            'site_competitor_id' => $competitor?->id,
            'source_type' => 'factory',
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'title' => 'AI visibility implementation guide',
            'meta_description' => 'A practical competitor page about AI visibility.',
            'content_excerpt' => 'AI visibility implementation guide for B2B content teams with answer blocks and workflows.',
            'normalized_text' => 'AI visibility implementation guide for B2B content teams with answer blocks and workflows.',
            'content_type' => 'article',
            'content_format' => 'implementation_guide',
            'query_intent' => 'implementation',
            'funnel_stage' => 'mofu',
            'detected_topics' => ['ai visibility implementation'],
            'detected_entities' => ['AI', 'B2B'],
            'seo_patterns' => ['title_has_modifier' => true],
            'aeo_patterns' => ['answer_block' => true],
            'normalized_payload' => [],
            'normalized_payload_hash' => hash('sha256', $url . '|payload'),
            'imported_at' => now(),
        ];
    }
}
