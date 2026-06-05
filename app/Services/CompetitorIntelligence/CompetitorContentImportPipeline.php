<?php

namespace App\Services\CompetitorIntelligence;

use App\Models\CompetitorContentItem;
use App\Models\SiteCompetitor;
use App\Services\CompetitorIntelligence\Contracts\CompetitorContentSource;

class CompetitorContentImportPipeline
{
    public function __construct(
        private readonly CompetitorContentNormalizer $normalizer,
        private readonly CompetitorTopicExtractor $topicExtractor,
        private readonly CompetitorEntityExtractor $entityExtractor,
        private readonly CompetitorQueryIntentClassifier $intentClassifier,
        private readonly CompetitorContentPatternDetector $patternDetector,
    ) {}

    public function import(SiteCompetitor $competitor, array $payload, string $sourceType = 'manual_import'): CompetitorContentItem
    {
        $normalized = $this->normalizer->normalize($payload);
        $text = (string) ($normalized['normalized_text'] ?? '');
        $title = (string) ($normalized['title'] ?? '');
        $url = (string) ($normalized['url'] ?? '');
        $intent = $this->intentClassifier->classify($text);
        $patterns = $this->patternDetector->detect($url, $title, $text);
        $topics = $this->topicExtractor->extract($text);
        $entities = $this->entityExtractor->extract($text);

        $normalizedPayload = [
            'source' => [
                'type' => $sourceType,
                'url' => $url,
                'competitor' => [
                    'id' => $competitor->id,
                    'name' => $competitor->name,
                    'domain' => $competitor->domain,
                ],
            ],
            'content' => [
                'title' => $title,
                'meta_description' => $normalized['meta_description'] ?? null,
                'text_excerpt' => $normalized['content_excerpt'] ?? null,
                'format' => $patterns['content_format'],
                'type' => $patterns['content_type'],
            ],
            'intelligence' => [
                'topics' => $topics,
                'entities' => $entities,
                'query_intent' => $intent['query_intent'],
                'funnel_stage' => $intent['funnel_stage'],
                'landing_page_angle' => $patterns['landing_page_angle'],
                'seo_patterns' => $patterns['seo_patterns'],
                'aeo_patterns' => $patterns['aeo_patterns'],
            ],
        ];

        return CompetitorContentItem::query()->updateOrCreate(
            [
                'workspace_id' => (string) $competitor->workspace_id,
                'site_competitor_id' => $competitor->id,
                'url_hash' => $normalized['url_hash'],
            ],
            [
                'organization_id' => $competitor->workspace?->organization_id,
                'client_site_id' => (string) $competitor->client_site_id,
                'source_type' => $sourceType,
                'url' => $url !== '' ? $url : null,
                'title' => $normalized['title'],
                'meta_description' => $normalized['meta_description'],
                'content_excerpt' => $normalized['content_excerpt'],
                'normalized_text' => $normalized['normalized_text'],
                'content_type' => $patterns['content_type'],
                'content_format' => $patterns['content_format'],
                'query_intent' => $intent['query_intent'],
                'funnel_stage' => $intent['funnel_stage'],
                'landing_page_angle' => $patterns['landing_page_angle'],
                'is_comparison_page' => $patterns['is_comparison_page'],
                'has_answer_block_pattern' => $patterns['has_answer_block_pattern'],
                'has_schema_pattern' => $patterns['has_schema_pattern'],
                'detected_topics' => $topics,
                'detected_entities' => $entities,
                'seo_patterns' => $patterns['seo_patterns'],
                'aeo_patterns' => $patterns['aeo_patterns'],
                'normalized_payload' => $normalizedPayload,
                'normalized_payload_hash' => hash('sha256', json_encode($normalizedPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                'imported_at' => now(),
            ]
        );
    }

    /**
     * @return array<int, CompetitorContentItem>
     */
    public function importFromSource(SiteCompetitor $competitor, CompetitorContentSource $source, array $options = [], string $sourceType = 'source_import'): array
    {
        $items = [];

        foreach ($source->items($competitor, $options) as $payload) {
            $items[] = $this->import($competitor, $payload, $sourceType);
        }

        return $items;
    }
}
