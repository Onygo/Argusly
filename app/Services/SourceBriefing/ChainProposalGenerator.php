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
        $topic = trim((string) ($analysis['main_topic'] ?? $source->source_title ?? ''));
        $secondary = collect((array) ($analysis['secondary_keywords'] ?? []))
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->take(8)
            ->values();

        $supportingTopics = $secondary->isNotEmpty()
            ? $secondary->map(fn (string $item, int $index): array => [
                'title' => $this->supportingTitle($topic, $item, $index),
                'internal_link_to' => $index === 0 ? 'pillar' : 'pillar + adjacent supporting articles',
            ])
            : collect([
                ['title' => 'What ' . Str::lower($topic) . ' means in practice', 'internal_link_to' => 'pillar'],
                ['title' => 'Common mistakes in ' . Str::lower($topic), 'internal_link_to' => 'pillar'],
                ['title' => 'How to evaluate ' . Str::lower($topic) . ' for your team', 'internal_link_to' => 'pillar'],
            ]);

        $differentiators = collect((array) ($analysis['suggested_differentiators'] ?? []))
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->take(3)
            ->values();

        return [
            'pillar_topic' => $topic !== '' ? $topic : 'Original pillar article opportunity',
            'supporting_subtopics' => $supportingTopics->take(8)->values()->all(),
            'recommended_order' => range(1, max(1, $supportingTopics->take(8)->count())),
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
