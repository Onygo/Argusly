<?php

namespace App\Services\LlmTracking;

use App\Models\LlmTrackingQuery;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class LlmVisibilityScoreCalculator
{
    /**
     * @param array<int,array<string,mixed>> $brandHits
     * @param array<int,array<string,mixed>> $competitorHits
     * @param array<string,mixed> $citationRanking
     * @return array<string,mixed>
     */
    public function calculate(
        LlmTrackingQuery $query,
        string $answerText,
        array $brandHits,
        array $competitorHits,
        array $citationRanking,
        array $sources = [],
        array $detectedDomains = [],
        ?int $firstMentionIndex = null,
        ?string $firstMentionBlock = null,
        ?string $firstMentionContext = null,
        string $provider = '',
        array $providerEvidence = [],
    ): array {
        $brandMentionCount = (int) collect($brandHits)->sum(fn (array $hit): int => (int) ($hit['count'] ?? 0));
        $competitorMentionCount = (int) collect($competitorHits)->sum(fn (array $hit): int => (int) ($hit['count'] ?? 0));

        $presenceScore = $brandMentionCount > 0 ? 1.0 : 0.0;
        $positionScore = $this->positionScore(
            $brandHits,
            $citationRanking,
            $firstMentionIndex,
            $firstMentionBlock,
            max(1, strlen($answerText)),
        );

        [$contextLabel, $contextScore, $contextSignals] = $this->contextForBrandContexts(
            $answerText,
            $brandHits,
            $firstMentionContext,
        );

        $citationParts = $this->citationScores(
            $query,
            $brandMentionCount,
            $sources,
            $detectedDomains,
        );
        $citationScore = $citationParts['legacy_citation_score'];
        $competitorShareScore = $this->competitiveScore($brandMentionCount, $competitorMentionCount);
        $competitorPressureScore = $this->competitorPressureScore($brandMentionCount, $competitorMentionCount);
        $modelConfidenceScore = $this->modelConfidenceScore($answerText, $sources, $brandMentionCount, $competitorMentionCount);
        $realWorldGapScore = $this->realWorldGapScore($provider, $providerEvidence, $brandMentionCount > 0);
        $ownedVisibilityScore = $this->ownedVisibilityScore($presenceScore, $positionScore, $contextScore, $citationParts['owned_citation_score']);
        $earnedVisibilityScore = $this->earnedVisibilityScore($presenceScore, $positionScore, $contextScore, $citationParts['earned_citation_score'], $citationParts['citation_diversity_score']);
        $weights = $this->componentWeights();
        $aiVisibilityScore = round(
            ($ownedVisibilityScore * $weights['owned_visibility'])
            + ($earnedVisibilityScore * $weights['earned_visibility'])
            + ((1 - $competitorPressureScore) * $weights['competitor_pressure'])
            + ($citationParts['citation_diversity_score'] * $weights['citation_diversity'])
            + ($modelConfidenceScore * $weights['model_confidence'])
            + ((1 - $realWorldGapScore) * $weights['real_world_gap']),
            4
        );

        return [
            'detected_brands' => $this->normalizeHits($brandHits, 'brand'),
            'detected_competitors' => $this->normalizeHits($competitorHits, 'competitor'),
            'entity_presence' => $this->buildEntityPresence($query, $brandHits, $competitorHits),
            'presence_score' => $presenceScore,
            'position_score' => $positionScore,
            'citation_score' => $citationScore,
            'context_score' => $contextScore,
            'context_label' => $contextLabel,
            'sentiment_score' => $contextScore,
            'sentiment_label' => $contextLabel,
            'competitive_score' => $competitorShareScore,
            'competitor_share_score' => $competitorShareScore,
            'owned_visibility_score' => $ownedVisibilityScore,
            'earned_visibility_score' => $earnedVisibilityScore,
            'competitor_pressure_score' => $competitorPressureScore,
            'citation_diversity_score' => $citationParts['citation_diversity_score'],
            'model_confidence_score' => $modelConfidenceScore,
            'real_world_gap_score' => $realWorldGapScore,
            'ai_visibility_score' => $aiVisibilityScore,
            'visibility_breakdown' => [
                'weights' => $weights,
                'legacy_weights' => $this->legacyWeights(),
                'presence_score' => $presenceScore,
                'position_score' => $positionScore,
                'citation_score' => $citationScore,
                'context_score' => $contextScore,
                'context_label' => $contextLabel,
                'sentiment_score' => $contextScore,
                'sentiment_label' => $contextLabel,
                'competitive_score' => $competitorShareScore,
                'competitor_share_score' => $competitorShareScore,
                'owned_visibility_score' => $ownedVisibilityScore,
                'earned_visibility_score' => $earnedVisibilityScore,
                'competitor_pressure_score' => $competitorPressureScore,
                'citation_diversity_score' => $citationParts['citation_diversity_score'],
                'model_confidence_score' => $modelConfidenceScore,
                'real_world_gap_score' => $realWorldGapScore,
                'provider' => $provider,
                'provider_evidence' => $providerEvidence,
                'ai_visibility_score' => $aiVisibilityScore,
                'subscores_100' => [
                    'presence' => round($presenceScore * 100, 2),
                    'position' => round($positionScore * 100, 2),
                    'citation' => round($citationScore * 100, 2),
                    'context' => round($contextScore * 100, 2),
                    'competitor_share' => round($competitorShareScore * 100, 2),
                    'owned_visibility' => round($ownedVisibilityScore * 100, 2),
                    'earned_visibility' => round($earnedVisibilityScore * 100, 2),
                    'competitor_pressure' => round($competitorPressureScore * 100, 2),
                    'citation_diversity' => round($citationParts['citation_diversity_score'] * 100, 2),
                    'model_confidence' => round($modelConfidenceScore * 100, 2),
                    'real_world_gap' => round($realWorldGapScore * 100, 2),
                    'final' => round($aiVisibilityScore * 100, 2),
                ],
                'brand_mentions' => $brandMentionCount,
                'competitor_mentions' => $competitorMentionCount,
                'brand_bucket' => $this->resolveBrandBucket($brandHits, $citationRanking),
                'first_mention_index' => $firstMentionIndex,
                'first_mention_block' => $firstMentionBlock,
                'first_mention_context' => $firstMentionContext,
                'detected_domains' => $detectedDomains,
                'source_count' => count($sources),
                'context_signals' => $contextSignals,
                'brand_present' => $presenceScore >= 1.0,
                'missing_visibility' => $presenceScore === 0.0,
                'explainability' => [
                    'presence' => $presenceScore > 0 ? 'Target brand is explicitly mentioned in the answer.' : 'Target brand is missing from the answer.',
                    'position' => $this->positionExplanation($positionScore, $firstMentionBlock, $firstMentionIndex),
                    'citation' => $this->citationExplanation($citationScore, $query, $sources, $detectedDomains),
                    'context' => 'Context around the brand mention is classified as ' . $contextLabel . '.',
                    'competitor_share' => $brandMentionCount + $competitorMentionCount > 0
                        ? 'Brand share is measured against competitors mentioned in the same answer.'
                        : 'No competitor mentions were available for share comparison.',
                    'owned_visibility' => 'Owned visibility combines brand presence, placement, context, and owned-domain citation support.',
                    'earned_visibility' => 'Earned visibility emphasizes third-party citations and diverse non-owned authority signals.',
                    'competitor_pressure' => $competitorPressureScore > 0.7
                        ? 'Other entities dominate the answer relative to the tracked brand.'
                        : 'Other entity pressure is limited in this answer.',
                    'citation_diversity' => 'Citation diversity measures source-domain and source-type breadth instead of only owned citations.',
                    'model_confidence' => 'Model confidence is inferred from answer substance, source support, and entity evidence.',
                    'real_world_gap' => $realWorldGapScore > 0.5
                        ? 'Visibility is not yet reinforced across enough provider evidence.'
                        : 'Provider evidence does not show a major cross-model gap.',
                ],
            ],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $brandHits
     * @param array<int,array<string,mixed>> $competitorHits
     * @return array<int,array<string,mixed>>
     */
    private function buildEntityPresence(LlmTrackingQuery $query, array $brandHits, array $competitorHits): array
    {
        $brandIndex = collect($brandHits)->keyBy(fn (array $hit): string => Str::lower((string) ($hit['term'] ?? '')));
        $competitorIndex = collect($competitorHits)->keyBy(fn (array $hit): string => Str::lower((string) ($hit['term'] ?? '')));

        $entries = [];

        foreach ((array) ($query->brand_terms ?? []) as $termRaw) {
            $term = trim((string) $termRaw);
            if ($term === '') {
                continue;
            }

            $entries[] = $this->presenceEntry($term, 'brand', $brandIndex->get(Str::lower($term)));
        }

        foreach ((array) ($query->competitor_terms ?? []) as $termRaw) {
            $term = trim((string) $termRaw);
            if ($term === '') {
                continue;
            }

            $entries[] = $this->presenceEntry($term, 'competitor', $competitorIndex->get(Str::lower($term)));
        }

        return $entries;
    }

    /**
     * @param array<string,mixed>|null $hit
     * @return array<string,mixed>
     */
    private function presenceEntry(string $term, string $type, ?array $hit): array
    {
        $bucket = $hit['bucket'] ?? null;

        return [
            'term' => $term,
            'type' => $type,
            'present' => $hit !== null,
            'count' => (int) ($hit['count'] ?? 0),
            'position' => $bucket,
            'position_score' => $this->positionScoreFromBucket(is_string($bucket) ? $bucket : null),
            'snippet_context' => (array) ($hit['context_snippets'] ?? []),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $hits
     * @return array<int,array<string,mixed>>
     */
    private function normalizeHits(array $hits, string $type): array
    {
        return collect($hits)
            ->map(function (array $hit) use ($type): array {
                return [
                    'term' => (string) ($hit['term'] ?? ''),
                    'type' => $type,
                    'present' => true,
                    'count' => (int) ($hit['count'] ?? 0),
                    'first_position' => data_get($hit, 'first_position'),
                    'first_sentence_index' => data_get($hit, 'first_sentence_index'),
                    'position' => data_get($hit, 'bucket'),
                    'position_score' => $this->positionScoreFromBucket((string) data_get($hit, 'bucket')),
                    'context_snippets' => (array) data_get($hit, 'context_snippets', []),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<int,array<string,mixed>> $brandHits
     * @param array<string,mixed> $citationRanking
     */
    private function resolveBrandBucket(array $brandHits, array $citationRanking): ?string
    {
        $bucket = data_get($citationRanking, 'brand.bucket');
        if (is_string($bucket) && $bucket !== '') {
            return $bucket;
        }

        /** @var array<string,mixed>|null $first */
        $first = collect($brandHits)
            ->sortBy('first_position')
            ->first();

        $hitBucket = $first['bucket'] ?? null;

        return is_string($hitBucket) && $hitBucket !== '' ? $hitBucket : null;
    }

    private function positionScoreFromBucket(?string $bucket): float
    {
        return match ($bucket) {
            'first' => 1.0,
            'middle' => 0.75,
            'last' => 0.25,
            default => 0.0,
        };
    }

    /**
     * @param array<int,array<string,mixed>> $brandHits
     * @return array{0:string,1:float,2:array<string,mixed>}
     */
    private function contextForBrandContexts(string $answerText, array $brandHits, ?string $firstMentionContext = null): array
    {
        if ($brandHits === []) {
            return ['not_present', 0.0, ['positive_hits' => [], 'negative_hits' => []]];
        }

        $positiveKeywords = (array) config('llm_tracking.analysis.positive_keywords', []);
        $negativeKeywords = (array) config('llm_tracking.analysis.negative_keywords', []);

        $contexts = collect($brandHits)
            ->pluck('context_snippets')
            ->flatten()
            ->map(fn ($value): string => Str::lower((string) $value))
            ->filter()
            ->values();

        if (is_string($firstMentionContext) && trim($firstMentionContext) !== '') {
            $contexts->prepend(Str::lower($firstMentionContext));
        }

        if ($contexts->isEmpty()) {
            $contexts = collect([Str::lower($answerText)]);
        }

        $positiveHits = $this->matchedKeywords($contexts, $positiveKeywords);
        $negativeHits = $this->matchedKeywords($contexts, $negativeKeywords);

        if ($positiveHits !== [] && count($positiveHits) > count($negativeHits)) {
            return ['positive', 1.0, ['positive_hits' => $positiveHits, 'negative_hits' => $negativeHits]];
        }

        if ($negativeHits !== [] && count($negativeHits) >= count($positiveHits)) {
            return ['negative', 0.2, ['positive_hits' => $positiveHits, 'negative_hits' => $negativeHits]];
        }

        return ['neutral', 0.6, ['positive_hits' => $positiveHits, 'negative_hits' => $negativeHits]];
    }

    /**
     * @param Collection<int,string> $contexts
     * @param array<int,string> $keywords
     * @return array<int,string>
     */
    private function matchedKeywords(Collection $contexts, array $keywords): array
    {
        $hits = [];

        foreach ($keywords as $keyword) {
            foreach ($contexts as $context) {
                if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/u', $context) === 1) {
                    $hits[] = $keyword;
                    break;
                }
            }
        }

        return array_values(array_unique($hits));
    }

    private function competitiveScore(int $brandMentions, int $competitorMentions): float
    {
        $denominator = $brandMentions + $competitorMentions;

        if ($denominator <= 0) {
            return 0.0;
        }

        return round($brandMentions / $denominator, 4);
    }

    /**
     * @param array<int,array<string,mixed>> $brandHits
     * @param array<string,mixed> $citationRanking
     */
    private function positionScore(
        array $brandHits,
        array $citationRanking,
        ?int $firstMentionIndex,
        ?string $firstMentionBlock,
        int $answerLength,
    ): float {
        if ($firstMentionBlock === 'block_1') {
            return 1.0;
        }

        if ($firstMentionIndex !== null) {
            $normalized = $answerLength > 0 ? round($firstMentionIndex / $answerLength, 4) : null;

            if ($normalized !== null && $normalized <= 0.5) {
                return 0.75;
            }

            if ($normalized !== null && $normalized <= 0.85) {
                return 0.5;
            }

            if ($normalized !== null) {
                return 0.25;
            }
        }

        return $this->positionScoreFromBucket(
            $this->resolveBrandBucket($brandHits, $citationRanking)
        );
    }

    /**
     * @param array<int,array<string,mixed>> $sources
     * @param array<int,string> $detectedDomains
     */
    private function citationScores(LlmTrackingQuery $query, int $brandMentionCount, array $sources, array $detectedDomains): array
    {
        $targetDomains = collect(array_merge(
            [$query->target_domain],
            (array) ($query->target_urls ?? [])
        ))
            ->map(function ($value): string {
                $value = trim((string) $value);
                if ($value === '') {
                    return '';
                }

                $host = parse_url($value, PHP_URL_HOST);

                return Str::lower(trim(is_string($host) ? $host : $value));
            })
            ->filter()
            ->unique()
            ->values();

        $matchedOwnDomain = $targetDomains->contains(function (string $targetDomain) use ($detectedDomains): bool {
            return collect($detectedDomains)->contains(function (string $domain) use ($targetDomain): bool {
                return $domain === $targetDomain
                    || Str::endsWith($domain, '.' . $targetDomain)
                    || Str::endsWith($targetDomain, '.' . $domain);
            });
        });

        $sourceCount = count($sources);
        $sourceTypes = collect($sources)->pluck('type')->filter()->unique()->count();
        $domainCount = collect($detectedDomains)->filter()->unique()->count();
        $nonOwnedCount = collect($sources)->filter(function (array $source) use ($targetDomains): bool {
            $domain = Str::lower((string) ($source['domain'] ?? ''));
            if ($domain === '') {
                return false;
            }

            return ! $targetDomains->contains(fn (string $targetDomain): bool => $domain === $targetDomain || Str::endsWith($domain, '.' . $targetDomain));
        })->count();

        $ownedCitationScore = $matchedOwnDomain ? 0.75 : 0.0;
        $earnedCitationScore = $brandMentionCount > 0 && $nonOwnedCount > 0
            ? min(1.0, 0.35 + ($nonOwnedCount * 0.18) + min(0.25, $domainCount * 0.06))
            : ($nonOwnedCount > 0 ? 0.25 : 0.0);
        $citationDiversityScore = $sourceCount > 0
            ? min(1.0, ($domainCount * 0.18) + ($sourceTypes * 0.12) + ($nonOwnedCount > 0 ? 0.20 : 0.0))
            : 0.0;

        $legacyCitationScore = match (true) {
            $brandMentionCount > 0 && $matchedOwnDomain && $nonOwnedCount > 0 => 0.85,
            $brandMentionCount > 0 && $matchedOwnDomain => 0.65,
            $brandMentionCount > 0 && $sources !== [] => 0.55,
            $brandMentionCount === 0 && $sources !== [] => 0.20,
            default => 0.0,
        };

        return [
            'legacy_citation_score' => round($legacyCitationScore, 4),
            'owned_citation_score' => round($ownedCitationScore, 4),
            'earned_citation_score' => round($earnedCitationScore, 4),
            'citation_diversity_score' => round($citationDiversityScore, 4),
        ];
    }

    private function ownedVisibilityScore(float $presenceScore, float $positionScore, float $contextScore, float $ownedCitationScore): float
    {
        if ($presenceScore <= 0.0) {
            return 0.0;
        }

        return round(($presenceScore * 0.40) + ($positionScore * 0.25) + ($contextScore * 0.15) + ($ownedCitationScore * 0.20), 4);
    }

    private function earnedVisibilityScore(float $presenceScore, float $positionScore, float $contextScore, float $earnedCitationScore, float $citationDiversityScore): float
    {
        if ($presenceScore <= 0.0) {
            return round(min(0.2, ($earnedCitationScore + $citationDiversityScore) / 4), 4);
        }

        return round(($presenceScore * 0.25) + ($positionScore * 0.15) + ($contextScore * 0.15) + ($earnedCitationScore * 0.30) + ($citationDiversityScore * 0.15), 4);
    }

    private function competitorPressureScore(int $brandMentions, int $competitorMentions): float
    {
        $denominator = $brandMentions + $competitorMentions;
        if ($denominator <= 0) {
            return 0.0;
        }

        return round($competitorMentions / $denominator, 4);
    }

    private function modelConfidenceScore(string $answerText, array $sources, int $brandMentionCount, int $competitorMentionCount): float
    {
        $lengthScore = min(1.0, strlen(trim($answerText)) / 900);
        $sourceScore = min(1.0, count($sources) / 4);
        $entityScore = min(1.0, ($brandMentionCount + $competitorMentionCount) / 4);

        return round(($lengthScore * 0.35) + ($sourceScore * 0.35) + ($entityScore * 0.30), 4);
    }

    private function realWorldGapScore(string $provider, array $providerEvidence, bool $brandPresent): float
    {
        $normalizedProvider = Str::lower(trim($provider));
        $providersSeen = collect((array) ($providerEvidence['providers_seen'] ?? []))->map(fn ($value): string => Str::lower((string) $value))->filter()->unique();
        $providersWithBrand = collect((array) ($providerEvidence['providers_with_brand'] ?? []))->map(fn ($value): string => Str::lower((string) $value))->filter()->unique();

        if ($providersSeen->count() <= 1) {
            return $normalizedProvider === 'openai' && $brandPresent ? 0.65 : 0.45;
        }

        $missingRate = 1 - ($providersWithBrand->count() / max(1, $providersSeen->count()));
        if ($brandPresent && ! $providersWithBrand->contains($normalizedProvider)) {
            $providersWithBrand->push($normalizedProvider);
            $missingRate = 1 - ($providersWithBrand->unique()->count() / max(1, $providersSeen->count()));
        }

        return round(max(0.0, min(1.0, $missingRate)), 4);
    }

    /**
     * @return array<string,float>
     */
    private function componentWeights(): array
    {
        $configured = (array) config('llm_tracking.score.component_weights', []);
        $weights = [
            'owned_visibility' => max(0.0, (float) ($configured['owned_visibility'] ?? 0.18)),
            'earned_visibility' => max(0.0, (float) ($configured['earned_visibility'] ?? 0.24)),
            'competitor_pressure' => max(0.0, (float) ($configured['competitor_pressure'] ?? 0.18)),
            'citation_diversity' => max(0.0, (float) ($configured['citation_diversity'] ?? 0.14)),
            'model_confidence' => max(0.0, (float) ($configured['model_confidence'] ?? 0.12)),
            'real_world_gap' => max(0.0, (float) ($configured['real_world_gap'] ?? 0.14)),
        ];

        $sum = array_sum($weights);
        if ($sum <= 0.0) {
            return [
                'owned_visibility' => 0.18,
                'earned_visibility' => 0.24,
                'competitor_pressure' => 0.18,
                'citation_diversity' => 0.14,
                'model_confidence' => 0.12,
                'real_world_gap' => 0.14,
            ];
        }

        return collect($weights)
            ->map(fn (float $weight): float => round($weight / $sum, 4))
            ->all();
    }

    private function legacyWeights(): array
    {
        $configured = (array) config('llm_tracking.score.weights', []);
        $weights = [
            'presence' => max(0.0, (float) ($configured['presence'] ?? 0.30)),
            'position' => max(0.0, (float) ($configured['position'] ?? 0.25)),
            'context' => max(0.0, (float) ($configured['context'] ?? 0.20)),
            'citation' => max(0.0, (float) ($configured['citation'] ?? 0.15)),
            'competitor_share' => max(0.0, (float) ($configured['competitor_share'] ?? 0.10)),
        ];

        $sum = array_sum($weights);
        if ($sum <= 0.0) {
            return [
                'presence' => 0.30,
                'position' => 0.25,
                'context' => 0.20,
                'citation' => 0.15,
                'competitor_share' => 0.10,
            ];
        }

        return collect($weights)
            ->map(fn (float $weight): float => round($weight / $sum, 4))
            ->all();
    }

    private function positionExplanation(float $positionScore, ?string $firstMentionBlock, ?int $firstMentionIndex): string
    {
        return match (true) {
            $positionScore >= 1.0 => 'The brand appears in the first paragraph or list block.',
            $positionScore >= 0.75 => 'The first brand mention appears in the first half of the answer.',
            $positionScore >= 0.5 => 'The first brand mention appears later in the answer.',
            $positionScore > 0 => 'The first brand mention is buried near the bottom of the answer.',
            default => 'No brand mention was found in the answer.',
        };
    }

    /**
     * @param array<int,array<string,mixed>> $sources
     * @param array<int,string> $detectedDomains
     */
    private function citationExplanation(float $citationScore, LlmTrackingQuery $query, array $sources, array $detectedDomains): string
    {
        if ($citationScore >= 1.0) {
            return 'The tracked domain is explicitly present in detected citations or sources.';
        }

        if ($citationScore >= 0.7) {
            return 'The brand is mentioned and citations are present, but the owned domain is not directly cited.';
        }

        if ($citationScore > 0) {
            return 'Sources were detected, but visibility is dominated by non-owned domains.';
        }

        return 'No owned-domain citation footprint was detected in the answer.';
    }
}
