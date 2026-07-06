<?php

namespace Database\Factories;

use App\Models\PageContentExtraction;
use App\Models\PageSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PageContentExtraction>
 */
class PageContentExtractionFactory extends Factory
{
    protected $model = PageContentExtraction::class;

    public function definition(): array
    {
        $snapshot = PageSnapshot::factory()->create();
        $page = $snapshot->page;
        $mainText = 'Argusly page evidence for media monitoring and page intelligence.';
        $mainHtml = '<main><h1>Page intelligence market update</h1><p>Argusly page evidence.</p></main>';

        return [
            'organization_id' => $snapshot->organization_id,
            'workspace_id' => $snapshot->workspace_id,
            'client_site_id' => $snapshot->client_site_id,
            'monitored_page_id' => $page->id,
            'page_snapshot_id' => $snapshot->id,
            'extraction_method' => 'factory',
            'extractor_version' => 'factory-v1',
            'title' => 'Page intelligence market update',
            'meta_description' => 'A factory page extraction for Page Intelligence.',
            'h1' => 'Page intelligence market update',
            'headings_json' => [
                ['level' => 1, 'text' => 'Page intelligence market update'],
                ['level' => 2, 'text' => 'Evidence'],
            ],
            'author' => 'Factory Author',
            'publisher' => 'Factory Publisher',
            'published_at' => now()->subDay(),
            'language' => 'en',
            'summary' => 'A summary of the extracted monitored page.',
            'main_text' => $mainText,
            'main_text_hash' => hash('sha256', $mainText),
            'main_text_bytes' => strlen($mainText),
            'main_text_preview' => $mainText,
            'main_html' => $mainHtml,
            'main_html_hash' => hash('sha256', $mainHtml),
            'main_html_bytes' => strlen($mainHtml),
            'word_count' => 9,
            'char_count' => 68,
            'estimated_tokens' => 14,
            'content_depth_score' => 58.75,
            'quality_score' => 81.50,
            'structured_data_json' => [['@type' => 'Article']],
            'images_json' => [['src' => 'https://example.com/image.jpg', 'alt' => 'Example']],
            'media_json' => [],
            'outbound_links_json' => [['href' => 'https://example.com/source']],
            'internal_links_json' => [],
            'metadata_json' => ['factory' => true],
        ];
    }

    public function forSnapshot(PageSnapshot $snapshot): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $snapshot->organization_id,
            'workspace_id' => $snapshot->workspace_id,
            'client_site_id' => $snapshot->client_site_id,
            'monitored_page_id' => $snapshot->monitored_page_id,
            'page_snapshot_id' => $snapshot->id,
        ]);
    }
}
