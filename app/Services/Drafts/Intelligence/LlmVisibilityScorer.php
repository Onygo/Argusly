<?php

namespace App\Services\Drafts\Intelligence;

class LlmVisibilityScorer
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
        $score = 0;
        $score += ($signals['explicit_answer_presence'] ?? false) ? 16 : 0;
        $score += ($signals['answer_like_intro'] ?? false) ? 10 : 0;
        $score += ($signals['definitional_clarity'] ?? false) ? 10 : 0;
        $score += min(12, ((int) ($signals['concise_authoritative_passage_count'] ?? 0)) * 4);
        $score += ($signals['extractable_summary_block_present'] ?? false) ? 12 : 0;
        $score += ($signals['comparison_pattern_present'] ?? false) ? 6 : 0;
        $score += ($signals['step_based_section_present'] ?? false) ? 8 : 0;
        $score += ($signals['structured_list_present'] ?? false) ? 6 : 0;
        $score += ($signals['faq_pattern_present'] ?? false) ? 5 : 0;
        $score += ($signals['scannable_sections'] ?? false) ? 8 : 0;
        $score += (int) round(max(0, min(1, (float) ($signals['entity_clarity_ratio'] ?? 0))) * 10);
        $score += ($signals['trust_signal_present'] ?? false) ? 4 : 0;
        $score += ($signals['strong_intro_framing'] ?? false) ? 5 : 0;
        $score += ($signals['strong_conclusion_framing'] ?? false) ? 4 : 0;
        $score += ($signals['likely_user_question_answered'] ?? false) ? 6 : 0;
        $score -= min(10, ((int) ($signals['ambiguity_marker_count'] ?? 0)) > 8 ? 10 : (int) floor(((int) ($signals['ambiguity_marker_count'] ?? 0)) / 3) * 2);

        $score = max(0, min(100, $score));
        $llmScore = $this->normalizeScore($llmSection['score'] ?? null);
        $finalScore = $llmScore === null
            ? $score
            : max(0, min(100, (int) round(($score * 0.9) + ($llmScore * 0.1))));
        $band = $this->rubrics->bandForScore($finalScore, 'llm_visibility');

        $strengths = [];
        $gaps = [];

        ($signals['explicit_answer_presence'] ?? false)
            ? $strengths[] = 'the draft states its main answer clearly'
            : $gaps[] = 'the core answer stays implicit';
        ($signals['extractable_summary_block_present'] ?? false)
            ? $strengths[] = 'it includes summary-ready passages'
            : $gaps[] = 'it lacks a concise summary block';
        (($signals['entity_clarity_ratio'] ?? 0) >= 0.65)
            ? $strengths[] = 'named entities and topic references are explicit'
            : $gaps[] = 'important entities still read as vague references';
        ($signals['step_based_section_present'] ?? false) || ($signals['comparison_pattern_present'] ?? false)
            ? $strengths[] = 'the structure is easy for AI systems to reframe'
            : $gaps[] = 'the article lacks step-based or comparison framing';

        return [
            'score' => $finalScore,
            'band_label' => $band['label'],
            'deterministic_score' => $score,
            'llm_score' => $llmScore,
            'explanation' => $this->explanation($band['label'], $strengths, $gaps),
            'improvements' => array_values(array_filter([
                ! ($signals['explicit_answer_presence'] ?? false) ? 'Make the core answer explicit in the introduction.' : null,
                ! ($signals['extractable_summary_block_present'] ?? false) ? 'Add a concise summary or key takeaways block near the start.' : null,
                ((float) ($signals['entity_clarity_ratio'] ?? 0)) < 0.65 ? 'Replace vague references with named entities or concrete examples.' : null,
                ! ($signals['step_based_section_present'] ?? false) && ! ($signals['comparison_pattern_present'] ?? false) ? 'Add a step-based or comparison section to make the guidance easier to extract.' : null,
                ! ($signals['strong_conclusion_framing'] ?? false) ? 'Close with a short recap that restates the main recommendation clearly.' : null,
            ])) ?: ['Keep answer-first framing and concise summary passages intact.'],
        ];
    }

    /**
     * @param array<int,string> $strengths
     * @param array<int,string> $gaps
     */
    private function explanation(string $bandLabel, array $strengths, array $gaps): string
    {
        $strengthText = $strengths !== [] ? implode(', ', array_slice($strengths, 0, 2)) : 'few passages are easy for AI systems to extract';
        $gapText = $gaps !== [] ? implode(', ', array_slice($gaps, 0, 2)) : 'major extractability gaps are limited';

        return sprintf(
            'LLM Visibility falls in the %s band because %s, which makes the draft easier for AI systems to extract, while %s.',
            $bandLabel,
            $strengthText,
            $gapText
        );
    }

    private function normalizeScore(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) round((float) $value)));
    }
}
