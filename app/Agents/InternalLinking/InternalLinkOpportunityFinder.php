<?php

namespace App\Agents\InternalLinking;

use App\Models\Content;
use App\Services\Content\ContentGraphService;
use Illuminate\Support\Str;

class InternalLinkOpportunityFinder
{
    public function __construct(
        private readonly ContentGraphService $contentGraphService,
        private readonly AnchorSuggestionService $anchorSuggestionService,
    ) {
    }

    /**
     * @param array<string,mixed> $input
     * @return array<int,array<string,mixed>>
     */
    public function find(array $input): array
    {
        $source = $input['content'] ?? null;
        if (! $source instanceof Content) {
            return [];
        }

        $sourceLocale = Str::lower((string) ($input['source_locale'] ?? $source->localeCode()));
        $candidates = $this->contentGraphService->linkableCandidatesFor(
            $source,
            $sourceLocale,
            excludeUrls: (array) ($input['existing_link_urls'] ?? []),
        );

        $sourceTokens = $this->tokens(implode(' ', array_filter([
            (string) ($input['source_title'] ?? ''),
            (string) ($input['source_keyword'] ?? ''),
            implode(' ', (array) ($input['headings'] ?? [])),
        ])));
        $headingTokens = $this->tokens(implode(' ', (array) ($input['headings'] ?? [])));
        $usedAnchors = [];
        $seenTargets = [];
        $opportunities = [];

        foreach ($candidates as $candidate) {
            /** @var Content $target */
            $target = $candidate['content'];
            $targetId = (string) $target->id;

            if (in_array($targetId, $seenTargets, true)) {
                continue;
            }

            $anchor = $this->anchorSuggestionService->suggest($input, $target);
            if ($anchor === null) {
                continue;
            }

            $anchorText = trim((string) $anchor['anchor_text']);
            $normalizedAnchor = Str::lower($anchorText);
            if ($anchorText === '' || in_array($normalizedAnchor, $usedAnchors, true)) {
                continue;
            }

            $candidateTokens = $this->tokens(implode(' ', array_filter([
                (string) $target->title,
                (string) ($target->primary_keyword ?? ''),
            ])));
            $similarityScore = $this->overlapScore($sourceTokens, $candidateTokens);
            $headingOverlap = $this->overlapScore($headingTokens, $candidateTokens);
            $freshnessBoost = $this->freshnessBoost($target);
            $relationshipBoost = $this->relationshipBoost((string) $candidate['relationship']);
            $confidence = round(min(0.99, 0.35 + ($similarityScore * 0.35) + ($headingOverlap * 0.15) + $freshnessBoost + $relationshipBoost), 2);

            $opportunities[] = [
                'key' => substr(sha1($targetId . '|' . $anchorText . '|' . (string) $candidate['target_url']), 0, 16),
                'anchor_text' => $anchorText,
                'target_content_id' => $targetId,
                'target_title' => (string) $target->title,
                'target_url' => (string) $candidate['target_url'],
                'confidence_score' => $confidence,
                'reason' => $this->reason(
                    target: $target,
                    relationship: (string) $candidate['relationship'],
                    similarityScore: $similarityScore,
                    headingOverlap: $headingOverlap,
                ),
                'insertion_hint' => $anchor['insertion_hint'],
                'first_anchor_position' => (int) $anchor['first_position'],
            ];

            $usedAnchors[] = $normalizedAnchor;
            $seenTargets[] = $targetId;
        }

        usort($opportunities, function (array $left, array $right): int {
            $confidenceComparison = ((float) $right['confidence_score']) <=> ((float) $left['confidence_score']);
            if ($confidenceComparison !== 0) {
                return $confidenceComparison;
            }

            $positionComparison = ((int) $left['first_anchor_position']) <=> ((int) $right['first_anchor_position']);
            if ($positionComparison !== 0) {
                return $positionComparison;
            }

            return strcasecmp((string) $left['target_title'], (string) $right['target_title']);
        });

        $limit = min(4, max(1, (int) config('internal_linking.max_links_per_article', 4)));

        return collect($opportunities)
            ->take($limit)
            ->map(function (array $opportunity): array {
                unset($opportunity['first_anchor_position']);

                return $opportunity;
            })
            ->values()
            ->all();
    }

    private function relationshipBoost(string $relationship): float
    {
        return match ($relationship) {
            'same_chain_pillar' => 0.18,
            'same_chain_supporting' => 0.14,
            'same_chain_related' => 0.1,
            default => 0.04,
        };
    }

    private function freshnessBoost(Content $content): float
    {
        $reference = $content->updated_at ?: $content->created_at;
        if (! $reference) {
            return 0.0;
        }

        $ageInDays = now()->diffInDays($reference);

        return match (true) {
            $ageInDays <= 60 => 0.08,
            $ageInDays <= 180 => 0.05,
            $ageInDays <= 365 => 0.02,
            default => 0.0,
        };
    }

    private function reason(Content $target, string $relationship, float $similarityScore, float $headingOverlap): string
    {
        $signals = [];

        if ($relationship !== 'topic_related') {
            $signals[] = str_replace('_', ' ', $relationship);
        }

        if ($headingOverlap > 0) {
            $signals[] = 'heading overlap';
        }

        if ($similarityScore >= 0.2) {
            $signals[] = 'topic similarity';
        }

        if ($signals === []) {
            $signals[] = 'same site and locale relevance';
        }

        return sprintf(
            'Matches %s and points to "%s".',
            implode(', ', $signals),
            $target->title
        );
    }

    /**
     * @param array<int,string> $left
     * @param array<int,string> $right
     */
    private function overlapScore(array $left, array $right): float
    {
        if ($left === [] || $right === []) {
            return 0.0;
        }

        $overlap = count(array_intersect($left, $right));
        $union = count(array_unique([...$left, ...$right]));

        if ($union === 0) {
            return 0.0;
        }

        return round($overlap / $union, 4);
    }

    /**
     * @return array<int,string>
     */
    private function tokens(string $value): array
    {
        return collect(preg_split('/[^[:alnum:]]+/u', Str::lower($value)) ?: [])
            ->map(fn ($token): string => trim((string) $token))
            ->filter(fn (string $token): bool => mb_strlen($token) >= 3)
            ->reject(fn (string $token): bool => in_array($token, ['the', 'and', 'for', 'with', 'from', 'that', 'this'], true))
            ->unique()
            ->values()
            ->all();
    }
}
