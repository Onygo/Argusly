<?php

namespace App\Services\DraftComparison;

use App\Models\DraftComparison;
use App\Models\DraftComparisonVariant;

class DraftComparisonMetricResolver
{
    /**
     * @var array<string,string>
     */
    private const LEGACY_METRIC_KEY_MAP = [
        'word_count' => 'word_count',
        'reading_time' => 'reading_time',
        'reading_time_minutes' => 'reading_time',
        'seo_score' => 'seo_score',
        'ai_seo_score' => 'ai_seo_score',
        'readability_score' => 'readability_score',
        'brand_voice_match' => 'brand_voice_match',
        'cta_strength' => 'cta_strength',
        'structure_quality' => 'structure_quality',
        'topical_coverage' => 'topical_coverage',
        'entity_coverage' => 'entity_coverage',
        'factual_confidence' => 'factual_confidence',
        'conversion_focus' => 'conversion_focus',
    ];

    /**
     * Build one lookup map for all legacy item metrics keyed by `provider:model`.
     *
     * @return array<string,array<string,mixed>>
     */
    public function legacyMetricsByProviderModel(DraftComparison $comparison): array
    {
        return $comparison->items
            ->mapWithKeys(fn ($item): array => [
                $this->providerModelKey((string) $item->provider, (string) $item->model) => (array) ($item->metrics ?? []),
            ])
            ->all();
    }

    /**
     * Resolve variant metrics using score rows first, then legacy item metrics.
     *
     * @param array<string,array<string,mixed>> $legacyMetricsByModel
     * @return array<string,mixed>
     */
    public function metricsForVariant(DraftComparisonVariant $variant, array $legacyMetricsByModel = []): array
    {
        $metrics = $this->metricsFromScores($variant);
        if ($metrics !== []) {
            return $metrics;
        }

        $key = $this->providerModelKey((string) $variant->provider_key, (string) $variant->model_key);
        $legacyMetrics = (array) ($legacyMetricsByModel[$key] ?? []);

        return $this->normalizeLegacyMetrics($legacyMetrics);
    }

    /**
     * @return array<string,mixed>
     */
    public function metricsFromScores(DraftComparisonVariant $variant): array
    {
        return $variant->scores
            ->mapWithKeys(static function ($score): array {
                $numeric = $score->numeric_score;
                if ($numeric !== null && is_numeric($numeric)) {
                    return [(string) $score->metric_key => round((float) $numeric, 2)];
                }

                $text = $score->text_score;

                return [(string) $score->metric_key => $text !== null ? (string) $text : null];
            })
            ->all();
    }

    /**
     * @param array<string,mixed> $metrics
     * @return array<string,mixed>
     */
    public function normalizeLegacyMetrics(array $metrics): array
    {
        $normalized = [];

        foreach (self::LEGACY_METRIC_KEY_MAP as $sourceKey => $targetKey) {
            if (! array_key_exists($sourceKey, $metrics)) {
                continue;
            }

            $value = $metrics[$sourceKey];
            $normalized[$targetKey] = is_numeric($value) ? round((float) $value, 2) : $value;
        }

        return $normalized;
    }

    public function providerModelKey(string $provider, string $model): string
    {
        return strtolower(trim($provider)) . ':' . trim($model);
    }
}
