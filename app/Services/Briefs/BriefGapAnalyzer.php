<?php

namespace App\Services\Briefs;

use App\Models\Brief;

class BriefGapAnalyzer
{
    /**
     * @return array{
     *   score:int,
     *   missing_inputs:array<int,string>,
     *   weak_inputs:array<int,string>,
     *   strongest_inputs:array<int,string>,
     *   recommendation:string,
     *   evaluated_at:string
     * }
     */
    public function analyze(Brief $brief): array
    {
        $checks = [
            'title' => ['label' => 'Title', 'weight' => 18, 'quality' => $this->stringQuality((string) ($brief->title ?? ''), 14)],
            'primary_keyword' => ['label' => 'Primary keyword', 'weight' => 14, 'quality' => $this->stringQuality((string) ($brief->primary_keyword ?? ''), 4)],
            'target_audience' => ['label' => 'Target audience', 'weight' => 12, 'quality' => $this->stringQuality((string) (($brief->target_audience ?: $brief->audience) ?: ''), 8)],
            'search_intent' => ['label' => 'Search intent', 'weight' => 8, 'quality' => $this->stringQuality((string) ($brief->search_intent ?? ''), 4)],
            'unique_angle' => ['label' => 'Unique angle', 'weight' => 10, 'quality' => $this->stringQuality((string) ($brief->unique_angle ?? ''), 12)],
            'key_points' => ['label' => 'Key points', 'weight' => 12, 'quality' => $this->listQuality($brief->key_points, 2)],
            'secondary_keywords' => ['label' => 'Semantic terms', 'weight' => 8, 'quality' => $this->listQuality($brief->secondary_keywords, 3)],
            'call_to_action' => ['label' => 'CTA direction', 'weight' => 10, 'quality' => $this->stringQuality((string) ($brief->call_to_action ?? ''), 8)],
            'tone_of_voice' => ['label' => 'Tone of voice', 'weight' => 4, 'quality' => $this->stringQuality((string) ($brief->tone_of_voice ?? ''), 6)],
            'length' => ['label' => 'Length guidance', 'weight' => 4, 'quality' => $this->lengthQuality($brief)],
        ];

        $weighted = 0.0;
        $total = 0;
        $missing = [];
        $weak = [];
        $strong = [];

        foreach ($checks as $check) {
            $weight = (int) $check['weight'];
            $quality = (float) $check['quality'];
            $label = (string) $check['label'];

            $weighted += $quality * $weight;
            $total += $weight;

            if ($quality <= 0.05) {
                $missing[] = $label;

                continue;
            }

            if ($quality < 0.45) {
                $weak[] = $label;
            }

            if ($quality >= 0.8) {
                $strong[] = $label;
            }
        }

        $score = $total > 0
            ? (int) max(0, min(100, round(($weighted / $total) * 100)))
            : 0;

        return [
            'score' => $score,
            'missing_inputs' => array_values($missing),
            'weak_inputs' => array_values($weak),
            'strongest_inputs' => array_values($strong),
            'recommendation' => $this->recommendation($score, $missing, $weak),
            'evaluated_at' => now()->toIso8601String(),
        ];
    }

    private function recommendation(int $score, array $missing, array $weak): string
    {
        if ($score >= 85) {
            return 'Brief is strong enough for generation. Optional: refine headline variants and CTA precision.';
        }

        if ($missing !== []) {
            return 'Complete these inputs before generation: ' . implode(', ', array_slice($missing, 0, 4)) . '.';
        }

        if ($weak !== []) {
            return 'Strengthen weak inputs for better output quality: ' . implode(', ', array_slice($weak, 0, 4)) . '.';
        }

        return 'Add more detail to improve generation quality.';
    }

    private function stringQuality(string $value, int $minLength): float
    {
        $normalized = trim($value);

        if ($normalized === '') {
            return 0.0;
        }

        $length = mb_strlen($normalized);

        if ($length >= max($minLength * 2, $minLength + 8)) {
            return 1.0;
        }

        if ($length >= $minLength) {
            return 0.7;
        }

        return 0.35;
    }

    private function listQuality(mixed $value, int $goodCount): float
    {
        $items = collect(is_array($value) ? $value : (preg_split('/[\n,]+/', (string) $value) ?: []))
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->values();

        $count = $items->count();

        if ($count === 0) {
            return 0.0;
        }

        if ($count >= $goodCount + 2) {
            return 1.0;
        }

        if ($count >= $goodCount) {
            return 0.75;
        }

        return 0.45;
    }

    private function lengthQuality(Brief $brief): float
    {
        $min = (int) ($brief->desired_length_min ?? 0);
        $max = (int) ($brief->desired_length_max ?? 0);

        if ($min <= 0 || $max <= 0) {
            return 0.0;
        }

        if ($min > $max) {
            return 0.2;
        }

        if (($max - $min) > 2500) {
            return 0.5;
        }

        return 1.0;
    }
}
