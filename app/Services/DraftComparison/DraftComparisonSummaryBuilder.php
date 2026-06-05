<?php

namespace App\Services\DraftComparison;

use App\Models\DraftComparison;
use App\Models\DraftComparisonVariant;

class DraftComparisonSummaryBuilder
{
    private const SUMMARY_VERSION = 'draft_compare_summary_v1';

    public function __construct(
        private readonly DraftComparisonMetricResolver $metricResolver,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(DraftComparison $comparison): array
    {
        $comparison->loadMissing([
            'variants.scores',
            'variants.draft',
            'items',
        ]);

        $legacyMetricsByModel = $this->metricResolver->legacyMetricsByProviderModel($comparison);

        $variantRows = $comparison->variants
            ->map(fn (DraftComparisonVariant $variant): array => $this->variantRow($variant, $legacyMetricsByModel))
            ->values();

        $completedRows = $variantRows
            ->filter(fn (array $row): bool => (string) ($row['status'] ?? '') === DraftComparisonVariant::STATUS_COMPLETED)
            ->values();

        return [
            'version' => self::SUMMARY_VERSION,
            'generated_at' => now()->toIso8601String(),
            'variant_count' => $variantRows->count(),
            'completed_variant_count' => $completedRows->count(),
            'insights' => [
                'best_overall' => $this->bestBy($completedRows, 'overall_score'),
                'best_for_seo' => $this->bestBy($completedRows, 'seo_fit_score'),
                'best_for_conversion' => $this->bestBy($completedRows, 'conversion_fit_score'),
                'best_brand_voice_fit' => $this->bestBy($completedRows, 'brand_voice_match'),
                'most_concise' => $this->mostConcise($completedRows),
                'most_comprehensive' => $this->mostComprehensive($completedRows),
            ],
            'ranking' => $completedRows
                ->sortByDesc('overall_score')
                ->values()
                ->map(fn (array $row): array => [
                    'variant_id' => (string) $row['variant_id'],
                    'draft_id' => $row['draft_id'],
                    'provider' => (string) $row['provider'],
                    'model' => (string) $row['model'],
                    'display_name' => $row['display_name'],
                    'overall_score' => $row['overall_score'],
                    'seo_fit_score' => $row['seo_fit_score'],
                    'conversion_fit_score' => $row['conversion_fit_score'],
                ])
                ->all(),
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $legacyMetricsByModel
     * @return array<string,mixed>
     */
    private function variantRow(DraftComparisonVariant $variant, array $legacyMetricsByModel): array
    {
        $metrics = $this->metricResolver->metricsForVariant($variant, $legacyMetricsByModel);

        $overallScore = $this->weightedAverage([
            ['key' => 'seo_score', 'weight' => 0.20],
            ['key' => 'ai_seo_score', 'weight' => 0.15],
            ['key' => 'readability_score', 'weight' => 0.10],
            ['key' => 'brand_voice_match', 'weight' => 0.15],
            ['key' => 'cta_strength', 'weight' => 0.10],
            ['key' => 'structure_quality', 'weight' => 0.10],
            ['key' => 'topical_coverage', 'weight' => 0.07],
            ['key' => 'entity_coverage', 'weight' => 0.05],
            ['key' => 'factual_confidence', 'weight' => 0.04],
            ['key' => 'conversion_focus', 'weight' => 0.04],
        ], $metrics);

        $seoFitScore = $this->weightedAverage([
            ['key' => 'seo_score', 'weight' => 0.45],
            ['key' => 'ai_seo_score', 'weight' => 0.30],
            ['key' => 'topical_coverage', 'weight' => 0.15],
            ['key' => 'entity_coverage', 'weight' => 0.10],
        ], $metrics);

        $conversionFitScore = $this->weightedAverage([
            ['key' => 'conversion_focus', 'weight' => 0.60],
            ['key' => 'cta_strength', 'weight' => 0.40],
        ], $metrics);

        return [
            'variant_id' => (string) $variant->id,
            'draft_id' => $variant->draft_id ? (string) $variant->draft_id : null,
            'provider' => (string) $variant->provider_key,
            'model' => (string) $variant->model_key,
            'display_name' => $variant->display_name,
            'status' => (string) $variant->status,
            'metrics' => $metrics,
            'overall_score' => $overallScore,
            'seo_fit_score' => $seoFitScore,
            'conversion_fit_score' => $conversionFitScore,
            'brand_voice_match' => $this->numericMetric($metrics, 'brand_voice_match'),
            'word_count' => $this->numericMetric($metrics, 'word_count'),
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int,array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function bestBy($rows, string $key): ?array
    {
        $filtered = $rows
            ->filter(fn (array $row): bool => is_numeric($row[$key] ?? null))
            ->sortByDesc(fn (array $row): float => (float) ($row[$key] ?? 0))
            ->values();

        $winner = $filtered->first();
        if (! is_array($winner)) {
            return null;
        }

        return [
            'variant_id' => (string) $winner['variant_id'],
            'draft_id' => $winner['draft_id'],
            'provider' => (string) $winner['provider'],
            'model' => (string) $winner['model'],
            'display_name' => $winner['display_name'],
            'score_key' => $key,
            'score' => round((float) $winner[$key], 2),
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int,array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function mostConcise($rows): ?array
    {
        $winner = $rows
            ->filter(fn (array $row): bool => is_numeric($row['word_count'] ?? null) && (float) $row['word_count'] > 0)
            ->sortBy(fn (array $row): float => (float) ($row['word_count'] ?? 0))
            ->values()
            ->first();

        if (! is_array($winner)) {
            return null;
        }

        return [
            'variant_id' => (string) $winner['variant_id'],
            'draft_id' => $winner['draft_id'],
            'provider' => (string) $winner['provider'],
            'model' => (string) $winner['model'],
            'display_name' => $winner['display_name'],
            'word_count' => (int) round((float) $winner['word_count']),
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int,array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function mostComprehensive($rows): ?array
    {
        $winner = $rows
            ->filter(fn (array $row): bool => is_numeric($row['word_count'] ?? null) && (float) $row['word_count'] > 0)
            ->sortByDesc(fn (array $row): float => (float) ($row['word_count'] ?? 0))
            ->values()
            ->first();

        if (! is_array($winner)) {
            return null;
        }

        return [
            'variant_id' => (string) $winner['variant_id'],
            'draft_id' => $winner['draft_id'],
            'provider' => (string) $winner['provider'],
            'model' => (string) $winner['model'],
            'display_name' => $winner['display_name'],
            'word_count' => (int) round((float) $winner['word_count']),
        ];
    }

    /**
     * @param array<int,array{key:string,weight:float}> $weights
     * @param array<string,mixed> $metrics
     */
    private function weightedAverage(array $weights, array $metrics): ?float
    {
        $weightedSum = 0.0;
        $weightTotal = 0.0;

        foreach ($weights as $weight) {
            $metricKey = $weight['key'];
            $metricWeight = (float) $weight['weight'];
            if ($metricWeight <= 0) {
                continue;
            }

            $value = $this->numericMetric($metrics, $metricKey);
            if ($value === null) {
                continue;
            }

            $weightedSum += ($value * $metricWeight);
            $weightTotal += $metricWeight;
        }

        if ($weightTotal <= 0.0) {
            return null;
        }

        return round(max(0.0, min(100.0, $weightedSum / $weightTotal)), 2);
    }

    /**
     * @param array<string,mixed> $metrics
     */
    private function numericMetric(array $metrics, string $key): ?float
    {
        $value = $metrics[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }
}
