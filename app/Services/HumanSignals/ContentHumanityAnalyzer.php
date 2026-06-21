<?php

namespace App\Services\HumanSignals;

use Illuminate\Support\Str;

class ContentHumanityAnalyzer
{
    /**
     * @return array<string,mixed>
     */
    public function analyze(string $content): array
    {
        $plain = trim(strip_tags($content));
        $words = max(1, Str::wordCount($plain));
        $lower = Str::lower($plain);
        $phrases = [
            'it is important to',
            'organizations should',
            'the future of',
            'increasingly important',
            'more and more companies',
            'digital transformation',
            'innovation is key',
        ];

        $matches = collect($phrases)
            ->filter(fn (string $phrase): bool => str_contains($lower, $phrase))
            ->values()
            ->all();

        $genericClaimCount = preg_match_all('/\b(should|must|need to|important|key|crucial|essential)\b/i', $plain);
        $evidenceMarkers = preg_match_all('/\b\d+(?:[.,]\d+)?%?|\bobserved\b|\bdetected\b|\bmeasured\b|\btracked\b|\bcompared\b/i', $plain);
        $specificity = min(35, $evidenceMarkers * 4);
        $slopScore = min(100, (count($matches) * 16) + min(45, $genericClaimCount * 3) + ($words > 0 ? min(20, ($genericClaimCount / $words) * 500) : 0));
        $humanityScore = max(0, min(100, 86 - $slopScore + $specificity));

        return [
            'humanity_score' => round($humanityScore, 2),
            'ai_slop_score' => round($slopScore, 2),
            'generic_claim_count' => (int) $genericClaimCount,
            'evidence_marker_count' => (int) $evidenceMarkers,
            'matched_cliches' => $matches,
            'recommendation' => $slopScore >= 45
                ? 'Replace generic advice with detected observations, metrics, and named patterns.'
                : 'Content has enough concrete signal language to avoid obvious generic AI slop.',
        ];
    }
}
