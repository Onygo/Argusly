<?php

namespace App\Services\Drafts\Intelligence;

use Illuminate\Support\Str;

class LlmVisibilitySignalExtractor
{
    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $entitySignals
     * @return array<string,mixed>
     */
    public function extract(array $snapshot, array $entitySignals = []): array
    {
        $intro = trim((string) ($snapshot['intro'] ?? ''));
        $conclusion = trim((string) ($snapshot['conclusion'] ?? ''));
        $plainText = trim((string) ($snapshot['plain_text'] ?? ''));
        $paragraphs = collect((array) ($snapshot['paragraphs'] ?? []))
            ->map(fn (mixed $paragraph): string => trim((string) $paragraph))
            ->filter()
            ->values();
        $headings = collect((array) ($snapshot['headings'] ?? []))
            ->map(fn (array $heading): string => trim((string) ($heading['text'] ?? '')))
            ->filter()
            ->values();
        $primaryKeyword = $this->normalize((string) ($snapshot['primary_keyword'] ?? ''));
        $title = $this->normalize((string) ($snapshot['title'] ?? ''));
        $definitionPassages = collect((array) ($snapshot['definition_passages'] ?? []))
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->values();
        $extractablePassages = collect((array) ($snapshot['extractable_passages'] ?? []))
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->values();

        $answerLikeIntro = $intro !== ''
            && $this->wordCount($intro) <= 90
            && (
                $this->matchesAnswerPattern($intro)
                || ($primaryKeyword !== '' && Str::contains($this->normalize($intro), $primaryKeyword))
                || ($title !== '' && Str::contains($this->normalize($intro), Str::before($title, ' ')))
            );

        $explicitAnswerPresence = $answerLikeIntro
            || $paragraphs->contains(fn (string $paragraph): bool => $this->matchesAnswerPattern($paragraph))
            || $definitionPassages->isNotEmpty();

        $summaryBlockPresent = ((int) ($snapshot['summary_section_count'] ?? 0)) > 0
            || $paragraphs->contains(fn (string $paragraph): bool => preg_match('/\b(in short|summary|key takeaways|at a glance|samengevat|kort gezegd|belangrijkste punten)\b/iu', $paragraph) === 1);

        $comparisonPatternPresent = ((int) ($snapshot['comparison_section_count'] ?? 0)) > 0
            || $headings->contains(fn (string $heading): bool => preg_match('/\b(vs\.?|versus|compare|comparison|difference|verschil)\b/iu', $heading) === 1);

        $stepBasedSectionPresent = ((int) ($snapshot['step_section_count'] ?? 0)) > 0
            || $paragraphs->contains(fn (string $paragraph): bool => preg_match('/\b(step|steps|stap|stappen|how to|aanpak|roadmap|proces)\b/iu', $paragraph) === 1);

        $faqPatternPresent = ((int) ($snapshot['faq_section_count'] ?? 0)) > 0
            || $headings->contains(fn (string $heading): bool => Str::contains($heading, '?'));

        $trustSignalPresent = $paragraphs->contains(function (string $paragraph): bool {
            return preg_match('/\b(according to|research|study|data|for example|case study|based on|ervaring|onderzoek|bron|voorbeeld|in de praktijk)\b/iu', $paragraph) === 1;
        });

        $strongIntroFraming = $answerLikeIntro && (
            ($primaryKeyword !== '' && Str::contains($this->normalize($intro), $primaryKeyword))
            || preg_match('/\b(this article|this guide|dit artikel|deze gids|in this article|the answer)\b/iu', $intro) === 1
        );

        $strongConclusionFraming = $conclusion !== '' && (
            preg_match('/\b(in short|summary|key takeaway|next step|samengevat|kort gezegd|volgende stap|belangrijkste)\b/iu', $conclusion) === 1
            || $this->wordCount($conclusion) <= 80
        );

        $vagueReferenceCount = $paragraphs->sum(function (string $paragraph): int {
            preg_match_all('/\b(this|that|it|they|these|those|dit|dat|deze|die)\b/iu', $paragraph, $matches);

            return count($matches[0] ?? []);
        });

        $entityClarityRatio = (float) ($entitySignals['coverage_ratio'] ?? 0);
        if ($entityClarityRatio === 0.0 && $plainText !== '') {
            $expectedEntities = collect((array) ($snapshot['expected_entities'] ?? []))
                ->map(fn (mixed $item): string => $this->normalize((string) $item))
                ->filter()
                ->values();

            if ($expectedEntities->isEmpty()) {
                $entityClarityRatio = 0.7;
            } else {
                $matched = $expectedEntities->filter(fn (string $entity): bool => Str::contains($this->normalize($plainText), $entity))->count();
                $entityClarityRatio = round($matched / max(1, $expectedEntities->count()), 2);
            }
        }

        $likelyUserQuestionAnswered = $explicitAnswerPresence && (
            $summaryBlockPresent
            || $comparisonPatternPresent
            || $stepBasedSectionPresent
            || $definitionPassages->isNotEmpty()
        );

        return [
            'explicit_answer_presence' => $explicitAnswerPresence,
            'answer_like_intro' => $answerLikeIntro,
            'definitional_clarity' => $definitionPassages->isNotEmpty(),
            'definition_passage_count' => $definitionPassages->count(),
            'entity_clarity_ratio' => round($entityClarityRatio, 2),
            'concise_authoritative_passage_count' => $extractablePassages->count(),
            'extractable_summary_block_present' => $summaryBlockPresent,
            'comparison_pattern_present' => $comparisonPatternPresent,
            'step_based_section_present' => $stepBasedSectionPresent,
            'structured_list_present' => ((int) ($snapshot['list_count'] ?? 0)) > 0,
            'faq_pattern_present' => $faqPatternPresent,
            'scannable_sections' => $headings->count() >= 2 || ((int) ($snapshot['list_count'] ?? 0)) > 0,
            'ambiguity_marker_count' => $vagueReferenceCount,
            'trust_signal_present' => $trustSignalPresent,
            'strong_intro_framing' => $strongIntroFraming,
            'strong_conclusion_framing' => $strongConclusionFraming,
            'likely_user_question_answered' => $likelyUserQuestionAnswered,
            'extractable_passages' => $extractablePassages->take(3)->all(),
            'definition_passages' => $definitionPassages->take(3)->all(),
        ];
    }

    private function matchesAnswerPattern(string $text): bool
    {
        return preg_match('/\b(is|are|means|refers to|can be defined as|works by|starts with|comes down to|helps|dit artikel|deze gids|betekent|is een|bestaat uit)\b/iu', $text) === 1;
    }

    private function normalize(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return Str::of($value)->lower()->trim()->toString();
    }

    private function wordCount(string $text): int
    {
        preg_match_all('/[\pL\pN\']+/u', $text, $matches);

        return count($matches[0] ?? []);
    }
}
