<?php

namespace App\Services\SourceBriefing;

use App\Models\ContentSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SourceBasedBriefGenerator
{
    /**
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $workspaceContext
     * @param array<string, mixed>|null $chainProposal
     * @return array<string, mixed>
     */
    public function generate(
        ContentSource $source,
        array $analysis,
        array $workspaceContext,
        string $outputMode,
        ?array $chainProposal = null,
    ): array {
        $topic = trim((string) ($analysis['main_topic'] ?? $source->source_title ?? ''));
        $services = collect((array) data_get($workspaceContext, 'company_profile.services', []))->filter()->values();
        $audience = trim((string) ($analysis['likely_audience'] ?? data_get($workspaceContext, 'company_profile.target_audience', '')));
        $differentiators = collect((array) ($analysis['suggested_differentiators'] ?? []))->filter()->values();

        $brief = [
            'working_title' => $this->workingTitle($topic, $services, $audience),
            'summary' => $this->summaryAngle($topic, $audience, $differentiators),
            'primary_keyword' => trim((string) ($analysis['primary_keyword'] ?? '')),
            'secondary_keywords' => $this->normalizeList($analysis['secondary_keywords'] ?? []),
            'target_audience' => $audience !== '' ? $audience : 'Prospects evaluating the topic',
            'search_intent' => trim((string) ($analysis['search_intent'] ?? 'informational')),
            'recommended_structure' => $this->recommendedStructure($topic, $analysis),
            'key_talking_points' => $this->keyTalkingPoints($analysis, $differentiators),
            'recommended_differentiators' => $differentiators->take(5)->values()->all(),
            'cta_recommendation' => $this->ctaRecommendation($workspaceContext, $analysis),
            'things_to_avoid' => [
                'Do not mirror the source article structure section by section.',
                'Do not reuse source phrasing or examples too closely.',
                'Avoid unsupported claims or positioning that conflicts with the workspace context.',
            ],
            'source_inspiration_note' => 'Generated from external source analysis for original, brand-aligned briefing. Use as strategic inspiration, not as copy.',
            'language' => (string) ($source->source_language ?: data_get($workspaceContext, 'workspace.default_language', 'en')),
            'chain_suitability' => $outputMode === 'brief_chain' || count((array) ($analysis['content_gaps'] ?? [])) >= 2,
        ];

        $keywordBlock = [
            'primary_keyword_candidate' => $brief['primary_keyword'],
            'secondary_keywords' => $brief['secondary_keywords'],
            'related_long_tail_phrases' => $this->longTailPhrases($topic, $analysis),
            'entities' => $this->normalizeList($analysis['semantic_entities'] ?? []),
            'faq_opportunities' => $this->faqOpportunities($topic, $analysis),
            'note' => 'These are inferred opportunities from source analysis plus workspace context, not guaranteed SEO outcomes.',
        ];

        return [
            'brief' => $brief,
            'keywords' => $outputMode === 'brief_only' ? null : $keywordBlock,
            'chain_proposal' => $outputMode === 'brief_chain' ? $chainProposal : null,
        ];
    }

    private function workingTitle(string $topic, Collection $services, string $audience): string
    {
        $service = trim((string) $services->first());

        return match (true) {
            $service !== '' && $audience !== '' => Str::limit($topic . ': a practical guide for ' . Str::lower($audience) . ' using ' . $service, 250, ''),
            $audience !== '' => Str::limit($topic . ': a practical guide for ' . Str::lower($audience), 250, ''),
            default => Str::limit($topic !== '' ? $topic : 'Original article brief', 250, ''),
        };
    }

    private function summaryAngle(string $topic, string $audience, Collection $differentiators): string
    {
        $angle = 'Create an original article that explains ' . Str::lower($topic ?: 'the topic') . ' in a clearer, more actionable way.';

        if ($audience !== '') {
            $angle .= ' Tailor the angle to ' . Str::lower($audience) . '.';
        }

        if ($differentiators->isNotEmpty()) {
            $angle .= ' Emphasize ' . Str::lower((string) $differentiators->first()) . ' as a differentiating point of view.';
        }

        return Str::limit($angle, 500, '');
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array<int, string>
     */
    private function recommendedStructure(string $topic, array $analysis): array
    {
        $sections = collect([
            'Direct answer: what ' . Str::lower($topic ?: 'the topic') . ' means',
            'Why it matters now',
            'How to evaluate options or approaches',
            ...((array) ($analysis['content_gaps'] ?? [])),
            'Practical recommendations and next steps',
            'FAQ',
        ]);

        return $sections
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->unique()
            ->take(7)
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array<int, string>
     */
    private function keyTalkingPoints(array $analysis, Collection $differentiators): array
    {
        return collect([
            ...((array) ($analysis['key_claims'] ?? [])),
            ...$differentiators->map(fn (string $item): string => 'Connect the topic to ' . $item)->all(),
        ])->map(fn (string $item): string => trim($item))
            ->filter()
            ->unique()
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $workspaceContext
     * @param array<string, mixed> $analysis
     */
    private function ctaRecommendation(array $workspaceContext, array $analysis): string
    {
        $services = collect((array) data_get($workspaceContext, 'company_profile.services', []))->filter()->values();
        $base = match ((string) ($analysis['cta_style'] ?? '')) {
            'product demo CTA' => 'Offer a low-friction demo or walkthrough tailored to the reader intent.',
            'download CTA' => 'Offer a checklist, template, or downloadable next step.',
            default => 'Use a soft CTA that moves the reader from education to a relevant next step.',
        };

        if ($services->isNotEmpty()) {
            $base .= ' Anchor it to ' . Str::lower((string) $services->first()) . '.';
        }

        return Str::limit($base, 240, '');
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array<int, string>
     */
    private function longTailPhrases(string $topic, array $analysis): array
    {
        $keyword = trim((string) ($analysis['primary_keyword'] ?? $topic));
        $base = Str::lower($keyword);

        return collect([
            'what is ' . $base,
            'how to improve ' . $base,
            $base . ' best practices',
            $base . ' examples',
        ])->unique()->values()->all();
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array<int, string>
     */
    private function faqOpportunities(string $topic, array $analysis): array
    {
        return collect((array) ($analysis['questions_answered'] ?? []))
            ->merge([
                'What is ' . Str::lower($topic) . '?',
                'How should teams approach ' . Str::lower($topic) . '?',
            ])->map(fn (string $item): string => trim($item))
            ->filter()
            ->unique()
            ->take(6)
            ->values()
            ->all();
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->values()
            ->all();
    }
}
