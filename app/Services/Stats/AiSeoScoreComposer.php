<?php

namespace App\Services\Stats;

class AiSeoScoreComposer
{
    public const FORMULA_VERSION = 'ai_seo_v1';

    /**
     * @var array<string,float>
     */
    private const DEFAULT_WEIGHTS = [
        'content_roi' => 0.55,
        'ai_visibility_normalized' => 0.45,
    ];

    /**
     * @return array<string,float>
     */
    public function baseWeights(): array
    {
        $configured = config('analytics.ai_seo_score.weights', []);
        $contentRoi = $this->toWeight($configured['content_roi'] ?? self::DEFAULT_WEIGHTS['content_roi']);
        $visibility = $this->toWeight($configured['ai_visibility_normalized'] ?? self::DEFAULT_WEIGHTS['ai_visibility_normalized']);

        if (($contentRoi + $visibility) <= 0.0) {
            $contentRoi = self::DEFAULT_WEIGHTS['content_roi'];
            $visibility = self::DEFAULT_WEIGHTS['ai_visibility_normalized'];
        }

        $sum = $contentRoi + $visibility;

        return [
            'content_roi' => round($contentRoi / $sum, 4),
            'ai_visibility_normalized' => round($visibility / $sum, 4),
        ];
    }

    /**
     * @return array{
     *     score:float,
     *     applied_weights:array<string,float>,
     *     normalized_inputs:array<string,float>,
     *     missing_inputs:array<int,string>
     * }
     */
    public function compose(?float $contentRoiScore, ?float $normalizedVisibilityScore): array
    {
        $baseWeights = $this->baseWeights();
        $inputs = [
            'content_roi' => $contentRoiScore !== null ? $this->clampScore($contentRoiScore) : null,
            'ai_visibility_normalized' => $normalizedVisibilityScore !== null ? $this->clampScore($normalizedVisibilityScore) : null,
        ];

        $availableKeys = array_values(array_filter(
            array_keys($inputs),
            fn (string $key): bool => $inputs[$key] !== null
        ));

        if ($availableKeys === []) {
            return [
                'score' => 0.0,
                'applied_weights' => [
                    'content_roi' => 0.0,
                    'ai_visibility_normalized' => 0.0,
                ],
                'normalized_inputs' => [
                    'content_roi' => 0.0,
                    'ai_visibility_normalized' => 0.0,
                ],
                'missing_inputs' => ['content_roi', 'ai_visibility_normalized'],
            ];
        }

        $weights = [
            'content_roi' => 0.0,
            'ai_visibility_normalized' => 0.0,
        ];

        if (count($availableKeys) === 1) {
            $weights[$availableKeys[0]] = 1.0;
        } else {
            $availableBaseWeight = array_sum(array_map(
                static fn (string $key): float => (float) ($baseWeights[$key] ?? 0.0),
                $availableKeys
            ));

            if ($availableBaseWeight <= 0.0) {
                $uniformWeight = round(1 / count($availableKeys), 4);
                foreach ($availableKeys as $key) {
                    $weights[$key] = $uniformWeight;
                }
            } else {
                foreach ($availableKeys as $key) {
                    $weights[$key] = round(((float) $baseWeights[$key]) / $availableBaseWeight, 4);
                }
            }
        }

        $score = round(
            ($this->safeInput($inputs['content_roi']) * $weights['content_roi'])
            + ($this->safeInput($inputs['ai_visibility_normalized']) * $weights['ai_visibility_normalized']),
            2
        );

        return [
            'score' => $this->clampScore($score),
            'applied_weights' => $weights,
            'normalized_inputs' => [
                'content_roi' => $this->safeInput($inputs['content_roi']),
                'ai_visibility_normalized' => $this->safeInput($inputs['ai_visibility_normalized']),
            ],
            'missing_inputs' => array_values(array_filter(
                ['content_roi', 'ai_visibility_normalized'],
                static fn (string $key): bool => $inputs[$key] === null
            )),
        ];
    }

    public function formulaVersion(): string
    {
        return self::FORMULA_VERSION;
    }

    public function tooltipLabel(): string
    {
        $weights = $this->baseWeights();
        $roi = number_format($weights['content_roi'], 2);
        $visibility = number_format($weights['ai_visibility_normalized'], 2);

        return "AI SEO Score = (Content ROI × {$roi}) + (AI Visibility Normalized × {$visibility})";
    }

    private function safeInput(?float $value): float
    {
        return $value === null ? 0.0 : $this->clampScore($value);
    }

    private function clampScore(float $value): float
    {
        return round(max(0.0, min(100.0, $value)), 2);
    }

    private function toWeight(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        return max(0.0, (float) $value);
    }
}
