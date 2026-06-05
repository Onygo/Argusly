<?php

namespace App\Services\DraftComparison;

use App\Models\DraftComparison;
use App\Models\DraftComparisonVariant;

class DraftComparisonWinnerService
{
    private const VERSION = 'draft_compare_winner_v1';

    public function __construct(
        private readonly DraftComparisonMetricResolver $metricResolver,
        private readonly DraftComparisonFeatureGate $comparisonFeatureGate,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function recommend(DraftComparison $comparison): array
    {
        $comparison->loadMissing([
            'variants.scores',
            'variants.draft',
            'items',
        ]);

        $weightResolution = $this->resolveWeights($comparison);
        $weights = $weightResolution['weights'];

        $legacyMetricsByModel = $this->metricResolver->legacyMetricsByProviderModel($comparison);

        $rows = $comparison->variants
            ->filter(fn (DraftComparisonVariant $variant): bool => (string) $variant->status === DraftComparisonVariant::STATUS_COMPLETED)
            ->map(fn (DraftComparisonVariant $variant): array => $this->variantRow($variant, $legacyMetricsByModel, $weights))
            ->filter(fn (array $row): bool => $row['weighted_score'] !== null)
            ->values();

        $winner = $rows
            ->sortByDesc(fn (array $row): float => (float) ($row['weighted_score'] ?? 0))
            ->values()
            ->first();

        $runnerUp = $rows
            ->sortByDesc(fn (array $row): float => (float) ($row['weighted_score'] ?? 0))
            ->values()
            ->get(1);

        return [
            'version' => self::VERSION,
            'generated_at' => now()->toIso8601String(),
            'weights' => $weights,
            'weights_source' => $weightResolution['source'],
            'candidate_count' => $rows->count(),
            'suggested_winner' => is_array($winner) ? $this->winnerPayload($winner) : null,
            'why_it_won' => is_array($winner) ? $this->winnerExplanation($winner, is_array($runnerUp) ? $runnerUp : null) : 'No completed variants with score data available yet.',
            'best_for_seo' => $this->bestForSeo($rows),
            'best_for_brand_voice' => $this->bestByMetric($rows, 'brand_voice_match'),
            'best_concise_option' => $this->bestConcise($rows),
            'best_conversion_focused_option' => $this->bestConversion($rows),
        ];
    }

    /**
     * @return array<string,float>
     */
    public function configuredWeights(): array
    {
        return $this->resolveWeights()['weights'];
    }

    /**
     * @return array{weights:array<string,float>,source:string}
     */
    private function resolveWeights(?DraftComparison $comparison = null): array
    {
        $defaults = [
            'seo_score' => 20.0,
            'ai_seo_score' => 15.0,
            'brand_voice_match' => 20.0,
            'structure_quality' => 15.0,
            'readability_score' => 10.0,
            'cta_strength' => 10.0,
            'conversion_focus' => 10.0,
        ];

        $configured = (array) config('credits.draft_compare.winner_weights', []);
        $source = 'config_default';

        if ($comparison !== null) {
            $workspaceOverrides = $this->comparisonFeatureGate->winnerWeightsForComparison($comparison);
            if ($workspaceOverrides !== []) {
                $configured = array_replace($configured, $workspaceOverrides);
                $source = 'workspace_entitlement';
            }
        }

        $weights = [];

        foreach ($defaults as $metricKey => $defaultValue) {
            $raw = $configured[$metricKey] ?? $defaultValue;
            $weights[$metricKey] = is_numeric($raw) ? max(0.0, (float) $raw) : $defaultValue;
        }

        if (array_sum($weights) <= 0.0) {
            return [
                'weights' => $defaults,
                'source' => 'fallback_defaults',
            ];
        }

        return [
            'weights' => $weights,
            'source' => $source,
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $legacyMetricsByModel
     * @param array<string,float> $weights
     * @return array<string,mixed>
     */
    private function variantRow(DraftComparisonVariant $variant, array $legacyMetricsByModel, array $weights): array
    {
        $metrics = $this->metricResolver->metricsForVariant($variant, $legacyMetricsByModel);

        $score = $this->weightedScore($metrics, $weights);

        return [
            'variant_id' => (string) $variant->id,
            'draft_id' => $variant->draft_id ? (string) $variant->draft_id : null,
            'provider' => (string) $variant->provider_key,
            'model' => (string) $variant->model_key,
            'display_name' => $variant->display_name,
            'metrics' => $metrics,
            'weighted_score' => $score['score'],
            'weighted_breakdown' => $score['breakdown'],
            'missing_weighted_metrics' => $score['missing_metrics'],
        ];
    }

    /**
     * @param array<string,mixed> $metrics
     * @param array<string,float> $weights
     * @return array{score:?float,breakdown:array<string,mixed>,missing_metrics:array<int,string>}
     */
    private function weightedScore(array $metrics, array $weights): array
    {
        $available = [];
        $missing = [];

        foreach ($weights as $metric => $weight) {
            $value = $metrics[$metric] ?? null;
            if (is_numeric($value)) {
                $available[$metric] = [
                    'value' => (float) $value,
                    'weight' => (float) $weight,
                ];

                continue;
            }

            $missing[] = $metric;
        }

        $weightSum = array_sum(array_map(static fn (array $item): float => (float) $item['weight'], $available));
        if ($weightSum <= 0.0) {
            return [
                'score' => null,
                'breakdown' => [],
                'missing_metrics' => $missing,
            ];
        }

        $breakdown = [];
        $total = 0.0;

        foreach ($available as $metric => $item) {
            $normalizedWeight = ((float) $item['weight']) / $weightSum;
            $contribution = ((float) $item['value']) * $normalizedWeight;
            $total += $contribution;

            $breakdown[$metric] = [
                'value' => round((float) $item['value'], 2),
                'weight' => round((float) $item['weight'], 4),
                'normalized_weight' => round($normalizedWeight, 4),
                'contribution' => round($contribution, 2),
            ];
        }

        return [
            'score' => round(max(0.0, min(100.0, $total)), 2),
            'breakdown' => $breakdown,
            'missing_metrics' => $missing,
        ];
    }

    /**
     * @param array<string,mixed> $winner
     * @return array<string,mixed>
     */
    private function winnerPayload(array $winner): array
    {
        return [
            'variant_id' => (string) $winner['variant_id'],
            'draft_id' => $winner['draft_id'],
            'provider' => (string) $winner['provider'],
            'model' => (string) $winner['model'],
            'display_name' => $winner['display_name'],
            'total_weighted_score' => round((float) ($winner['weighted_score'] ?? 0), 2),
            'weighted_breakdown' => (array) ($winner['weighted_breakdown'] ?? []),
            'missing_weighted_metrics' => (array) ($winner['missing_weighted_metrics'] ?? []),
        ];
    }

    /**
     * @param array<string,mixed> $winner
     * @param array<string,mixed>|null $runnerUp
     */
    private function winnerExplanation(array $winner, ?array $runnerUp = null): string
    {
        $winnerScore = (float) ($winner['weighted_score'] ?? 0);
        $parts = collect((array) ($winner['weighted_breakdown'] ?? []))
            ->sortByDesc(fn (array $part): float => (float) ($part['contribution'] ?? 0))
            ->take(3)
            ->map(fn (array $part, string $metric): string => sprintf(
                '%s %.1f',
                str_replace('_', ' ', $metric),
                (float) ($part['value'] ?? 0)
            ))
            ->values()
            ->all();

        $reason = 'Highest weighted score';
        if ($parts !== []) {
            $reason .= ' driven by ' . implode(', ', $parts);
        }

        if ($runnerUp !== null && is_numeric($runnerUp['weighted_score'] ?? null)) {
            $margin = round($winnerScore - (float) $runnerUp['weighted_score'], 2);
            $reason .= sprintf(' (margin %+0.2f vs next variant).', $margin);
        } else {
            $reason .= '.';
        }

        return $reason;
    }

    /**
     * @param \Illuminate\Support\Collection<int,array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function bestForSeo($rows): ?array
    {
        $winner = $rows
            ->map(function (array $row): array {
                $seo = is_numeric(data_get($row, 'metrics.seo_score')) ? (float) data_get($row, 'metrics.seo_score') : null;
                $aiSeo = is_numeric(data_get($row, 'metrics.ai_seo_score')) ? (float) data_get($row, 'metrics.ai_seo_score') : null;

                $score = null;
                if ($seo !== null && $aiSeo !== null) {
                    $score = round(($seo * 0.6) + ($aiSeo * 0.4), 2);
                } elseif ($seo !== null) {
                    $score = round($seo, 2);
                } elseif ($aiSeo !== null) {
                    $score = round($aiSeo, 2);
                }

                $row['seo_fit_score'] = $score;

                return $row;
            })
            ->filter(fn (array $row): bool => is_numeric($row['seo_fit_score'] ?? null))
            ->sortByDesc(fn (array $row): float => (float) ($row['seo_fit_score'] ?? 0))
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
            'score' => round((float) $winner['seo_fit_score'], 2),
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int,array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function bestByMetric($rows, string $metric): ?array
    {
        $winner = $rows
            ->filter(fn (array $row): bool => is_numeric(data_get($row, 'metrics.' . $metric)))
            ->sortByDesc(fn (array $row): float => (float) data_get($row, 'metrics.' . $metric, 0))
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
            'score' => round((float) data_get($winner, 'metrics.' . $metric, 0), 2),
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int,array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function bestConcise($rows): ?array
    {
        $winner = $rows
            ->filter(fn (array $row): bool => is_numeric(data_get($row, 'metrics.word_count')) && (float) data_get($row, 'metrics.word_count') > 0)
            ->sortBy(fn (array $row): float => (float) data_get($row, 'metrics.word_count', 0))
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
            'word_count' => (int) round((float) data_get($winner, 'metrics.word_count', 0)),
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int,array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function bestConversion($rows): ?array
    {
        $winner = $rows
            ->map(function (array $row): array {
                $conversion = is_numeric(data_get($row, 'metrics.conversion_focus')) ? (float) data_get($row, 'metrics.conversion_focus') : null;
                $cta = is_numeric(data_get($row, 'metrics.cta_strength')) ? (float) data_get($row, 'metrics.cta_strength') : null;

                if ($conversion !== null && $cta !== null) {
                    $row['conversion_fit_score'] = round(($conversion * 0.7) + ($cta * 0.3), 2);
                } elseif ($conversion !== null) {
                    $row['conversion_fit_score'] = round($conversion, 2);
                } elseif ($cta !== null) {
                    $row['conversion_fit_score'] = round($cta, 2);
                } else {
                    $row['conversion_fit_score'] = null;
                }

                return $row;
            })
            ->filter(fn (array $row): bool => is_numeric($row['conversion_fit_score'] ?? null))
            ->sortByDesc(fn (array $row): float => (float) ($row['conversion_fit_score'] ?? 0))
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
            'score' => round((float) $winner['conversion_fit_score'], 2),
        ];
    }
}
