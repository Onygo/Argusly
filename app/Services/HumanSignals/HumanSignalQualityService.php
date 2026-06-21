<?php

namespace App\Services\HumanSignals;

use App\Models\HumanSignal;
use Illuminate\Support\Str;

class HumanSignalQualityService
{
    /**
     * @return array<string,mixed>
     */
    public function score(HumanSignal $signal): array
    {
        return $this->scoreValues(
            (string) $signal->title,
            (string) $signal->observation,
            (string) $signal->impact,
            $signal->relationLoaded('evidence') ? $signal->evidence->all() : [],
            (float) $signal->confidence_score,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function scoreCandidate(array $candidate): array
    {
        return $this->scoreValues(
            (string) ($candidate['title'] ?? ''),
            (string) ($candidate['observation'] ?? ''),
            (string) ($candidate['impact'] ?? ''),
            (array) ($candidate['evidence'] ?? []),
            (float) ($candidate['confidence_score'] ?? 50),
        );
    }

    /**
     * @param array<int,mixed> $evidence
     * @return array<string,mixed>
     */
    private function scoreValues(string $title, string $observation, string $impact, array $evidence, float $confidence): array
    {
        $text = trim($title.' '.$observation.' '.$impact);
        $hasNumbers = preg_match('/\d+(?:[.,]\d+)?|%|x\b/i', $text) === 1;
        $hasConcreteNoun = Str::wordCount($observation) >= 8 && ! str_contains(Str::lower($observation), 'generic');

        $specificity = min(100, 35 + ($hasNumbers ? 30 : 0) + ($hasConcreteNoun ? 25 : 0) + min(10, Str::wordCount($title)));
        $evidenceScore = min(100, count($evidence) * 28 + ($hasNumbers ? 18 : 0));
        $originality = $this->genericPhrasePenalty($text) === 0 ? 82 : 55;
        $practicality = trim($impact) !== '' ? min(100, 55 + Str::wordCount($impact)) : 35;
        $confidenceScore = max(0, min(100, $confidence));
        $humanSignalScore = round(
            ($specificity * 0.22)
            + ($evidenceScore * 0.24)
            + ($originality * 0.2)
            + ($practicality * 0.16)
            + ($confidenceScore * 0.18),
            2
        );

        return [
            'specificity_score' => round($specificity, 2),
            'evidence_score' => round($evidenceScore, 2),
            'originality_score' => round($originality, 2),
            'practicality_score' => round($practicality, 2),
            'confidence_score' => round($confidenceScore, 2),
            'human_signal_score' => max(0, min(100, $humanSignalScore)),
        ];
    }

    private function genericPhrasePenalty(string $text): int
    {
        $lower = Str::lower($text);
        $phrases = [
            'it is important to',
            'organizations should',
            'the future of',
            'increasingly important',
            'more and more companies',
            'digital transformation',
            'innovation is key',
        ];

        return collect($phrases)->filter(fn (string $phrase): bool => str_contains($lower, $phrase))->count();
    }
}
