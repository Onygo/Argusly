<?php

namespace App\Services\SourceBriefing;

use App\Models\ContentSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ChainProposalGenerator
{
    /**
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $workspaceContext
     * @return array<string, mixed>
     */
    public function generate(ContentSource $source, array $analysis, array $workspaceContext): array
    {
        $settings = (array) data_get($source->metadata_json, 'chain_settings', []);
        $topic = trim((string) ($settings['main_topic'] ?? $analysis['main_topic'] ?? $source->source_title ?? ''));
        $secondary = collect((array) ($analysis['secondary_keywords'] ?? []))
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->take(8)
            ->values();
        $settingsSecondary = collect((array) ($settings['secondary_keywords'] ?? []))
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->values();
        $secondary = $settingsSecondary->isNotEmpty() ? $settingsSecondary : $secondary;
        $itemTypes = collect((array) ($settings['item_types'] ?? []))
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->values();
        $requestedCount = (int) ($settings['items_count'] ?? 0);
        $targetCount = $requestedCount > 0
            ? max(1, min(20, $requestedCount))
            : max(4, min(8, $secondary->count() + 1));

        $supportingTopics = $secondary->isNotEmpty()
            ? $secondary->map(fn (string $item, int $index): array => [
                'title' => $this->supportingTitle($topic, $item, $index),
                'primary_keyword' => $item,
                'content_type' => (string) ($itemTypes->get($index + 1) ?: 'supporting_blog'),
                'secondary_keywords' => collect([$topic, $item])->filter()->values()->all(),
                'search_intent' => trim((string) ($settings['search_intent'] ?? $analysis['search_intent'] ?? 'informational')),
                'funnel_stage' => trim((string) ($settings['funnel_stage'] ?? $analysis['funnel_stage'] ?? 'awareness')),
                'target_audience' => trim((string) ($settings['target_audience'] ?? $analysis['likely_audience'] ?? '')),
                'angle' => trim((string) ($settings['unique_angle'] ?? 'Expand one supporting angle from the source without copying its structure.')),
                'key_points' => collect((array) ($analysis['content_gaps'] ?? []))->take(3)->values()->all(),
                'cta' => trim((string) ($settings['cta'] ?? '')),
                'suggested_internal_links' => ['pillar'],
                'status' => 'proposed',
                'internal_link_to' => $index === 0 ? 'pillar' : 'pillar + adjacent supporting articles',
            ])
            : collect([
                ['title' => 'What ' . Str::lower($topic) . ' means in practice', 'primary_keyword' => $topic, 'internal_link_to' => 'pillar'],
                ['title' => 'Common mistakes in ' . Str::lower($topic), 'primary_keyword' => $topic . ' mistakes', 'internal_link_to' => 'pillar'],
                ['title' => 'How to evaluate ' . Str::lower($topic) . ' for your team', 'primary_keyword' => $topic . ' evaluation', 'internal_link_to' => 'pillar'],
            ]);
        $supportingTopics = $supportingTopics
            ->map(function (array $row, int $index) use ($topic, $itemTypes, $settings, $analysis): array {
                return [
                    'title' => (string) ($row['title'] ?? ''),
                    'content_type' => (string) (($row['content_type'] ?? $itemTypes->get($index + 1)) ?: 'supporting_blog'),
                    'primary_keyword' => (string) ($row['primary_keyword'] ?? $row['title'] ?? ''),
                    'secondary_keywords' => (array) ($row['secondary_keywords'] ?? collect([$topic])->filter()->values()->all()),
                    'search_intent' => trim((string) ($row['search_intent'] ?? $settings['search_intent'] ?? $analysis['search_intent'] ?? 'informational')),
                    'funnel_stage' => trim((string) ($row['funnel_stage'] ?? $settings['funnel_stage'] ?? $analysis['funnel_stage'] ?? 'awareness')),
                    'target_audience' => trim((string) ($row['target_audience'] ?? $settings['target_audience'] ?? $analysis['likely_audience'] ?? '')),
                    'angle' => trim((string) ($row['angle'] ?? $settings['unique_angle'] ?? 'Create a differentiated supporting article for the chain.')),
                    'key_points' => (array) ($row['key_points'] ?? collect((array) ($analysis['content_gaps'] ?? []))->take(3)->values()->all()),
                    'cta' => trim((string) ($row['cta'] ?? $settings['cta'] ?? '')),
                    'suggested_internal_links' => (array) ($row['suggested_internal_links'] ?? ['pillar']),
                    'status' => (string) ($row['status'] ?? 'proposed'),
                    'internal_link_to' => (string) ($row['internal_link_to'] ?? 'pillar'),
                ];
            })
            ->values();

        $differentiators = collect((array) ($analysis['suggested_differentiators'] ?? []))
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->take(3)
            ->values();
        $pillarItem = [
            'title' => trim((string) ($settings['title'] ?? 'Pillar: ' . ($topic !== '' ? $topic : 'Original content chain'))),
            'content_type' => (string) ($itemTypes->first() ?: 'cornerstone_article'),
            'primary_keyword' => trim((string) ($settings['primary_keyword'] ?? $analysis['primary_keyword'] ?? $topic)),
            'secondary_keywords' => $secondary->take(5)->values()->all(),
            'search_intent' => trim((string) ($settings['search_intent'] ?? $analysis['search_intent'] ?? 'informational')),
            'funnel_stage' => trim((string) ($settings['funnel_stage'] ?? $analysis['funnel_stage'] ?? 'awareness')),
            'target_audience' => trim((string) ($settings['target_audience'] ?? $analysis['likely_audience'] ?? '')),
            'angle' => trim((string) ($settings['unique_angle'] ?? 'Create the central point of view for the chain.')),
            'key_points' => collect((array) ($analysis['key_claims'] ?? []))->merge((array) ($analysis['content_gaps'] ?? []))->take(5)->values()->all(),
            'cta' => trim((string) ($settings['cta'] ?? '')),
            'suggested_internal_links' => ['supporting items'],
            'status' => 'proposed',
        ];
        $proposalItems = collect([$pillarItem])
            ->merge($supportingTopics)
            ->pipe(function (Collection $items) use ($targetCount, $topic, $settings, $analysis): Collection {
                $fallbacks = collect([
                    'FAQ: questions buyers ask about ' . Str::lower($topic ?: 'this topic'),
                    'Comparison guide for ' . Str::lower($topic ?: 'this topic'),
                    'Implementation checklist for ' . Str::lower($topic ?: 'this topic'),
                    'LinkedIn post: the core lesson from ' . Str::lower($topic ?: 'this source'),
                    'Newsletter angle for ' . Str::lower($topic ?: 'this topic'),
                ]);

                while ($items->count() < $targetCount && $fallbacks->isNotEmpty()) {
                    $title = (string) $fallbacks->shift();
                    $items->push([
                        'title' => $title,
                        'content_type' => str_starts_with($title, 'FAQ') ? 'faq_article' : 'supporting_blog',
                        'primary_keyword' => $topic !== '' ? $topic : $title,
                        'secondary_keywords' => collect([$topic])->filter()->values()->all(),
                        'search_intent' => trim((string) ($settings['search_intent'] ?? $analysis['search_intent'] ?? 'informational')),
                        'funnel_stage' => trim((string) ($settings['funnel_stage'] ?? $analysis['funnel_stage'] ?? 'awareness')),
                        'target_audience' => trim((string) ($settings['target_audience'] ?? $analysis['likely_audience'] ?? '')),
                        'angle' => trim((string) ($settings['unique_angle'] ?? 'Add a supporting angle to complete the requested chain size.')),
                        'key_points' => collect((array) ($analysis['content_gaps'] ?? []))->take(3)->values()->all(),
                        'cta' => trim((string) ($settings['cta'] ?? '')),
                        'suggested_internal_links' => ['pillar'],
                        'status' => 'proposed',
                    ]);
                }

                return $items;
            })
            ->take($targetCount)
            ->values()
            ->map(function (array $row, int $index): array {
                $row['order'] = $index + 1;

                return $row;
            })
            ->all();

        return [
            'mode' => (string) ($source->generation_output_mode ?: 'brief_chain'),
            'proposal_only' => (string) ($source->generation_output_mode ?: '') !== 'full_chain',
            'pillar_topic' => $topic !== '' ? $topic : 'Original pillar article opportunity',
            'supporting_subtopics' => $supportingTopics->take(max(1, $targetCount - 1))->values()->all(),
            'proposal_items' => $proposalItems,
            'recommended_order' => range(1, max(1, count($proposalItems))),
            'internal_linking_notes' => [
                'Link each supporting article back to the pillar using audience-intent anchors.',
                'Cross-link adjacent supporting topics where the user journey overlaps.',
                $differentiators->isNotEmpty()
                    ? 'Use brand differentiators in the pillar and repeat them selectively in supporting articles.'
                    : 'Add a distinct brand point of view in the pillar before scaling supporting articles.',
            ],
            'source_fit' => count((array) ($analysis['content_gaps'] ?? [])) >= 2
                ? 'Best used as pillar inspiration with differentiated supporting coverage.'
                : 'Best used as supporting-topic inspiration inside a broader original pillar strategy.',
            'source_context' => [
                'source_title' => (string) ($source->source_title ?? ''),
                'source_summary' => (string) data_get($source->metadata_json, 'extraction.summary', ''),
                'detected_topic' => (string) ($analysis['main_topic'] ?? ''),
                'detected_keywords' => $secondary->values()->all(),
                'recommended_structure' => collect((array) ($analysis['content_gaps'] ?? []))->values()->all(),
                'extraction_metadata' => (array) data_get($source->metadata_json, 'extraction', []),
            ],
        ];
    }

    private function supportingTitle(string $topic, string $keyword, int $index): string
    {
        $patterns = [
            'How ' . Str::lower($keyword) . ' supports ' . Str::lower($topic),
            Str::title($keyword) . ' mistakes teams should avoid',
            'Questions to answer before investing in ' . Str::lower($keyword),
            'A practical framework for ' . Str::lower($keyword),
        ];

        return $patterns[$index % count($patterns)];
    }
}
