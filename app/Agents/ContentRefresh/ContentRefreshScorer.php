<?php

namespace App\Agents\ContentRefresh;

use App\Models\Content;
use App\Services\Content\ContentHealthService;
use DateTimeInterface;
use Illuminate\Support\Str;

class ContentRefreshScorer
{
    public function __construct(
        private readonly ContentHealthService $contentHealthService,
    ) {
    }

    /**
     * @param array<string,mixed> $input
     * @return array{
     *   refresh_score:int,
     *   urgency_level:string,
     *   signals:array<int,array<string,mixed>>
     * }
     */
    public function score(array $input): array
    {
        /** @var Content $content */
        $content = $input['content'];
        $weights = (array) config('content_refresh.weights', []);
        $thresholds = (array) config('content_refresh.thresholds', []);
        $signals = [];

        $ageDays = $this->ageDays($input);
        $ageScore = match (true) {
            $ageDays >= (int) ($thresholds['very_stale_days'] ?? 365) => (int) ($weights['content_age'] ?? 24),
            $ageDays >= (int) ($thresholds['stale_days'] ?? 180) => (int) round(((int) ($weights['content_age'] ?? 24)) * 0.8),
            $ageDays >= (int) ($thresholds['aging_days'] ?? 90) => (int) round(((int) ($weights['content_age'] ?? 24)) * 0.45),
            default => 0,
        };
        if ($ageScore > 0) {
            $signals[] = [
                'key' => 'content_age',
                'score' => $ageScore,
                'title' => 'Content age',
                'description' => sprintf('This content has not been meaningfully updated for %d days.', $ageDays),
                'action' => 'update examples',
            ];
        }

        $missingSeoFields = (array) ($input['missing_seo_fields'] ?? $this->contentHealthService->missingSeoFields($content));
        if ($missingSeoFields !== []) {
            $baseWeight = (int) ($weights['missing_seo_structure'] ?? 20);
            $signals[] = [
                'key' => 'missing_seo_structure',
                'score' => (int) round($baseWeight * min(1, count($missingSeoFields) / 3)),
                'title' => 'Missing SEO structure',
                'description' => 'Important SEO fields are missing: ' . collect($missingSeoFields)->map(fn (string $field): string => Str::headline($field))->implode(', ') . '.',
                'action' => 'improve headings',
            ];
        }

        if ((bool) ($input['title_h1_mismatch'] ?? $this->contentHealthService->hasTitleH1Mismatch($content))) {
            $signals[] = [
                'key' => 'title_h1_mismatch',
                'score' => (int) ($weights['title_h1_mismatch'] ?? 8),
                'title' => 'Title and H1 drift',
                'description' => 'The primary title and H1 are no longer aligned, which can weaken the page structure.',
                'action' => 'improve headings',
            ];
        }

        $duplicateTitleRisks = (array) ($input['duplicate_title_risks'] ?? []);
        if ($duplicateTitleRisks !== []) {
            $firstRisk = (array) $duplicateTitleRisks[0];
            $matchType = (string) ($firstRisk['match_type'] ?? 'similar_title');
            $signals[] = [
                'key' => 'duplicate_title_risk',
                'score' => (int) ($weights['duplicate_title_risk'] ?? 14),
                'title' => $matchType === 'exact_title' ? 'Duplicate title risk' : 'Similar title risk',
                'description' => sprintf(
                    '%d same-locale article%s %s a %s title. Closest match: %s.',
                    count($duplicateTitleRisks),
                    count($duplicateTitleRisks) === 1 ? '' : 's',
                    count($duplicateTitleRisks) === 1 ? 'has' : 'have',
                    $matchType === 'exact_title' ? 'duplicate' : 'very similar',
                    Str::limit((string) ($firstRisk['title'] ?? 'Untitled content'), 90)
                ),
                'action' => 'review canonical coverage',
            ];
        }

        $targetWordCount = (int) ($input['target_word_count'] ?? $this->contentHealthService->targetWordCount($content));
        $wordCount = (int) ($input['word_count'] ?? 0);
        if ($targetWordCount > 0 && $wordCount > 0 && $wordCount < $targetWordCount) {
            $signals[] = [
                'key' => 'short_content',
                'score' => (int) round(((int) ($weights['short_content'] ?? 12)) * (1 - max(0, $wordCount / $targetWordCount))),
                'title' => 'Content depth is light',
                'description' => sprintf('The current body is about %d words, below the %d-word target for this content type.', $wordCount, $targetWordCount),
                'action' => 'expand FAQs',
            ];
        }

        if (! (bool) ($input['has_faq'] ?? false)) {
            $signals[] = [
                'key' => 'missing_faq',
                'score' => (int) ($weights['missing_faq'] ?? 8),
                'title' => 'Supporting FAQ is missing',
                'description' => 'There is no obvious FAQ or supporting questions section to capture follow-up intent.',
                'action' => 'expand FAQs',
            ];
        }

        $internalLinkCount = (int) ($input['internal_link_count'] ?? 0);
        if ($internalLinkCount < (int) ($thresholds['weak_internal_link_count'] ?? 2)) {
            $signals[] = [
                'key' => 'weak_internal_linking',
                'score' => (int) ($weights['weak_internal_linking'] ?? 10),
                'title' => 'Internal linking is light',
                'description' => sprintf('Only %d internal link%s %s currently detected in the editable body.', $internalLinkCount, $internalLinkCount === 1 ? '' : 's', $internalLinkCount === 1 ? 'is' : 'are'),
                'action' => 'improve internal linking',
            ];
        }

        $bodyYears = (array) ($input['body_years'] ?? []);
        $staleBefore = (int) ($thresholds['year_pattern_stale_before'] ?? ((int) now()->format('Y') - 1));
        if ($bodyYears !== [] && max($bodyYears) <= $staleBefore) {
            $signals[] = [
                'key' => 'outdated_references',
                'score' => (int) ($weights['outdated_references'] ?? 8),
                'title' => 'Visible dated references',
                'description' => 'The content body still references older years (' . collect($bodyYears)->implode(', ') . '), which suggests examples or evidence may need an update.',
                'action' => 'update examples',
            ];
        }

        $outdatedLocales = (array) ($input['outdated_locales'] ?? []);
        if ($outdatedLocales !== []) {
            $signals[] = [
                'key' => 'translation_inconsistency',
                'score' => (int) ($weights['translation_inconsistency'] ?? 6),
                'title' => 'Localized variants look stale',
                'description' => 'The following locales appear out of sync with the source content: ' . implode(', ', $outdatedLocales) . '.',
                'action' => 'generate refresh draft',
            ];
        }

        $newerChainArticleCount = (int) ($input['newer_chain_article_count'] ?? 0);
        if ($newerChainArticleCount > 0) {
            $signals[] = [
                'key' => 'chain_outdated',
                'score' => (int) ($weights['chain_outdated'] ?? 8),
                'title' => 'Related chain content is newer',
                'description' => sprintf('%d related chain article%s %s been updated more recently.', $newerChainArticleCount, $newerChainArticleCount === 1 ? '' : 's', $newerChainArticleCount === 1 ? 'has' : 'have'),
                'action' => 'update examples',
            ];
        }

        $score = min(100, collect($signals)->sum(fn (array $signal): int => (int) ($signal['score'] ?? 0)));
        $urgency = match (true) {
            $score >= (int) ($thresholds['score_high'] ?? 65) => 'high',
            $score >= (int) ($thresholds['score_medium'] ?? 35) => 'medium',
            default => 'low',
        };

        return [
            'refresh_score' => $score,
            'urgency_level' => $urgency,
            'signals' => $signals,
        ];
    }

    /**
     * @param array<string,mixed> $input
     */
    private function ageDays(array $input): int
    {
        $reference = $input['latest_content_reference_at'] ?? null;
        if (! $reference instanceof DateTimeInterface) {
            return 0;
        }

        return (int) abs(round(now()->diffInDays($reference, false)));
    }
}
