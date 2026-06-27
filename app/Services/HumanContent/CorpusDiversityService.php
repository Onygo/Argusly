<?php

namespace App\Services\HumanContent;

use App\Models\Brief;
use App\Models\Draft;
use Illuminate\Support\Str;

class CorpusDiversityService
{
    public const VERSION = 'corpus-diversity.v1';

    /**
     * @return array<string,mixed>
     */
    public function analyzeDraft(Draft $draft, string $html, ?string $title = null): array
    {
        $draft->loadMissing(['brief', 'content.workspace', 'clientSite.workspace']);

        $workspace = $draft->content?->workspace ?: $draft->clientSite?->workspace;
        $topic = (string) ($draft->brief?->primary_keyword ?: $draft->brief?->title ?: $title ?: $draft->title);

        return $this->analyze(
            html: $html,
            title: (string) ($title ?: $draft->title),
            comparisonDocuments: $this->recentRelatedDocuments(
                workspaceId: $workspace?->id ? (string) $workspace->id : null,
                topic: $topic,
                excludeContentId: $draft->content_id ? (string) $draft->content_id : null,
                excludeDraftId: $draft->id ? (string) $draft->id : null,
            ),
        );
    }

    /**
     * @param array<int,array<string,mixed>> $comparisonDocuments
     * @return array<string,mixed>
     */
    public function analyze(string $html, string $title, array $comparisonDocuments = []): array
    {
        $documents = collect($comparisonDocuments)
            ->filter(fn (array $document): bool => trim((string) ($document['html'] ?? $document['content_html'] ?? '')) !== '')
            ->take($this->recentLimit())
            ->values();

        if ($documents->isEmpty()) {
            return $this->emptyResult();
        }

        $current = $this->features($html, $title);
        $dimensionMax = $this->emptyDimensionScores();
        $findings = [];

        foreach ($documents as $document) {
            $other = $this->features(
                (string) ($document['html'] ?? $document['content_html'] ?? ''),
                (string) ($document['title'] ?? ''),
            );

            $scores = $this->compareFeatures($current, $other);
            foreach ($scores as $dimension => $score) {
                $dimensionMax[$dimension] = max($dimensionMax[$dimension], $score);
                if ($score >= $this->similarityThreshold()) {
                    $findings[] = $this->finding($dimension, $score, $document, $current, $other);
                }
            }
        }

        $findings = collect($findings)
            ->sortByDesc('similarity')
            ->unique(fn (array $finding): string => (string) $finding['type'] . '|' . (string) $finding['comparison_title'])
            ->take(8)
            ->values()
            ->all();

        $riskScore = $this->weightedRisk($dimensionMax);
        $penalty = min($this->penaltyMax(), (int) round(($riskScore / 100) * $this->penaltyMax()));
        $recommendations = $this->recommendations($findings, $dimensionMax);

        return [
            'version' => self::VERSION,
            'status' => $riskScore >= $this->similarityThreshold() ? 'review' : 'pass',
            'score' => max(0, 100 - $riskScore),
            'risk_score' => $riskScore,
            'max_similarity' => max($dimensionMax),
            'penalty' => $penalty,
            'compared_documents_count' => $documents->count(),
            'dimension_scores' => $dimensionMax,
            'findings' => $findings,
            'recommendations' => $recommendations,
            'humanization_actions' => collect($findings)
                ->pluck('humanization_action')
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * @param array<string,mixed> $draftMeta
     * @param array<int,array<string,string>> $previousArticles
     * @return array<string,mixed>
     */
    public function planningGuidance(Brief $brief, array $draftMeta = [], array $previousArticles = []): array
    {
        if ($brief->exists) {
            $brief->loadMissing(['clientSite.workspace']);
        }

        $workspace = $brief->clientSite?->workspace;
        $topic = trim((string) ($brief->primary_keyword ?: $brief->title ?: ($draftMeta['primary_keyword'] ?? '')));
        $documents = $this->recentRelatedDocuments(
            workspaceId: $workspace?->id ? (string) $workspace->id : null,
            topic: $topic,
            excludeContentId: $brief->content_id ? (string) $brief->content_id : null,
        );

        $documents = $documents !== []
            ? $documents
            : collect($previousArticles)->map(fn (array $article): array => [
                'title' => (string) ($article['title'] ?? ''),
                'html' => '',
                'primary_keyword' => (string) ($article['primary_keyword'] ?? ''),
            ])->all();

        if ($documents === []) {
            return [
                'status' => 'insufficient_context',
                'comparison_count' => 0,
                'avoid_repeating' => [],
                'recommendations' => ['Use a distinct editorial movement from the selected pattern rather than a generic SEO article shape.'],
            ];
        }

        $features = collect($documents)
            ->filter(fn (array $document): bool => trim((string) ($document['html'] ?? '')) !== '')
            ->map(fn (array $document): array => $this->features((string) $document['html'], (string) ($document['title'] ?? '')))
            ->values();

        $patterns = $features->pluck('narrative_pattern')->filter()->countBy()->sortDesc()->keys()->take(3)->values()->all();
        $headings = $features->flatMap(fn (array $feature): array => $feature['headings'])->filter()->countBy()->sortDesc()->keys()->take(6)->values()->all();

        $avoid = collect($previousArticles)
            ->merge($documents)
            ->pluck('title')
            ->filter()
            ->unique()
            ->take(5)
            ->map(fn (string $title): string => 'Do not repeat the same angle or section movement as "' . $title . '".')
            ->values()
            ->all();

        return [
            'status' => 'available',
            'comparison_count' => count($documents),
            'common_narrative_patterns' => $patterns,
            'common_headings' => $headings,
            'avoid_repeating' => $avoid,
            'recommendations' => collect([
                $patterns !== [] ? 'Choose a different narrative movement than recent related articles: ' . implode(', ', $patterns) . '.' : null,
                $headings !== [] ? 'Use fresh, topic-specific headings instead of recurring corpus headings: ' . implode('; ', $headings) . '.' : null,
                'Vary opening frame, section rhythm, examples, argument order, and CTA language against recent workspace content.',
            ])->filter()->values()->all(),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recentRelatedDocuments(?string $workspaceId, string $topic, ?string $excludeContentId = null, ?string $excludeDraftId = null): array
    {
        if (! $workspaceId) {
            return [];
        }

        $terms = $this->terms($topic, 6);
        $limit = $this->recentLimit();
        $lookback = now()->subDays($this->lookbackDays());

        $drafts = Draft::query()
            ->with(['content:id,workspace_id,title,primary_keyword'])
            ->whereNotNull('content_html')
            ->where('content_html', '!=', '')
            ->where('updated_at', '>=', $lookback)
            ->whereHas('content', fn ($query) => $query->where('workspace_id', $workspaceId))
            ->when($excludeDraftId, fn ($query) => $query->where('id', '!=', $excludeDraftId))
            ->when($excludeContentId, fn ($query) => $query->where('content_id', '!=', $excludeContentId))
            ->latest('updated_at')
            ->limit($limit * 4)
            ->get(['id', 'content_id', 'title', 'content_html', 'updated_at']);

        $related = $drafts->filter(function (Draft $draft) use ($terms): bool {
            if ($terms === []) {
                return true;
            }

            $haystack = Str::lower(trim((string) $draft->title . ' ' . (string) $draft->content?->title . ' ' . (string) $draft->content?->primary_keyword));

            return collect($terms)->contains(fn (string $term): bool => str_contains($haystack, $term));
        });

        if ($related->isEmpty()) {
            $related = $drafts->take($limit);
        }

        return $related
            ->take($limit)
            ->map(fn (Draft $draft): array => [
                'draft_id' => (string) $draft->id,
                'content_id' => (string) $draft->content_id,
                'title' => (string) ($draft->title ?: $draft->content?->title),
                'primary_keyword' => (string) ($draft->content?->primary_keyword ?? ''),
                'html' => (string) $draft->content_html,
                'updated_at' => optional($draft->updated_at)->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyResult(): array
    {
        return [
            'version' => self::VERSION,
            'status' => 'pass',
            'score' => 100,
            'risk_score' => 0,
            'max_similarity' => 0,
            'penalty' => 0,
            'compared_documents_count' => 0,
            'dimension_scores' => $this->emptyDimensionScores(),
            'findings' => [],
            'recommendations' => [],
            'humanization_actions' => [],
        ];
    }

    /**
     * @return array<string,int>
     */
    private function emptyDimensionScores(): array
    {
        return [
            'heading_similarity' => 0,
            'opening_similarity' => 0,
            'ending_similarity' => 0,
            'narrative_pattern_similarity' => 0,
            'structure_similarity' => 0,
            'section_count_similarity' => 0,
            'paragraph_rhythm_similarity' => 0,
            'example_similarity' => 0,
            'argument_similarity' => 0,
            'cta_similarity' => 0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function features(string $html, string $title): array
    {
        $headings = $this->headings($html);
        $paragraphs = $this->paragraphs($html);
        $text = $this->normalize(strip_tags($html));

        return [
            'title' => $title,
            'headings' => $headings,
            'heading_text' => implode(' ', $headings),
            'opening' => (string) ($paragraphs[0] ?? Str::limit($text, 450, '')),
            'ending' => (string) ($paragraphs[count($paragraphs) - 1] ?? ''),
            'narrative_pattern' => $this->narrativePattern($headings, $text),
            'structure' => $this->structureSignature($html),
            'section_count' => count($headings),
            'paragraph_lengths' => collect($paragraphs)->map(fn (string $paragraph): int => str_word_count($paragraph))->values()->all(),
            'examples' => implode(' ', $this->sentencesMatching($text, '/\b(for example|for instance|case|scenario|in practice|such as|bijvoorbeeld|praktijk|casus)\b/i')),
            'argument' => implode(' ', $this->terms($title . ' ' . implode(' ', array_slice($paragraphs, 0, 2)), 12)),
            'cta' => $this->ctaText($paragraphs),
        ];
    }

    /**
     * @param array<string,mixed> $current
     * @param array<string,mixed> $other
     * @return array<string,int>
     */
    private function compareFeatures(array $current, array $other): array
    {
        return [
            'heading_similarity' => $this->textSimilarity((string) $current['heading_text'], (string) $other['heading_text']),
            'opening_similarity' => $this->textSimilarity((string) $current['opening'], (string) $other['opening']),
            'ending_similarity' => $this->textSimilarity((string) $current['ending'], (string) $other['ending']),
            'narrative_pattern_similarity' => $current['narrative_pattern'] === $other['narrative_pattern'] ? 100 : 0,
            'structure_similarity' => $this->sequenceSimilarity((string) $current['structure'], (string) $other['structure']),
            'section_count_similarity' => $this->countSimilarity((int) $current['section_count'], (int) $other['section_count']),
            'paragraph_rhythm_similarity' => $this->rhythmSimilarity((array) $current['paragraph_lengths'], (array) $other['paragraph_lengths']),
            'example_similarity' => $this->textSimilarity((string) $current['examples'], (string) $other['examples']),
            'argument_similarity' => $this->textSimilarity((string) $current['argument'], (string) $other['argument']),
            'cta_similarity' => $this->textSimilarity((string) $current['cta'], (string) $other['cta']),
        ];
    }

    /**
     * @param array<string,int> $dimensionMax
     */
    private function weightedRisk(array $dimensionMax): int
    {
        $weights = [
            'heading_similarity' => 1.2,
            'opening_similarity' => 1.1,
            'ending_similarity' => 0.9,
            'narrative_pattern_similarity' => 0.8,
            'structure_similarity' => 1.3,
            'section_count_similarity' => 0.5,
            'paragraph_rhythm_similarity' => 1.0,
            'example_similarity' => 0.8,
            'argument_similarity' => 1.0,
            'cta_similarity' => 0.7,
        ];

        $weighted = 0.0;
        $total = 0.0;
        foreach ($dimensionMax as $dimension => $score) {
            $weight = $weights[$dimension] ?? 1.0;
            $weighted += $score * $weight;
            $total += $weight;
        }

        return (int) round($total > 0 ? $weighted / $total : 0);
    }

    /**
     * @param array<string,mixed> $document
     * @param array<string,mixed> $current
     * @param array<string,mixed> $other
     * @return array<string,mixed>
     */
    private function finding(string $dimension, int $score, array $document, array $current, array $other): array
    {
        $label = Str::of($dimension)->replace('_', ' ')->headline()->lower()->value();

        return [
            'type' => $dimension,
            'severity' => $score >= $this->highSimilarityThreshold() ? 'high' : 'medium',
            'similarity' => $score,
            'message' => "The draft shows {$label} against recent related workspace content.",
            'comparison_title' => (string) ($document['title'] ?? 'Related content'),
            'comparison_content_id' => (string) ($document['content_id'] ?? ''),
            'comparison_draft_id' => (string) ($document['draft_id'] ?? ''),
            'evidence' => $this->findingEvidence($dimension, $current, $other),
            'recommendation' => $this->recommendationFor($dimension),
            'humanization_action' => $this->humanizationActionFor($dimension),
        ];
    }

    /**
     * @param array<string,mixed> $current
     * @param array<string,mixed> $other
     */
    private function findingEvidence(string $dimension, array $current, array $other): string
    {
        return match ($dimension) {
            'heading_similarity' => 'Current headings echo: ' . Str::limit((string) $other['heading_text'], 140, ''),
            'opening_similarity' => 'Opening frame resembles: ' . Str::limit((string) $other['opening'], 140, ''),
            'ending_similarity' => 'Ending resembles: ' . Str::limit((string) $other['ending'], 140, ''),
            'narrative_pattern_similarity' => 'Both articles use the ' . (string) $current['narrative_pattern'] . ' movement.',
            'structure_similarity' => 'HTML section/list/paragraph sequence is materially similar.',
            'section_count_similarity' => 'Both drafts use a similar number of editorial sections.',
            'paragraph_rhythm_similarity' => 'Paragraph length pattern is materially similar.',
            'example_similarity' => 'Examples or scenarios overlap in wording or setup.',
            'argument_similarity' => 'The argument uses a similar term cluster.',
            'cta_similarity' => 'The closing action or CTA language is similar.',
            default => '',
        };
    }

    private function recommendationFor(string $dimension): string
    {
        return match ($dimension) {
            'heading_similarity' => 'Rename sections around this article-specific argument, not the recurring corpus labels.',
            'opening_similarity' => 'Open from a different reader tension, field observation, or decision moment.',
            'ending_similarity', 'cta_similarity' => 'Close with a different practical implication and CTA phrasing.',
            'narrative_pattern_similarity', 'structure_similarity', 'section_count_similarity' => 'Change the editorial movement, section order, and pacing before publication.',
            'paragraph_rhythm_similarity' => 'Vary paragraph length, list placement, and explanation-to-example pacing.',
            'example_similarity' => 'Swap in a different example, scenario, role, or business constraint.',
            'argument_similarity' => 'Reorder the argument and add a distinct counterpoint or decision criterion.',
            default => 'Differentiate this draft from recent workspace content.',
        };
    }

    private function humanizationActionFor(string $dimension): string
    {
        return match ($dimension) {
            'heading_similarity', 'structure_similarity', 'narrative_pattern_similarity', 'section_count_similarity' => 'Reshape headings and section movement to avoid corpus repetition.',
            'opening_similarity' => 'Rewrite the opening around a different reader tension.',
            'ending_similarity', 'cta_similarity' => 'Rewrite the ending and CTA with a more specific next step.',
            'paragraph_rhythm_similarity' => 'Vary paragraph rhythm and list placement.',
            'example_similarity' => 'Replace overlapping examples with a distinct scenario.',
            'argument_similarity' => 'Add a different counterargument or decision criterion.',
            default => 'Differentiate the draft from recent related content.',
        };
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,int> $dimensionMax
     * @return array<int,string>
     */
    private function recommendations(array $findings, array $dimensionMax): array
    {
        return collect([
            $dimensionMax['structure_similarity'] >= $this->similarityThreshold() ? 'Change the article movement before publishing; structure similarity is the strongest corpus risk.' : null,
            $dimensionMax['heading_similarity'] >= $this->similarityThreshold() ? 'Replace recurring headings with specific editorial claims.' : null,
            $dimensionMax['paragraph_rhythm_similarity'] >= $this->similarityThreshold() ? 'Vary paragraph rhythm and list placement against recent drafts.' : null,
            ...collect($findings)->pluck('recommendation')->all(),
        ])->filter()->unique()->take(6)->values()->all();
    }

    /**
     * @return array<int,string>
     */
    private function headings(string $html): array
    {
        preg_match_all('/<h[1-6]\b[^>]*>(.*?)<\/h[1-6]>/is', $html, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $heading): string => $this->normalize(strip_tags($heading)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function paragraphs(string $html): array
    {
        preg_match_all('/<p\b[^>]*>(.*?)<\/p>/is', $html, $matches);
        $paragraphs = collect($matches[1] ?? [])
            ->map(fn (string $paragraph): string => $this->normalize(strip_tags($paragraph)))
            ->filter()
            ->values();

        return $paragraphs->isNotEmpty() ? $paragraphs->all() : [$this->normalize(strip_tags($html))];
    }

    private function structureSignature(string $html): string
    {
        preg_match_all('/<(h[1-6]|p|ul|ol|blockquote|table)\b/i', $html, $matches);

        return implode(' ', array_map('strtolower', $matches[1] ?? []));
    }

    /**
     * @param array<int,string> $headings
     */
    private function narrativePattern(array $headings, string $text): string
    {
        $haystack = Str::lower(implode(' ', $headings) . ' ' . Str::limit($text, 1200, ''));

        return match (true) {
            str_contains($haystack, ' vs ') || str_contains($haystack, 'compare') || str_contains($haystack, 'comparison') => 'comparison',
            preg_match('/\b(202[0-9]|timeline|phase|roadmap|history|evolution)\b/i', $haystack) === 1 => 'timeline',
            preg_match('/\b(myth|misconception|reality|truth)\b/i', $haystack) === 1 => 'myth_to_reality',
            preg_match('/\b(case study|case|example|observed|field)\b/i', $haystack) === 1 => 'field_observation',
            preg_match('/\b(decide|decision|criteria|checklist|choose)\b/i', $haystack) === 1 => 'decision_guide',
            substr_count($haystack, '?') >= 2 => 'question_driven',
            preg_match('/\b(problem|risk|challenge).*\b(solution|discovery|answer)\b/i', $haystack) === 1 => 'problem_to_discovery',
            default => 'framework_analysis',
        };
    }

    /**
     * @return array<int,string>
     */
    private function sentencesMatching(string $text, string $pattern): array
    {
        return collect(preg_split('/(?<=[.!?])\s+/', $text) ?: [])
            ->map(fn (string $sentence): string => trim($sentence))
            ->filter(fn (string $sentence): bool => preg_match($pattern, $sentence) === 1)
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @param array<int,string> $paragraphs
     */
    private function ctaText(array $paragraphs): string
    {
        return collect(array_reverse($paragraphs))
            ->first(fn (string $paragraph): bool => preg_match('/\b(contact|demo|learn more|book|download|subscribe|start|next step|plan|schedule|neem contact|demo|download)\b/i', $paragraph) === 1)
            ?? (string) ($paragraphs[count($paragraphs) - 1] ?? '');
    }

    private function textSimilarity(string $a, string $b): int
    {
        $a = $this->normalizeForComparison($a);
        $b = $this->normalizeForComparison($b);
        if ($a === '' || $b === '') {
            return 0;
        }

        similar_text($a, $b, $percent);
        $tokenScore = $this->tokenOverlap($a, $b);

        return (int) round(max($percent, $tokenScore));
    }

    private function sequenceSimilarity(string $a, string $b): int
    {
        if ($a === '' || $b === '') {
            return 0;
        }

        similar_text($a, $b, $percent);

        return (int) round($percent);
    }

    private function countSimilarity(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return max(0, 100 - abs($a - $b) * 20);
    }

    /**
     * @param array<int,int> $a
     * @param array<int,int> $b
     */
    private function rhythmSimilarity(array $a, array $b): int
    {
        if ($a === [] || $b === []) {
            return 0;
        }

        $limit = min(count($a), count($b), 8);
        $diff = 0;
        for ($i = 0; $i < $limit; $i++) {
            $diff += abs(($a[$i] ?? 0) - ($b[$i] ?? 0));
        }

        $averageDiff = $diff / max(1, $limit);
        $countPenalty = abs(count($a) - count($b)) * 8;

        return max(0, min(100, (int) round(100 - $averageDiff * 2 - $countPenalty)));
    }

    private function tokenOverlap(string $a, string $b): int
    {
        $left = collect(explode(' ', $a))->filter(fn (string $term): bool => mb_strlen($term) >= 5)->unique();
        $right = collect(explode(' ', $b))->filter(fn (string $term): bool => mb_strlen($term) >= 5)->unique();
        if ($left->isEmpty() || $right->isEmpty()) {
            return 0;
        }

        $intersection = $left->intersect($right)->count();
        $union = $left->merge($right)->unique()->count();

        return (int) round(($intersection / max(1, $union)) * 100);
    }

    /**
     * @return array<int,string>
     */
    private function terms(string $value, int $limit): array
    {
        return collect(explode(' ', $this->normalizeForComparison($value)))
            ->map(fn (string $term): string => trim($term))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 4)
            ->reject(fn (string $term): bool => in_array($term, ['with', 'that', 'this', 'from', 'into', 'when', 'then', 'they', 'your', 'about', 'voor', 'naar', 'door', 'zijn', 'haar', 'deze', 'niet'], true))
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    private function normalizeForComparison(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9\s]+/i', ' ')
            ->squish()
            ->value();
    }

    private function normalize(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }

    private function recentLimit(): int
    {
        return max(1, (int) config('human_content.corpus_diversity.recent_limit', 8));
    }

    private function lookbackDays(): int
    {
        return max(1, (int) config('human_content.corpus_diversity.lookback_days', 180));
    }

    private function similarityThreshold(): int
    {
        return max(1, min(100, (int) config('human_content.corpus_diversity.similarity_threshold', 62)));
    }

    private function highSimilarityThreshold(): int
    {
        return max($this->similarityThreshold(), min(100, (int) config('human_content.corpus_diversity.high_similarity_threshold', 78)));
    }

    private function penaltyMax(): int
    {
        return max(0, (int) config('human_content.corpus_diversity.penalty_max', 24));
    }
}
