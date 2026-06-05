<?php

namespace App\Services\Drafts\Intelligence;

class PublishReadinessEvaluator
{
    public function __construct(
        private readonly DraftIntelligenceRubricRegistry $rubrics,
    ) {}

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $signals
     * @param array<string,array<string,mixed>> $sections
     * @param array<string,mixed> $llmSection
     * @return array{score:int,explanation:string,improvements:array<int,string>,band_label:string,deterministic_score:int,llm_score:?int,status_label:string,blocking_issues:array<int,string>,recommended_next_actions:array<int,string>}
     */
    public function evaluate(array $snapshot, array $signals, array $sections, array $llmSection = []): array
    {
        $scores = collect([
            data_get($sections, 'seo.score'),
            data_get($sections, 'readability.score'),
            data_get($sections, 'cta.score'),
            data_get($sections, 'structure.score'),
            data_get($sections, 'llm_visibility.score'),
            data_get($sections, 'brand_voice_fit.score'),
            data_get($sections, 'conversion_fit.score'),
            data_get($sections, 'trust_evidence.score'),
        ])->filter(fn (mixed $value): bool => is_numeric($value));

        $averageScore = (int) round($scores->avg() ?: 0);
        $blockingIssues = [];
        $nextActions = [];

        $funnelStage = (string) ($snapshot['funnel_stage'] ?? 'consideration');
        $ctaPresent = (bool) data_get($signals, 'cta.cta_present', false);
        $metaReady = trim((string) ($snapshot['seo_title'] ?? '')) !== '' && trim((string) ($snapshot['seo_meta_description'] ?? '')) !== '';
        $readabilityScore = (int) data_get($sections, 'readability.score', 0);
        $headingsScore = (int) data_get($sections, 'structure.score', 0);
        $trustScore = (int) data_get($sections, 'trust_evidence.score', 0);
        $conversionScore = (int) data_get($sections, 'conversion_fit.score', 0);
        $brandScore = (int) data_get($sections, 'brand_voice_fit.score', 0);
        $guidanceAvailable = (bool) data_get($signals, 'brand_voice_fit.guidance_available', false);

        if (! $ctaPresent && in_array($funnelStage, ['consideration', 'decision'], true)) {
            $blockingIssues[] = 'Add a clear closing CTA that matches the funnel stage before publishing.';
        }

        if ($conversionScore < 50) {
            $blockingIssues[] = 'Strengthen the conversion path so the article leads to a clear next step.';
        }

        if ($readabilityScore < 45 && (int) data_get($signals, 'readability.dense_block_count', 0) >= 2) {
            $blockingIssues[] = 'Break up dense paragraphs before publishing.';
        }

        if ($headingsScore < 45 || ! (bool) data_get($signals, 'headings.h1_present', true)) {
            $blockingIssues[] = 'Fix the heading structure so the article is easier to scan.';
        }

        if ($trustScore < 40 || (int) data_get($signals, 'trust_evidence.overclaim_count', 0) >= 2) {
            $blockingIssues[] = 'Replace unsupported or overclaimed language with more concrete framing.';
        }

        if ($guidanceAvailable && $brandScore < 45) {
            $blockingIssues[] = 'Align the tone and terminology more closely with the brand guidance.';
        }

        if (! $metaReady) {
            $blockingIssues[] = 'Complete the SEO title and meta description before publishing.';
        }

        $weakMetrics = collect([
            'seo' => (int) data_get($sections, 'seo.score', 0),
            'readability' => $readabilityScore,
            'cta' => (int) data_get($sections, 'cta.score', 0),
            'headings' => $headingsScore,
            'llm_visibility' => (int) data_get($sections, 'llm_visibility.score', 0),
            'brand_voice_fit' => $brandScore,
            'conversion_fit' => $conversionScore,
            'trust_evidence' => $trustScore,
        ])->filter(fn (int $score): bool => $score < 65)
            ->sort()
            ->keys()
            ->take(3)
            ->values();

        foreach ($blockingIssues as $issue) {
            $nextActions[] = $issue;
        }

        foreach ($weakMetrics as $metricKey) {
            $nextActions[] = match ($metricKey) {
                'seo' => 'Tighten search relevance and metadata support.',
                'readability' => 'Improve paragraph flow and scanability.',
                'cta' => 'Make the next step more explicit and actionable.',
                'headings' => 'Rewrite weak headings and fix hierarchy issues.',
                'llm_visibility' => 'Make the main answer and summary passages easier to extract.',
                'brand_voice_fit' => 'Bring the draft closer to the intended brand voice and audience.',
                'conversion_fit' => 'Support the CTA with clearer decision guidance.',
                'trust_evidence' => 'Add a concrete example or proof-oriented framing.',
                default => 'Resolve the weakest quality issue before publishing.',
            };
        }

        $nextActions = collect($nextActions)->unique()->take(4)->values()->all();

        $score = $averageScore
            - (count($blockingIssues) * 9)
            - (max(0, $weakMetrics->count() - count($blockingIssues)) * 3)
            + ($metaReady ? 4 : 0);
        $score = max(0, min(100, $score));

        $llmScore = $this->normalizeScore($llmSection['score'] ?? null);
        $finalScore = $llmScore === null
            ? $score
            : max(0, min(100, (int) round(($score * 0.95) + ($llmScore * 0.05))));
        $band = $this->rubrics->bandForScore($finalScore, 'publish_readiness');
        $statusLabel = $this->statusLabel($finalScore, count($blockingIssues));

        return [
            'score' => $finalScore,
            'band_label' => $band['label'],
            'deterministic_score' => $score,
            'llm_score' => $llmScore,
            'status_label' => $statusLabel,
            'blocking_issues' => $blockingIssues,
            'recommended_next_actions' => $nextActions,
            'explanation' => $this->explanation($statusLabel, $blockingIssues, $weakMetrics->all()),
            'improvements' => $nextActions !== [] ? $nextActions : ['The draft is close to publishable. Preserve the current quality level.'],
        ];
    }

    /**
     * @param array<int,string> $blockingIssues
     * @param array<int,string> $weakMetrics
     */
    private function explanation(string $statusLabel, array $blockingIssues, array $weakMetrics): string
    {
        if ($blockingIssues !== []) {
            return sprintf(
                'Publish readiness is %s because %s.',
                strtolower($statusLabel),
                implode(' ', array_slice($blockingIssues, 0, 2))
            );
        }

        if ($weakMetrics !== []) {
            return sprintf(
                'Publish readiness is %s. The draft is coherent overall, but %s still need another pass.',
                strtolower($statusLabel),
                implode(' and ', array_map(static fn (string $metric): string => str_replace('_', ' ', $metric), array_slice($weakMetrics, 0, 2)))
            );
        }

        return sprintf(
            'Publish readiness is %s. The draft is coherent, complete, and free of major blocking issues.',
            strtolower($statusLabel)
        );
    }

    private function statusLabel(int $score, int $blockingIssueCount): string
    {
        if ($blockingIssueCount >= 2 || $score < 41) {
            return 'Not ready';
        }

        if ($blockingIssueCount >= 1 || $score < 61) {
            return 'Needs revision';
        }

        if ($score < 81) {
            return 'Nearly ready';
        }

        return 'Ready to publish';
    }

    private function normalizeScore(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) round((float) $value)));
    }
}
