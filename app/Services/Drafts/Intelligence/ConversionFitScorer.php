<?php

namespace App\Services\Drafts\Intelligence;

class ConversionFitScorer
{
    public function __construct(
        private readonly DraftIntelligenceRubricRegistry $rubrics,
    ) {}

    /**
     * @param array<string,mixed> $signals
     * @param array<string,mixed> $llmSection
     * @return array{score:int,explanation:string,improvements:array<int,string>,band_label:string,deterministic_score:int,llm_score:?int}
     */
    public function score(array $signals, array $llmSection = []): array
    {
        $funnelStage = (string) ($signals['funnel_stage'] ?? 'consideration');
        $ctaPresent = (bool) ($signals['cta_present'] ?? false);
        $stageFit = $this->normalizeScore($signals['cta_stage_fit_score'] ?? 0) ?? 0;
        $relevance = $this->normalizeScore($signals['cta_relevance_score'] ?? 0) ?? 0;
        $nextStepClarity = $this->normalizeScore($signals['next_step_clarity_score'] ?? 0) ?? 0;
        $decisionSupport = $this->normalizeScore($signals['decision_support_score'] ?? 0) ?? 0;
        $internalPath = $this->normalizeScore($signals['internal_conversion_path_score'] ?? 0) ?? 0;
        $promiseAlignment = $this->normalizeScore($signals['promise_alignment_score'] ?? 0) ?? 0;

        $score = (int) round(
            ($stageFit * 0.24)
            + ($relevance * 0.16)
            + ($nextStepClarity * 0.22)
            + ($decisionSupport * 0.20)
            + ($internalPath * 0.08)
            + ($promiseAlignment * 0.10)
        );

        if (! $ctaPresent) {
            $score -= in_array($funnelStage, ['consideration', 'decision'], true) ? 14 : 8;
        }

        if ($decisionSupport < 60) {
            $score -= (int) round((60 - $decisionSupport) * 0.35);
        }

        if ($promiseAlignment < 60) {
            $score -= (int) round((60 - $promiseAlignment) * 0.2);
        }

        $score = max(0, min(100, $score));

        $llmScore = $this->normalizeScore($llmSection['score'] ?? null);
        $finalScore = $llmScore === null
            ? $score
            : max(0, min(100, (int) round(($score * 0.9) + ($llmScore * 0.1))));
        $band = $this->rubrics->bandForScore($finalScore, 'conversion_fit');

        $strengths = [];
        $gaps = [];

        $nextStepClarity >= 65
            ? $strengths[] = 'the next step is clear'
            : $gaps[] = 'the next step is still vague';
        $decisionSupport >= 60
            ? $strengths[] = 'the article builds enough context before asking the reader to act'
            : $gaps[] = 'the article does not support the conversion decision strongly enough';
        $promiseAlignment >= 60
            ? $strengths[] = 'the suggested action matches the article promise'
            : $gaps[] = 'the suggested action does not fully match the article promise';

        return [
            'score' => $finalScore,
            'band_label' => $band['label'],
            'deterministic_score' => $score,
            'llm_score' => $llmScore,
            'explanation' => sprintf(
                'Conversion fit falls in the %s band because %s, while %s.',
                $band['label'],
                $strengths !== [] ? implode(', ', array_slice($strengths, 0, 2)) : 'few strong conversion signals are present',
                $gaps !== [] ? implode(', ', array_slice($gaps, 0, 2)) : 'major conversion gaps are limited'
            ),
            'improvements' => array_values(array_filter([
                $nextStepClarity < 65 ? 'Clarify the next step after the main argument so the reader knows exactly what to do.' : null,
                $decisionSupport < 60 ? 'Add one more decision-support section, example, or implementation cue before the CTA.' : null,
                $promiseAlignment < 60 ? 'Align the suggested action more closely with the article promise and funnel stage.' : null,
                $internalPath < 50 ? 'Create a clearer conversion path with a stronger closing action or a more explicit internal next step.' : null,
            ])) ?: ['Keep the article promise and next step tightly aligned.'],
        ];
    }

    private function normalizeScore(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) round((float) $value)));
    }
}
