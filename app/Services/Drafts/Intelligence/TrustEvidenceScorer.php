<?php

namespace App\Services\Drafts\Intelligence;

class TrustEvidenceScorer
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
        $concreteClaimCount = max(0, (int) ($signals['concrete_claim_count'] ?? 0));
        $exampleCount = max(0, (int) ($signals['example_count'] ?? 0));
        $evidenceSignalCount = max(0, (int) ($signals['evidence_signal_count'] ?? 0));
        $hypeTermCount = max(0, (int) ($signals['hype_term_count'] ?? 0));
        $overclaimCount = max(0, (int) ($signals['overclaim_count'] ?? 0));
        $proofCoverage = $this->normalizeRatio($signals['proof_point_coverage_ratio'] ?? 0.0);
        $recommendationClarity = $this->normalizeScore($signals['recommendation_clarity_score'] ?? 0) ?? 0;

        $score = 36
            + min(18, $concreteClaimCount * 6)
            + min(12, $exampleCount * 6)
            + min(12, $evidenceSignalCount * 4)
            + ((bool) ($signals['balanced_wording_present'] ?? false) ? 8 : 0)
            + (int) round($proofCoverage * 8)
            + (int) round($recommendationClarity * 0.12);

        $score -= min(20, $hypeTermCount * 6);
        $score -= min(18, $overclaimCount * 8);
        $score = max(0, min(100, $score));

        $llmScore = $this->normalizeScore($llmSection['score'] ?? null);
        $finalScore = $llmScore === null
            ? $score
            : max(0, min(100, (int) round(($score * 0.9) + ($llmScore * 0.1))));
        $band = $this->rubrics->bandForScore($finalScore, 'trust_evidence');

        $strengths = [];
        $gaps = [];

        $concreteClaimCount > 0
            ? $strengths[] = 'the draft uses concrete claims or specifics'
            : $gaps[] = 'key claims stay generic';
        $exampleCount > 0 || $evidenceSignalCount > 0
            ? $strengths[] = 'examples or evidence-style framing support the argument'
            : $gaps[] = 'the draft lacks examples or evidence-style framing';
        $hypeTermCount === 0 && $overclaimCount === 0
            ? $strengths[] = 'the wording stays measured'
            : $gaps[] = 'some claims sound overly promotional or absolute';

        return [
            'score' => $finalScore,
            'band_label' => $band['label'],
            'deterministic_score' => $score,
            'llm_score' => $llmScore,
            'explanation' => sprintf(
                'Trust and evidence falls in the %s band because %s, while %s.',
                $band['label'],
                $strengths !== [] ? implode(', ', array_slice($strengths, 0, 2)) : 'few strong trust signals are present',
                $gaps !== [] ? implode(', ', array_slice($gaps, 0, 2)) : 'major trust gaps are limited'
            ),
            'improvements' => array_values(array_filter([
                $concreteClaimCount === 0 ? 'Replace generic claims with one concrete example, number, timeframe, or operational detail.' : null,
                $exampleCount === 0 && $evidenceSignalCount === 0 ? 'Ground the main argument with a short example, observed pattern, or evidence-style framing.' : null,
                $hypeTermCount > 0 || $overclaimCount > 0 ? 'Remove hype and absolute language so the recommendations feel more credible.' : null,
                $recommendationClarity < 60 ? 'State the main recommendation more directly so it feels practical and well supported.' : null,
            ])) ?: ['Keep the claims concrete, measured, and well grounded.'],
        ];
    }

    private function normalizeRatio(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        return max(0.0, min(1.0, (float) $value));
    }

    private function normalizeScore(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) round((float) $value)));
    }
}
