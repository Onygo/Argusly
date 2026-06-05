<?php

namespace App\Services\Drafts\Intelligence;

class BrandVoiceFitScorer
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
        $guidanceAvailable = (bool) ($signals['guidance_available'] ?? false);
        $toneConsistency = $this->normalizeRatio($signals['tone_consistency_ratio'] ?? 0.7);
        $formalityFit = $this->normalizeRatio($signals['formality_fit_ratio'] ?? 0.7);
        $audienceFit = $this->normalizeRatio($signals['audience_fit_ratio'] ?? 0.7);
        $jargonFit = $this->normalizeRatio($signals['jargon_fit_ratio'] ?? 0.7);
        $preferredCoverage = $this->normalizeRatio($signals['preferred_terminology_coverage'] ?? ($guidanceAvailable ? 0.5 : 0.75));
        $valuePropAlignment = $this->normalizeRatio($signals['value_prop_alignment_ratio'] ?? ($guidanceAvailable ? 0.5 : 0.75));
        $disallowedHits = max(0, (int) ($signals['disallowed_term_hits'] ?? 0));

        if ($guidanceAvailable) {
            $score = 18
                + (int) round($toneConsistency * 18)
                + (int) round($formalityFit * 14)
                + (int) round($audienceFit * 16)
                + (int) round($jargonFit * 10)
                + (int) round($preferredCoverage * 10)
                + (int) round($valuePropAlignment * 10)
                + ((bool) ($signals['intro_cta_consistency'] ?? false) ? 8 : 0);
        } else {
            $score = 54
                + (int) round($toneConsistency * 14)
                + (int) round($audienceFit * 12)
                + (int) round($jargonFit * 8)
                + ((bool) ($signals['intro_cta_consistency'] ?? false) ? 4 : 0);
        }

        $score = max(0, $score - min(20, $disallowedHits * 8));
        $score = max(0, min(100, $score));

        $llmScore = $this->normalizeScore($llmSection['score'] ?? null);
        $finalScore = $llmScore === null
            ? $score
            : max(0, min(100, (int) round(($score * 0.9) + ($llmScore * 0.1))));
        $band = $this->rubrics->bandForScore($finalScore, 'brand_voice_fit');

        $strengths = [];
        $gaps = [];

        $toneConsistency >= 0.7
            ? $strengths[] = 'the tone stays consistent from intro to CTA'
            : $gaps[] = 'the voice shifts between sections';
        $audienceFit >= 0.65
            ? $strengths[] = 'the language generally fits the target audience'
            : $gaps[] = 'the language does not fully match the target audience sophistication';
        $disallowedHits === 0
            ? $strengths[] = 'discouraged phrasing is limited'
            : $gaps[] = 'discouraged phrasing appears in the draft';
        $guidanceAvailable && $preferredCoverage < 0.45
            ? $gaps[] = 'approved brand terminology is underused'
            : null;

        return [
            'score' => $finalScore,
            'band_label' => $band['label'],
            'deterministic_score' => $score,
            'llm_score' => $llmScore,
            'explanation' => $this->explanation($band['label'], $guidanceAvailable, $strengths, $gaps),
            'improvements' => array_values(array_filter([
                $guidanceAvailable && $preferredCoverage < 0.45 ? 'Use more of the approved terminology and positioning language from the brand guidance.' : null,
                $disallowedHits > 0 ? 'Replace discouraged phrasing with approved or more neutral wording.' : null,
                $audienceFit < 0.65 ? 'Align the language more closely with the target audience and their level of sophistication.' : null,
                $toneConsistency < 0.7 ? 'Keep the intro, body, and CTA in the same voice and level of formality.' : null,
            ])) ?: ['Keep the tone consistent and audience-appropriate across the full article.'],
        ];
    }

    /**
     * @param array<int,string> $strengths
     * @param array<int,string> $gaps
     */
    private function explanation(string $bandLabel, bool $guidanceAvailable, array $strengths, array $gaps): string
    {
        $strengthText = $strengths !== [] ? implode(', ', array_slice($strengths, 0, 2)) : 'few strong voice-fit signals are present';
        $gapText = $gaps !== [] ? implode(', ', array_slice($gaps, 0, 2)) : 'major tone mismatches are limited';

        if (! $guidanceAvailable) {
            return sprintf(
                'Brand voice fit falls in the %s band based on the available audience and tone signals. %s, while %s.',
                $bandLabel,
                $strengthText,
                $gapText
            );
        }

        return sprintf(
            'Brand voice fit falls in the %s band because %s, while %s.',
            $bandLabel,
            $strengthText,
            $gapText
        );
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
