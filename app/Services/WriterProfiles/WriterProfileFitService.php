<?php

namespace App\Services\WriterProfiles;

use App\Models\WriterProfile;
use Illuminate\Support\Str;

class WriterProfileFitService
{
    /**
     * @return array{score:int,tone_match:int,structure_match:int,vocabulary_match:int,readability:int,brand_persona_consistency:int,overfitting_risk:int,improvements:array<int,string>}
     */
    public function score(WriterProfile $profile, string $content, array $brandFacts = [], array $personaContext = []): array
    {
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($content)) ?? '');
        $paragraphs = collect(preg_split('/\n\s*\n/', trim(strip_tags(str_replace(['</p>', '</li>'], "\n\n", $content)))) ?: [])
            ->map(fn ($item) => trim((string) $item))
            ->filter();

        $readability = $this->readabilityScore($plain);
        $structure = $paragraphs->count() >= 3 ? 82 : 58;
        $vocabulary = $this->vocabularyScore($profile, $plain);
        $tone = $this->toneScore($profile, $plain);
        $brandConsistency = $this->consistencyScore($plain, $brandFacts, $personaContext);
        $overfittingRisk = $this->overfittingRisk($profile, $plain);
        $score = (int) round(($tone * 0.22) + ($structure * 0.18) + ($vocabulary * 0.18) + ($readability * 0.16) + ($brandConsistency * 0.18) + ((100 - $overfittingRisk) * 0.08));

        return [
            'score' => max(0, min(100, $score)),
            'tone_match' => $tone,
            'structure_match' => $structure,
            'vocabulary_match' => $vocabulary,
            'readability' => $readability,
            'brand_persona_consistency' => $brandConsistency,
            'overfitting_risk' => $overfittingRisk,
            'improvements' => $this->improvements($tone, $structure, $vocabulary, $readability, $brandConsistency, $overfittingRisk),
        ];
    }

    private function vocabularyScore(WriterProfile $profile, string $plain): int
    {
        $notes = Str::lower($profile->vocabulary_notes ?: implode(' ', (array) $profile->do_rules));
        if ($notes === '') {
            return 70;
        }

        $signals = collect(preg_split('/[^a-zA-Z0-9]+/', $notes) ?: [])
            ->map(fn ($word) => trim((string) $word))
            ->filter(fn ($word) => Str::length($word) > 4)
            ->unique()
            ->take(20);

        if ($signals->isEmpty()) {
            return 70;
        }

        $hits = $signals->filter(fn ($word) => Str::contains(Str::lower($plain), $word))->count();

        return (int) min(92, 58 + ($hits * 7));
    }

    private function toneScore(WriterProfile $profile, string $plain): int
    {
        $summary = Str::lower($profile->tone_summary.' '.$profile->writing_style_summary);
        $score = 68;

        if (Str::contains($summary, ['direct', 'clear', 'concrete']) && $this->averageSentenceLength($plain) <= 24) {
            $score += 12;
        }

        if (Str::contains($summary, ['practical', 'action']) && Str::contains(Str::lower($plain), ['how', 'step', 'action', 'use', 'make'])) {
            $score += 10;
        }

        return min(95, $score);
    }

    private function readabilityScore(string $plain): int
    {
        $average = $this->averageSentenceLength($plain);

        return match (true) {
            $average <= 18 => 88,
            $average <= 24 => 78,
            $average <= 32 => 65,
            default => 52,
        };
    }

    private function consistencyScore(string $plain, array $brandFacts, array $personaContext): int
    {
        $penalty = Str::contains(Str::lower($plain), ['guaranteed', 'best in the world', 'always']) ? 15 : 0;
        $bonus = ($brandFacts !== [] || $personaContext !== []) ? 5 : 0;

        return max(45, min(95, 78 + $bonus - $penalty));
    }

    private function overfittingRisk(WriterProfile $profile, string $plain): int
    {
        $patterns = collect((array) $profile->example_patterns)
            ->map(fn ($pattern) => trim((string) $pattern))
            ->filter(fn ($pattern) => Str::length($pattern) > 28);

        $hits = $patterns->filter(fn ($pattern) => Str::contains(Str::lower($plain), Str::lower($pattern)))->count();

        return min(90, $hits * 25);
    }

    private function averageSentenceLength(string $plain): float
    {
        $sentences = collect(preg_split('/[.!?]+/', $plain) ?: [])->filter(fn ($item) => trim((string) $item) !== '');
        $words = str_word_count($plain);

        return $sentences->isEmpty() ? $words : $words / max(1, $sentences->count());
    }

    /**
     * @return array<int, string>
     */
    private function improvements(int $tone, int $structure, int $vocabulary, int $readability, int $brandConsistency, int $overfittingRisk): array
    {
        return collect([
            $tone < 75 ? 'Move the tone closer to the selected writer profile before polishing channel constraints.' : null,
            $structure < 75 ? 'Use the profile structure more clearly, for example problem, insight, then action.' : null,
            $vocabulary < 75 ? 'Bring in more of the profile vocabulary while keeping claims factual.' : null,
            $readability < 70 ? 'Shorten long sentences and split dense paragraphs.' : null,
            $brandConsistency < 75 ? 'Recheck brand and persona facts; style must not override positioning or audience needs.' : null,
            $overfittingRisk > 35 ? 'Reduce similarity to source patterns; abstract the style further.' : null,
        ])->filter()->values()->all();
    }
}
