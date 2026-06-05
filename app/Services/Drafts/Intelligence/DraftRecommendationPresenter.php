<?php

namespace App\Services\Drafts\Intelligence;

use App\Enums\DraftImprovementAction;
use App\Models\DraftAnalysis;
use App\Models\DraftImprovementResult;

class DraftRecommendationPresenter
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private function normalizeDeltaPayload(array $payload): ?array
    {
        $before = $this->normalizeNullableInt($payload['score_before'] ?? null);
        $after = $this->normalizeNullableInt($payload['score_after'] ?? null);

        if ($before === null && $after === null) {
            return null;
        }

        $deltaValue = $before !== null && $after !== null
            ? $after - $before
            : null;

        return [
            'score_before' => $before,
            'score_after' => $after,
            'delta_value' => $deltaValue,
            'delta' => $deltaValue,
            'explanation' => $payload['explanation'] ?? null,
            'confidence_level' => $payload['confidence_level'] ?? null,
            'is_comparable' => $before !== null && $after !== null,
            'display_transition' => $this->formatScoreTransition($before, $after, $deltaValue),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function topPrioritiesForAnalysis(?DraftAnalysis $analysis, int $limit = 3): array
    {
        if (! $analysis) {
            return [];
        }

        return $analysis->recommendations()
            ->orderBy('sort_order')
            ->limit($limit)
            ->get()
            ->map(fn ($recommendation): array => [
                'metric_key' => $recommendation->metric_key,
                'title' => $recommendation->title,
                'summary' => $recommendation->summary,
                'why_it_matters' => $recommendation->why_it_matters,
                'suggested_action' => $recommendation->suggested_action,
                'impact_level' => $recommendation->impact_level,
                'effort_level' => $recommendation->effort_level,
                'confidence_level' => $recommendation->confidence_level,
                'sort_order' => $recommendation->sort_order,
            ])
            ->all();
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function deltaMapForImprovement(?DraftImprovementResult $improvementResult): array
    {
        if (! $improvementResult) {
            return [];
        }

        return $improvementResult->deltas()
            ->get()
            ->mapWithKeys(function ($delta): array {
                $payload = $this->normalizeDeltaPayload([
                    'score_before' => $delta->getRawOriginal('score_before'),
                    'score_after' => $delta->getRawOriginal('score_after'),
                    'delta_value' => $delta->getRawOriginal('delta'),
                    'explanation' => $delta->explanation,
                    'confidence_level' => $delta->confidence_level,
                ]);

                if ($payload === null) {
                    return [];
                }

                return [$delta->metric_key => $payload];
            })
            ->all();
    }

    /**
     * @param iterable<int,\App\Models\DraftImprovementResult> $results
     * @return array<int,array<string,mixed>>
     */
    public function recentImprovements(iterable $results): array
    {
        return collect($results)
            ->map(function (DraftImprovementResult $result): array {
                $deltaSnapshot = collect((array) ($result->score_delta_snapshot ?? []))
                    ->mapWithKeys(function (mixed $delta, string $metricKey): array {
                        if (! is_array($delta)) {
                            return [];
                        }

                        $payload = $this->normalizeDeltaPayload($delta);

                        if ($payload === null) {
                            return [];
                        }

                        return [$metricKey => $payload];
                    })
                    ->all();

                $status = (string) $result->status;
                $displayedAt = $result->completed_at
                    ?: $result->failed_at
                    ?: $result->started_at
                    ?: $result->created_at;

                return [
                    'id' => (string) $result->id,
                    'action' => $result->action,
                    'label' => DraftImprovementAction::fromInput((string) $result->action)?->label()
                        ?? ucfirst(str_replace('_', ' ', (string) $result->action)),
                    'status' => $status,
                    'status_label' => $this->statusLabel($status),
                    'requested_at' => $result->created_at?->toIso8601String(),
                    'started_at' => $result->started_at?->toIso8601String(),
                    'completed_at' => $result->completed_at?->toIso8601String(),
                    'failed_at' => $result->failed_at?->toIso8601String(),
                    'displayed_at' => $displayedAt?->toIso8601String(),
                    'summary' => $result->summary,
                    'change_notes' => (array) ($result->change_notes ?? []),
                    'score_delta_snapshot' => $deltaSnapshot,
                    'fully_applied' => (bool) $result->fully_applied,
                    'prompt_version' => $result->prompt_version,
                ];
            })
            ->all();
    }

    private function formatScoreTransition(?int $before, ?int $after, ?int $deltaValue): string
    {
        $beforeDisplay = $before === null ? 'n/a' : (string) $before;
        $afterDisplay = $after === null ? 'n/a' : (string) $after;

        if ($deltaValue === null) {
            return sprintf('%s → %s', $beforeDisplay, $afterDisplay);
        }

        $deltaDisplay = $deltaValue > 0 ? '+' . $deltaValue : (string) $deltaValue;

        return sprintf('%s → %s (%s)', $beforeDisplay, $afterDisplay, $deltaDisplay);
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'queued' => 'Queued',
            'processing' => 'Processing',
            'completed' => 'Completed',
            'failed' => 'Failed',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}
